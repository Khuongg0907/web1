<?php
/** MONO — PHP 8 + PDO SQLite. Run: php -S 127.0.0.1:8000 database.php
 * Three source files only. Runtime database and sessions live outside the web root.
 * Production: HTTPS, PHP-FPM/Apache, and a private persistent MONO_DATA_DIR.
 * Optional shop management: set MONO_ADMIN_TOKEN to a secret of at least 24 characters
 * before starting PHP; use "Quan tri cua hang" in the footer to update deliveries/returns.
 */
declare(strict_types=1);
$dataDir = getenv('MONO_DATA_DIR') ?: sys_get_temp_dir() . '/mono-store-' . substr(hash('sha256', __DIR__), 0, 12);
if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Serve only generated image filenames, without creating a login session.
if (preg_match('~^/api/media/([a-f0-9]{32}\.(jpg|png|webp))$~D', $path, $match)) {
    $file = $dataDir.'/uploads/'.$match[1];
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: '.['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$match[2]]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($file); exit;
}
if (!str_starts_with($path, '/api/')) {
    if ($path === '/' || $path === '/index.html') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__.'/index.html'); exit; }
    if ($path === '/style.css') { header('Content-Type: text/css; charset=utf-8'); readfile(__DIR__.'/style.css'); exit; }
    http_response_code(404); exit('Not found');
}
ini_set('session.use_strict_mode', '1');
session_save_path($dataDir);
session_name('mono_session');
session_set_cookie_params(['httponly'=>true, 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite'=>'Lax', 'path'=>'/']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function numericFields($data) {
    if(!is_array($data)) return $data;
    foreach($data as $key=>&$value) {
        if(is_array($value)) $value=numericFields($value);
        elseif(is_string($value) && in_array($key,['id','user_id','product_id','price','old_price','stock','qty','subtotal','shipping','total','comments'],true) && ctype_digit($value)) $value=(int)$value;
    } unset($value); return $data;
}
function reply($data, int $status=200): void { http_response_code($status); echo json_encode(numericFields($data), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail(string $message, int $status=400): void { reply(['error'=>$message],$status); }
try {
    $db = new PDO('sqlite:'.$dataDir.'/shop.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, category TEXT NOT NULL, audience TEXT NOT NULL, price INTEGER NOT NULL, old_price INTEGER, image TEXT NOT NULL, tag TEXT, colors TEXT NOT NULL, sizes TEXT NOT NULL, stock INTEGER NOT NULL DEFAULT 30, description TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS carts (user_id INTEGER PRIMARY KEY REFERENCES users(id), items TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), items TEXT NOT NULL, subtotal INTEGER NOT NULL, shipping INTEGER NOT NULL, total INTEGER NOT NULL, address TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'confirmed', created_at TEXT DEFAULT CURRENT_TIMESTAMP, delivered_at TEXT, request_key TEXT NOT NULL, UNIQUE(user_id, request_key));
    CREATE TABLE IF NOT EXISTS returns (id INTEGER PRIMARY KEY, order_id TEXT UNIQUE NOT NULL REFERENCES orders(id), user_id INTEGER NOT NULL REFERENCES users(id), reason TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'requested', created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS listings (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), seller TEXT NOT NULL, product_id INTEGER REFERENCES products(id), title TEXT NOT NULL, price INTEGER NOT NULL, condition TEXT NOT NULL, description TEXT NOT NULL, image TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'available', created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY, listing_id INTEGER NOT NULL REFERENCES listings(id), user_id INTEGER NOT NULL REFERENCES users(id), body TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS login_attempts (key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, reset_at INTEGER NOT NULL);");
    $db->exec('BEGIN IMMEDIATE');
    $columns = array_column($db->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    if (!in_array('active', $columns, true)) $db->exec('ALTER TABLE products ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    if (!(int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
        $products = [
            [1,'Áo thun Essential','Áo thun','unisex',249000,null,'photo-1521572163474-6864f9cf17ab','BESTSELLER',['Trắng','Đen','Xám'],['S','M','L','XL'],'Chất cotton 100% thoáng mát, phom regular vừa vặn. Một thiết kế đơn giản để mặc mỗi ngày.'],
            [2,'Sơ mi Oxford','Áo sơ mi','nam',459000,null,'photo-1598033129183-c4f50c736f10','NEW IN',['Trắng','Đen'],['S','M','L','XL'],'Vải Oxford mềm, bền và giữ phom. Cổ cài nút, đường may chỉn chu, dễ phối cùng quần âu hoặc denim.'],
            [3,'Quần jeans Straight','Quần jeans','unisex',599000,699000,'photo-1542272604-787c3835535d','−14%',['Xanh denim','Đen'],['S','M','L','XL'],'Denim dày vừa với phom ống đứng thoải mái. Thiết kế 5 túi, dễ kết hợp cùng các món đồ quen thuộc.'],
            [4,'Áo khoác Everyday','Áo khoác','unisex',789000,null,'photo-1544022613-e87ca75a784a','NEW IN',['Đen','Be'],['S','M','L','XL'],'Áo khoác nhẹ với lớp lót thoáng và túi tiện dụng. Phom rộng, phù hợp cho những ngày chuyển mùa.'],
            [5,'Quần âu Relaxed','Quần âu','nam',549000,null,'photo-1473966968600-fa801b869a1a',null,['Đen','Be'],['S','M','L','XL'],'Ống suông hiện đại, chất vải ít nhăn. Cạp vừa và phom thoải mái cho cả công việc lẫn cuối tuần.'],
            [6,'Sơ mi Linen','Áo sơ mi','nữ',489000,null,'photo-1551803091-e20673f15770',null,['Trắng','Be'],['S','M','L'],'Linen pha mềm nhẹ, tạo cảm giác thoáng mát. Dáng suông tự nhiên, mặc riêng hoặc khoác ngoài.'],
            [7,'Áo thun Relaxed','Áo thun','nữ',289000,null,'photo-1503341504253-dff4815485f1','NEW IN',['Đen','Trắng'],['S','M','L'],'Áo cotton với tay lỡ và dáng relaxed. Tối giản chi tiết, thoải mái trong mọi hoạt động thường ngày.'],
            [8,'Quần short Weekend','Quần short','unisex',329000,null,'photo-1591195853828-11db59a44f6b',null,['Be','Đen'],['S','M','L','XL'],'Quần short kaki mềm với túi hai bên và độ dài vừa phải. Một lựa chọn gọn nhẹ cho ngày nghỉ.']
        ];
        $insert=$db->prepare('INSERT OR IGNORE INTO products (id,name,category,audience,price,old_price,image,tag,colors,sizes,description) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach($products as $p) { $p[8]=json_encode($p[8],JSON_UNESCAPED_UNICODE); $p[9]=json_encode($p[9]); $insert->execute($p); }
        $insert=$db->prepare('INSERT INTO listings (seller,product_id,title,price,condition,description,image) VALUES (?,?,?,?,?,?,?)');
        foreach ([['Cộng đồng MONO · mẫu',1,'Essential tee tìm chủ mới',120000,'Rất tốt','Bài mẫu: áo mặc vài lần, vẫn giữ phom. Hãy đăng tin của bạn để bắt đầu trao đổi.','photo-1521572163474-6864f9cf17ab'],['Cộng đồng MONO · mẫu',3,'Straight jeans, một vòng đời mới',290000,'Tốt','Bài mẫu: denim ống đứng, được giữ gìn cẩn thận. Nội dung minh họa cho cộng đồng secondhand.','photo-1542272604-787c3835535d'],['Cộng đồng MONO · mẫu',4,'Everyday jacket còn như mới',450000,'Như mới','Bài mẫu: áo khoác nhẹ dễ phối đồ. Các bài có nhãn mẫu không phải tin rao bán thật.','photo-1544022613-e87ca75a784a']] as $l) $insert->execute($l);
    }
    $db->exec('COMMIT');
    function query(string $sql, array $args=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($args); return $s; }
    function user(): array { $u=query('SELECT id,name,email,address FROM users WHERE id=?',[$_SESSION['uid']??0])->fetch(); if (!$u) fail('Vui lòng đăng nhập để tiếp tục.',401); if ($u['address']) $u['address']=json_decode($u['address'],true); return $u; }
    function field(array $b,string $key,int $max=500): string { $v=$b[$key]??''; if(!is_string($v) || !trim($v) || mb_strlen(trim($v))>$max) fail('Thông tin '.$key.' không hợp lệ.'); return trim($v); }
    function product(array $p): array { $p['colors']=json_decode($p['colors'],true); $p['sizes']=json_decode($p['sizes'],true); return $p; }
    function normalizeCart($items): array {
        if(!is_array($items)||count($items)>50) fail('Giỏ hàng không hợp lệ.'); $result=[];
        foreach($items as $i) {
            if(!is_array($i)) fail('Giỏ hàng không hợp lệ.');
            $p=query('SELECT * FROM products WHERE id=? AND active=1',[(int)($i['id']??0)])->fetch();
            $qty=$i['qty']??0; if(!$p || !is_int($qty) || $qty<1 || $qty>10) fail('Số lượng mỗi lựa chọn phải từ 1 đến 10.');
            $p=product($p); if(!in_array($i['size']??'', $p['sizes'],true)||!in_array($i['color']??'', $p['colors'],true)) fail('Kích cỡ hoặc màu sắc không hợp lệ.');
            $key=$p['id'].'|'.$i['size'].'|'.$i['color'];
            if(isset($result[$key])) { $result[$key]['qty']+=$qty; if($result[$key]['qty']>10) fail('Tối đa 10 sản phẩm cho một lựa chọn.'); }
            else $result[$key]=['id'=>(int)$p['id'],'name'=>$p['name'],'price'=>(int)$p['price'],'image'=>$p['image'],'size'=>$i['size'],'color'=>$i['color'],'qty'=>$qty];
        } return array_values($result);
    }
    $_SESSION['csrf']=$_SESSION['csrf']??bin2hex(random_bytes(24));
    $method=$_SERVER['REQUEST_METHOD'];
    $route=substr($path,5);
    $body=[];
    if($method==='POST') {
        if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('Phiên làm việc đã thay đổi. Vui lòng tải lại trang.',403);
        if ($route==='admin/products/save') {
            if(!is_string($_POST['product']??null)||strlen($_POST['product'])>50000) fail('Thông tin sản phẩm quá lớn hoặc ảnh vượt giới hạn.',413);
            $body=json_decode($_POST['product']??'',true);
            if(!is_array($body)) fail('Thông tin sản phẩm không hợp lệ hoặc ảnh quá lớn.');
        } else {
            $raw=file_get_contents('php://input'); if(strlen($raw)>50000) fail('Dữ liệu quá lớn.',413);
            $body=json_decode($raw,true); if(!is_array($body)) fail('Dữ liệu không hợp lệ.');
        }
    }
    if($route==='admin/session' && $method==='POST') {
        $secret=getenv('MONO_ADMIN_TOKEN')?:'';
        if(strlen($secret)<24) fail('Chủ cửa hàng cần cấu hình MONO_ADMIN_TOKEN (tối thiểu 24 ký tự) trước khi khởi động PHP.',503);
        $key='admin:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'local');
        $attempt=query('SELECT * FROM login_attempts WHERE key=?',[$key])->fetch();
        if($attempt && $attempt['reset_at']>time() && $attempt['attempts']>=10) fail('Thử quá nhiều lần. Vui lòng thử lại sau 15 phút.',429);
        query('INSERT INTO login_attempts (key,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN reset_at<? THEN 1 ELSE attempts+1 END, reset_at=CASE WHEN reset_at<? THEN ? ELSE reset_at END',[$key,time()+900,time(),time(),time()+900]);
        if(!is_string($body['token']??null)||!hash_equals($secret,$body['token'])) fail('Khóa quản trị không đúng.',403);
        query('DELETE FROM login_attempts WHERE key=?',[$key]); session_regenerate_id(true); $_SESSION['admin']=true; reply(['ok'=>true]);
    }
    if(str_starts_with($route,'admin/')) {
        if(empty($_SESSION['admin'])) fail('Vui lòng xác thực quyền quản trị.',403);
        if($route==='admin/logout' && $method==='POST') { unset($_SESSION['admin']); reply(['ok'=>true]); }
        if($route==='admin/products' && $method==='GET') reply(['products'=>array_map('product',query('SELECT * FROM products ORDER BY id DESC')->fetchAll())]);
        if($route==='admin/products/visibility' && $method==='POST') {
            if(!is_int($body['id']??null)||!is_bool($body['active']??null)) fail('Thông tin sản phẩm không hợp lệ.');
            if(!query('SELECT id FROM products WHERE id=?',[$body['id']])->fetch()) fail('Không tìm thấy sản phẩm.',404);
            query('UPDATE products SET active=? WHERE id=?',[(int)$body['active'],$body['id']]);
            reply(['ok'=>true]);
        }
        if($route==='admin/products/save' && $method==='POST') {
            $id=$body['id']??0; if(!is_int($id)||$id<0) fail('Mã sản phẩm không hợp lệ.');
            $existing=$id?query('SELECT * FROM products WHERE id=?',[$id])->fetch():null;
            if($id&&!$existing) fail('Không tìm thấy sản phẩm.',404);
            $name=field($body,'name',100); $description=field($body,'description',2000);
            $category=field($body,'category',30); $audience=field($body,'audience',10);
            if(!in_array($category,['Áo thun','Áo sơ mi','Áo khoác','Quần jeans','Quần âu','Quần short'],true)||!in_array($audience,['nam','nữ','unisex'],true)) fail('Danh mục hoặc đối tượng không hợp lệ.');
            $price=$body['price']??null; $oldPrice=$body['old_price']??null; $stock=$body['stock']??null;
            if(!is_int($price)||$price<1000||$price>100000000) fail('Giá bán từ 1.000đ đến 100.000.000đ.');
            if($oldPrice!==null&&(!is_int($oldPrice)||$oldPrice<=$price||$oldPrice>100000000)) fail('Giá gốc phải lớn hơn giá bán và không quá 100.000.000đ.');
            if(!is_int($stock)||$stock<0||$stock>1000000) fail('Tồn kho phải là số nguyên từ 0 đến 1.000.000.');
            $tag=$body['tag']??''; if(!is_string($tag)||mb_strlen($tag)>30) fail('Nhãn sản phẩm tối đa 30 ký tự.');
            $variants=[];
            foreach(['colors','sizes'] as $key) {
                $values=$body[$key]??null;
                if(!is_array($values)||!array_is_list($values)||count($values)<1||count($values)>20) fail('Cần từ 1 đến 20 màu sắc / kích cỡ.');
                foreach($values as &$value) { if(!is_string($value)||!trim($value)||mb_strlen(trim($value))>30) fail('Màu sắc / kích cỡ tối đa 30 ký tự.'); $value=trim($value); } unset($value);
                $variants[$key]=json_encode(array_values(array_unique($values)),JSON_UNESCAPED_UNICODE);
            }
            $image=$existing['image']??null; $newFile=null;
            $upload=$_FILES['image']??null;
            if($upload&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                if($upload['error']!==UPLOAD_ERR_OK) fail('Không tải được ảnh. Chọn ảnh JPG, PNG hoặc WebP tối đa 5 MB.');
                if($upload['size']>5*1024*1024||!is_uploaded_file($upload['tmp_name'])) fail('Ảnh tối đa 5 MB.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
                $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                $info=@getimagesize($upload['tmp_name']);
                if(!isset($extensions[$mime])||!$info||($info['mime']??'')!==$mime||$info[0]>6000||$info[1]>6000) fail('Chọn ảnh JPG, PNG hoặc WebP hợp lệ, tối đa 6000 × 6000 pixel.');
                $uploadDir=$dataDir.'/uploads';
                if(!is_dir($uploadDir)&&!mkdir($uploadDir,0700,true)) throw new RuntimeException('Cannot create upload directory');
                $filename=bin2hex(random_bytes(16)).'.'.$extensions[$mime]; $newFile=$uploadDir.'/'.$filename;
                if(!move_uploaded_file($upload['tmp_name'],$newFile)) throw new RuntimeException('Cannot store image');
                chmod($newFile,0600); $image='/api/media/'.$filename;
            }
            if(!$image) fail('Vui lòng chọn ảnh cho sản phẩm mới.');
            $params=[$name,$category,$audience,$price,$oldPrice,$image,trim($tag),$variants['colors'],$variants['sizes'],$stock,$description];
            try {
                if($id) query('UPDATE products SET name=?,category=?,audience=?,price=?,old_price=?,image=?,tag=?,colors=?,sizes=?,stock=?,description=? WHERE id=?',[...$params,$id]);
                else { query('INSERT INTO products (name,category,audience,price,old_price,image,tag,colors,sizes,stock,description) VALUES (?,?,?,?,?,?,?,?,?,?,?)',$params); $id=(int)$db->lastInsertId(); }
            } catch(Throwable $e) { if($newFile) unlink($newFile); throw $e; }
            reply(['product'=>product(query('SELECT * FROM products WHERE id=?',[$id])->fetch())],$existing?200:201);
        }
        if($route==='admin/orders' && $method==='GET') {
            $all=query('SELECT o.*,r.status AS return_status,r.reason AS return_reason,u.email FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN returns r ON r.order_id=o.id ORDER BY o.created_at DESC,o.rowid DESC')->fetchAll();
            foreach($all as &$o) { $o['items']=json_decode($o['items'],true); $o['address']=json_decode($o['address'],true); } unset($o); reply(['orders'=>$all]);
        }
        if($route==='admin/status' && $method==='POST') {
            $o=query('SELECT * FROM orders WHERE id=?',[$body['id']??''])->fetch(); if(!$o) fail('Không tìm thấy đơn hàng.',404);
            $next=['confirmed'=>'processing','processing'=>'shipping','shipping'=>'delivered'];
            if(($next[$o['status']]??null)!==($body['status']??'')) fail('Chuyển trạng thái không hợp lệ.',409);
            $s=query('UPDATE orders SET status=?,delivered_at=CASE WHEN ?=\'delivered\' THEN CURRENT_TIMESTAMP ELSE delivered_at END WHERE id=? AND status=?',[$body['status'],$body['status'],$o['id'],$o['status']]);
            if(!$s->rowCount()) fail('Đơn hàng vừa thay đổi. Vui lòng tải lại.',409); reply(['ok'=>true]);
        }
        if($route==='admin/return' && $method==='POST') {
            $status=$body['status']??''; if(!in_array($status,['accepted','rejected','completed'],true)) fail('Trạng thái không hợp lệ.');
            $previous=$status==='completed'?'accepted':'requested';
            $s=query('UPDATE returns SET status=? WHERE order_id=? AND status=?',[$status,$body['id']??'',$previous]);
            if(!$s->rowCount()) fail('Yêu cầu vừa thay đổi hoặc không tồn tại.',409); reply(['ok'=>true]);
        }
        fail('Không tìm thấy chức năng quản trị.',404);
    }
    if($method==='GET' && $route==='bootstrap') {
        $u=null; if(isset($_SESSION['uid'])) $u=user();
        $cart=$u?query('SELECT items FROM carts WHERE user_id=?',[$u['id']])->fetchColumn():false;
        reply(['csrf'=>$_SESSION['csrf'],'user'=>$u,'products'=>array_map('product',query('SELECT * FROM products WHERE active=1')->fetchAll()),'cart'=>$cart?json_decode($cart,true):[]]);
    }
    if($method==='POST' && ($route==='signup'||$route==='signin')) {
        $email=strtolower(field($body,'email',254)); $password=$body['password']??'';
        if($route==='signup') {
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||strlen($password)<8||strlen($password)>200) fail('Email hợp lệ và mật khẩu từ 8 đến 200 ký tự là bắt buộc.');
        } elseif(!is_string($password)||$password===''||strlen($password)>200) fail('Email hoặc mật khẩu không đúng.',401);
        $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'local').'|'.$email);
        $attempt=query('SELECT * FROM login_attempts WHERE key=?',[$key])->fetch();
        if($attempt && $attempt['reset_at']>time() && $attempt['attempts']>=10) fail('Thử quá nhiều lần. Vui lòng thử lại sau 15 phút.',429);
        query('INSERT INTO login_attempts (key,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN reset_at<? THEN 1 ELSE attempts+1 END, reset_at=CASE WHEN reset_at<? THEN ? ELSE reset_at END',[$key,time()+900,time(),time(),time()+900]);
        if($route==='signup') {
            $name=field($body,'name',80);
            if(query('SELECT id FROM users WHERE email=?',[$email])->fetch()) fail('Email này đã được sử dụng.',409);
            query('INSERT INTO users (name,email,password) VALUES (?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $_SESSION['uid']=(int)$db->lastInsertId();
        } else {
            $u=query('SELECT * FROM users WHERE email=?',[$email])->fetch();
            if(!$u||!password_verify($password,$u['password'])) fail('Email hoặc mật khẩu không đúng.',401);
            $_SESSION['uid']=(int)$u['id'];
        }
        query('DELETE FROM login_attempts WHERE key=?',[$key]);
        session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(24));
        $cart=query('SELECT items FROM carts WHERE user_id=?',[$_SESSION['uid']])->fetchColumn();
        reply(['user'=>user(),'csrf'=>$_SESSION['csrf'],'cart'=>$cart?json_decode($cart,true):[]]);
    }
    if($method==='POST' && $route==='logout') { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(24)); reply(['csrf'=>$_SESSION['csrf']]); }
    if($route==='cart') {
        $u=user(); if($method==='POST') { $items=normalizeCart($body['items']??[]); query('INSERT INTO carts (user_id,items) VALUES (?,?) ON CONFLICT(user_id) DO UPDATE SET items=excluded.items',[$u['id'],json_encode($items,JSON_UNESCAPED_UNICODE)]); reply(['items'=>$items]); }
    }
    if($route==='address' && $method==='POST') {
        $u=user(); $address=validateAddress($body); query('UPDATE users SET address=? WHERE id=?',[json_encode($address,JSON_UNESCAPED_UNICODE),$u['id']]); reply(['user'=>user()]);
    }
    if($route==='orders' && $method==='GET') {
        $u=user(); $orders=query('SELECT o.*,r.reason AS return_reason,r.status AS return_status FROM orders o LEFT JOIN returns r ON r.order_id=o.id WHERE o.user_id=? ORDER BY o.created_at DESC,o.rowid DESC',[$u['id']])->fetchAll();
        foreach($orders as &$o) { $o['items']=json_decode($o['items'],true); $o['address']=json_decode($o['address'],true); } unset($o); reply(['orders'=>$orders]);
    }
    if($route==='checkout' && $method==='POST') {
        $u=user(); $address=validateAddress($body['address']??[]); $requestKey=field($body,'request_key',80);
        $db->exec('BEGIN IMMEDIATE');
        $existing=query('SELECT id FROM orders WHERE user_id=? AND request_key=?',[$u['id'],$requestKey])->fetch();
        if($existing) { $db->exec('COMMIT'); reply(['id'=>$existing['id']]); }
        $items=normalizeCart($body['items']??[]); if(!$items) fail('Giỏ hàng đang trống.');
        $subtotal=0; foreach($items as $item) { $subtotal+=$item['price']*$item['qty']; $s=query('UPDATE products SET stock=stock-? WHERE id=? AND active=1 AND stock>=?',[$item['qty'],$item['id'],$item['qty']]); if(!$s->rowCount()) { $db->exec('ROLLBACK'); fail('Sản phẩm '.$item['name'].' không còn bày bán hoặc không đủ số lượng.',409); } }
        $shipping=$subtotal>=799000?0:['hcm'=>25000,'city'=>35000,'other'=>45000][$address['region']];
        $id='MN-'.strtoupper(bin2hex(random_bytes(4)));
        query('INSERT INTO orders (id,user_id,items,subtotal,shipping,total,address,request_key) VALUES (?,?,?,?,?,?,?,?)',[$id,$u['id'],json_encode($items,JSON_UNESCAPED_UNICODE),$subtotal,$shipping,$subtotal+$shipping,json_encode($address,JSON_UNESCAPED_UNICODE),$requestKey]);
        query('UPDATE users SET address=? WHERE id=?',[json_encode($address,JSON_UNESCAPED_UNICODE),$u['id']]);
        query('DELETE FROM carts WHERE user_id=?',[$u['id']]); $db->exec('COMMIT'); reply(['id'=>$id],201);
    }
    if($route==='cancel' && $method==='POST') {
        $u=user(); $db->exec('BEGIN IMMEDIATE'); $o=query('SELECT * FROM orders WHERE id=? AND user_id=?',[$body['id']??'', $u['id']])->fetch();
        if(!$o||$o['status']!=='confirmed') { $db->exec('ROLLBACK'); fail('Chỉ có thể hủy đơn đang chờ xử lý.'); }
        query("UPDATE orders SET status='cancelled' WHERE id=?",[$o['id']]);
        foreach(json_decode($o['items'],true) as $i) query('UPDATE products SET stock=stock+? WHERE id=?',[$i['qty'],$i['id']]); $db->exec('COMMIT'); reply(['ok'=>true]);
    }
    if($route==='return' && $method==='POST') {
        $u=user(); $o=query('SELECT * FROM orders WHERE id=? AND user_id=?',[$body['id']??'', $u['id']])->fetch();
        if(!$o||$o['status']!=='delivered'||!$o['delivered_at']||time()-strtotime($o['delivered_at'].' UTC')>604800) fail('Yêu cầu trả hàng áp dụng trong 7 ngày sau khi giao thành công.');
        if(query('SELECT id FROM returns WHERE order_id=?',[$o['id']])->fetch()) fail('Đơn này đã có yêu cầu trả hàng.');
        query('INSERT INTO returns (order_id,user_id,reason) VALUES (?,?,?)',[$o['id'],$u['id'],field($body,'reason',1000)]); reply(['ok'=>true],201);
    }
    if($route==='listings' && $method==='GET') reply(['listings'=>query('SELECT l.*, (SELECT COUNT(*) FROM comments c WHERE c.listing_id=l.id) AS comments FROM listings l ORDER BY l.created_at DESC,l.id DESC')->fetchAll()]);
    if($route==='listings' && $method==='POST') {
        $u=user(); $p=query('SELECT * FROM products WHERE id=?',[(int)($body['product_id']??0)])->fetch(); if(!$p) fail('Vui lòng chọn sản phẩm của MONO.');
        $price=$body['price']??0; if(!is_int($price)||$price<1000||$price>10000000) fail('Giá bán từ 1.000đ đến 10.000.000đ.');
        $condition=field($body,'condition',30); if(!in_array($condition,['Như mới','Rất tốt','Tốt'],true)) fail('Tình trạng không hợp lệ.');
        query('INSERT INTO listings (user_id,seller,product_id,title,price,condition,description,image) VALUES (?,?,?,?,?,?,?,?)',[$u['id'],$u['name'],$p['id'],field($body,'title',100),$price,$condition,field($body,'description',2000),$p['image']]); reply(['ok'=>true],201);
    }
    if($route==='sold' && $method==='POST') { $u=user(); $s=query("UPDATE listings SET status='sold' WHERE id=? AND user_id=? AND status='available'",[(int)($body['id']??0),$u['id']]); if(!$s->rowCount()) fail('Bạn không thể cập nhật bài đăng này.'); reply(['ok'=>true]); }
    if($route==='comments' && $method==='GET') {
        $id=(int)($_GET['listing_id']??0); reply(['comments'=>query('SELECT c.id,c.body,c.created_at,u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.listing_id=? ORDER BY c.id',[$id])->fetchAll()]);
    }
    if($route==='comments' && $method==='POST') {
        $u=user(); $l=query('SELECT * FROM listings WHERE id=?',[(int)($body['listing_id']??0)])->fetch(); if(!$l||!$l['user_id']||$l['status']!=='available') fail('Bài mẫu hoặc bài đã bán không nhận bình luận.');
        query('INSERT INTO comments (listing_id,user_id,body) VALUES (?,?,?)',[$l['id'],$u['id'],field($body,'body',1000)]); reply(['ok'=>true],201);
    }
    fail('Không tìm thấy chức năng.',404);
} catch(Throwable $e) {
    if(isset($db)) { try { if($db->inTransaction()) $db->rollBack(); } catch(Throwable $ignored) {} }
    error_log('MONO: '.$e->getMessage()); fail('Không thể xử lý lúc này. Vui lòng thử lại.',500);
}
function validateAddress(array $b): array {
    $a=['name'=>field($b,'name',80),'phone'=>field($b,'phone',15),'street'=>field($b,'street',200),'city'=>field($b,'city',100),'region'=>field($b,'region',10)];
    if(!preg_match('/^(0[0-9]{9}|\+84[0-9]{9})$/',$a['phone'])) fail('Số điện thoại Việt Nam không hợp lệ.');
    if(!in_array($a['region'],['hcm','city','other'],true)) fail('Khu vực giao hàng không hợp lệ.'); return $a;
}
