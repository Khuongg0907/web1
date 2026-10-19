import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { deflateSync } from 'node:zlib';

const root = new URL('../', import.meta.url);
const source = fs.readFileSync(new URL('index.html', root), 'utf8');
new vm.Script(source.match(/<script>([\s\S]*?)<\/script>/)[1]);
console.log('Inline JavaScript syntax OK');
if (process.argv.includes('--syntax-only')) process.exit(0);
const base = 'http://127.0.0.1:18089';
const token = 'qa-only-catalog-test-secret-2026';
const artifactDir = new URL('artifacts/', import.meta.url);
fs.mkdirSync(artifactDir, { recursive: true });

// A real PNG over 64 KiB verifies the reverse proxy's upload limit as well.
function crc32(data) {
  let crc = -1;
  for (const b of data) { crc ^= b; for (let j = 0; j < 8; j++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1)); }
  return (crc ^ -1) >>> 0;
}
function chunk(type, data) {
  const name = Buffer.from(type), length = Buffer.alloc(4), crc = Buffer.alloc(4);
  length.writeUInt32BE(data.length); crc.writeUInt32BE(crc32(Buffer.concat([name, data])));
  return Buffer.concat([length, name, data, crc]);
}
const header = Buffer.alloc(13); header.writeUInt32BE(180, 0); header.writeUInt32BE(180, 4); header[8] = 8; header[9] = 2;
const pixels = Buffer.alloc((180 * 3 + 1) * 180);
let seed = 42;
for (let y = 0; y < 180; y++) for (let x = 1; x <= 540; x++) { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; pixels[y * 541 + x] = seed >>> 24; }
const png = Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', header), chunk('IDAT', deflateSync(pixels)), chunk('IEND', Buffer.alloc(0))]);
assert(png.length > 65536);
const fixturePath = new URL('product.png', artifactDir); fs.writeFileSync(fixturePath, png);

class Client {
  cookies = new Map(); csrf = '';
  async call(route, body, expected = 200, csrf = this.csrf) {
    const multipart = body instanceof FormData;
    const response = await fetch(base + '/api/' + route, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '), ...(body === undefined ? {} : { 'X-CSRF-Token': csrf, ...(multipart ? {} : { 'Content-Type': 'application/json' }) }) },
      body: body === undefined ? undefined : multipart ? body : JSON.stringify(body),
    });
    for (const cookie of response.headers.getSetCookie()) { const pair = cookie.split(';')[0]; const index = pair.indexOf('='); this.cookies.set(pair.slice(0, index), pair.slice(index + 1)); }
    const text = await response.text();
    assert.equal(response.status, expected, `${route}: ${text}`);
    const result = JSON.parse(text); if (result.csrf) this.csrf = result.csrf;
    return result;
  }
}
function upload(product, bytes = png, type = 'image/png') {
  const form = new FormData(); form.set('product', JSON.stringify(product));
  if (bytes) form.set('image', new Blob([bytes], { type }), 'product.png');
  return form;
}
const product = { id: 0, name: 'Áo kiểm thử upload', category: 'Áo thun', audience: 'unisex', price: 125000, old_price: 150000, stock: 7, tag: 'MỚI', colors: ['Trắng'], sizes: ['M'], description: 'Sản phẩm kiểm thử với ảnh tải lên.' };

if (!process.argv.includes('--browser-only')) {
  const admin = new Client(), guest = new Client(), buyer = new Client();
  await Promise.all([admin.call('bootstrap'), guest.call('bootstrap'), buyer.call('bootstrap')]);
  await guest.call('admin/products', undefined, 403);
  await guest.call('admin/products/save', upload(product), 403);
  await admin.call('admin/session', { token });
  await admin.call('admin/products/save', upload(product), 403, 'invalid-csrf');
  await admin.call('admin/products/save', upload(product, null), 400);
  await admin.call('admin/products/save', upload(product, Buffer.from('<?php echo "bad";'), 'image/png'), 400);
  await admin.call('admin/products/save', upload(product, Buffer.alloc(5 * 1024 * 1024 + 1)), 400);
  await admin.call('admin/products/save', upload({ ...product, price: -1 }), 400);
  await admin.call('admin/products/save', upload({ ...product, old_price: 1000 }), 400);
  await admin.call('admin/products/save', upload({ ...product, sizes: [] }), 400);
  const { product: created } = await admin.call('admin/products/save', upload(product), 201);
  assert.match(created.image, /^\/api\/media\/[a-f0-9]{32}\.png$/);
  const media = await fetch(base + created.image);
  assert.equal(media.headers.get('content-type'), 'image/png');
  assert.equal(media.headers.getSetCookie().length, 0, 'Image requests must not create sessions');
  assert.deepEqual(Buffer.from(await media.arrayBuffer()), png);
  const edited = { ...product, id: created.id, name: 'Áo đã chỉnh sửa', stock: 9 };
  const { product: updated } = await admin.call('admin/products/save', upload(edited, null));
  assert.equal(updated.image, created.image); assert.equal(updated.stock, 9);
  assert((await guest.call('bootstrap')).products.some(p => p.id === created.id && p.name === edited.name));
  await buyer.call('signup', { name: 'Khách kiểm thử', email: `qa-${Date.now()}@example.com`, password: 'CatalogTest123!' });
  const items = [{ id: created.id, size: 'M', color: 'Trắng', qty: 2 }];
  const checkout = { address: { name: 'Khách kiểm thử', phone: '0901234567', street: '123 Đường thử nghiệm', city: 'TP.HCM', region: 'hcm' }, items, request_key: crypto.randomUUID() };
  const order = await buyer.call('checkout', checkout, 201);
  await admin.call('admin/products/visibility', { id: created.id, active: false });
  assert(!(await guest.call('bootstrap')).products.some(p => p.id === created.id));
  await buyer.call('cart', { items }, 400);
  await buyer.call('checkout', { ...checkout, request_key: crypto.randomUUID() }, 400);
  const saved = (await buyer.call('orders')).orders.find(o => o.id === order.id);
  assert.equal(saved.items[0].name, edited.name); assert.equal(saved.items[0].image, created.image);
  await buyer.call('cancel', { id: order.id });
  const hidden = (await admin.call('admin/products')).products.find(p => p.id === created.id);
  assert.equal(hidden.stock, 9); assert.equal(hidden.active, 0);
  await admin.call('admin/products/visibility', { id: created.id, active: true });
  assert((await guest.call('bootstrap')).products.some(p => p.id === created.id));
  await admin.call('admin/logout', {}); await admin.call('admin/products', undefined, 403);
  console.log('API OK: permissions, CSRF, invalid files, limits, create/edit, media, checkout, hide/restore, historical orders, cancellation');
}

const { chromium } = await import('playwright');
const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.route('https://**/*', route => route.abort());
  await page.goto(base); await page.locator('.product-card').first().waitFor();
  await page.locator('#admin-btn').click(); await page.locator('#admin-form input[name=token]').fill(token);
  await page.locator('#admin-form button').click(); await page.locator('#admin-add-product').waitFor();
  await page.locator('[data-admin-tab=orders]').click(); await page.locator('#refresh-admin').waitFor();
  await page.locator('[data-admin-tab=products]').click(); await page.locator('#admin-add-product').click();
  const form = page.locator('#admin-product-form');
  const browserName = `Áo giao diện ${Date.now()}`;
  await form.locator('[name=name]').fill(browserName); await form.locator('[name=price]').fill('215000');
  await form.locator('[name=description]').fill('Kiểm tra thêm sản phẩm bằng giao diện quản trị.');
  await form.locator('[name=sizes]').fill('One size');
  await form.locator('[name=image]').setInputFiles(fixturePath.pathname.slice(1));
  await page.waitForFunction(() => document.querySelector('#product-image-preview')?.naturalWidth > 0);
  await page.screenshot({ path: new URL('admin-upload-desktop.png', artifactDir).pathname.slice(1), fullPage: true });
  await form.locator('[type=submit]').click();
  const row = page.locator('.admin-product-row').filter({ hasText: browserName }); await row.waitFor();
  await row.locator('[data-edit-product]').click();
  await page.locator('#admin-product-form [name=stock]').fill('12');
  await page.locator('#admin-product-form [type=submit]').click(); await row.waitFor();
  assert.match(await row.innerText(), /Tồn: 12/);
  await page.locator('#modal-close').click();
  await page.getByRole('button', { name: browserName, exact: true }).click();
  assert.equal(await page.locator('#add-cart-form input[name=size]:checked').inputValue(), 'One size');
  await page.locator('#modal-close').click(); await page.locator('#admin-btn').click(); await row.waitFor();
  await row.locator('[data-product-visibility]').click(); await page.locator('[data-confirm-visibility]').click();
  await row.waitFor(); assert.match(await row.innerText(), /Đã gỡ khỏi cửa hàng/);
  await page.locator('#modal-close').click();
  assert.equal(await page.getByRole('button', { name: browserName, exact: true }).count(), 0);
  await page.locator('#admin-btn').click(); await row.waitFor();
  await row.locator('[data-product-visibility]').click(); await row.waitFor();
  await row.locator('.status-tag').filter({ hasText: 'Đang bày bán' }).waitFor();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: new URL('admin-products-mobile.png', artifactDir).pathname.slice(1), fullPage: true });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  await page.locator('#admin-add-product').click();
  assert(await page.evaluate(() => document.querySelector('#modal').scrollWidth <= document.querySelector('#modal').clientWidth));
  await page.locator('[data-admin-tab=products]').click(); await page.locator('#admin-logout').click();
  await page.locator('#admin-btn').click(); await page.locator('#admin-form').waitFor();
  assert.deepEqual(errors, []);
  console.log('Browser OK: admin login, tabs, upload preview/create, edit, single size, hide confirmation, restore, mobile layout, logout');
} finally { await browser.close(); }
