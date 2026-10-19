# Chạy MONO bằng Docker trên Windows

Ba tệp ứng dụng `index.html`, `style.css` và `database.php` được đóng gói thành **ba image và ba container** riêng. Một Dockerfile có ba build target, được Docker Compose build và kết nối tự động.

| Service | Image tạo ra | Nhiệm vụ |
| --- | --- | --- |
| `html` | `mono-html:1.0.0` | Nginx phục vụ HTML, nhận truy cập ở `http://localhost:8080`, chuyển yêu cầu CSS/API đến hai container còn lại. |
| `css` | `mono-css:1.0.0` | Nginx phục vụ riêng `style.css` trong mạng Docker. |
| `php` | `mono-php:1.0.0` | Apache + PHP 8.4 xử lý `/api/*`, đăng nhập, giỏ hàng, đơn hàng, trả hàng và diễn đàn; lưu SQLite trong volume riêng. |

```text
Trình duyệt → localhost:8080 → html
                                ├─ /style.css → css
                                └─ /api/*    → php → volume mono_data
```

Trình duyệt dùng cùng một địa chỉ cho HTML, CSS và API nên cookie đăng nhập hoạt động mà không cần cấu hình CORS. Không cần cài PHP, Nginx hoặc MySQL trên Windows. Tách CSS thành container riêng thực hiện đúng mô hình ba container được yêu cầu; với dự án nhỏ, thông thường CSS và HTML có thể dùng chung một web server.

## 1. Chuẩn bị Docker Desktop

Máy cần Docker Desktop với **Linux containers**. Mở Docker Desktop từ Start và đợi engine chạy. Nếu chưa cài, làm theo [hướng dẫn Docker Desktop cho Windows](https://docs.docker.com/desktop/setup/install/windows-install/), bao gồm bật WSL 2 khi trình cài đặt yêu cầu.

Mở PowerShell hoặc Terminal của VS Code:

```powershell
cd E:\web1
docker version
docker compose version
```

`docker version` cần hiển thị được cả **Client** và **Server**. Nếu chỉ có Client và lỗi kết nối named pipe, Docker Desktop/engine chưa chạy. Có thể thử `docker desktop start` hoặc mở ứng dụng Docker Desktop để xem thông báo cần xử lý.

## 2. Build image và chạy ba container

```powershell
cd E:\web1
docker compose up -d --build --wait
docker compose ps
```

Lần đầu Docker tải các image nền PHP/Nginx nên cần Internet. Khi cả ba service báo `healthy`, mở:

**http://localhost:8080**

Xem ba image đã tạo:

```powershell
docker image ls mono-html
docker image ls mono-css
docker image ls mono-php
```

Không cần chạy đồng thời lệnh `php -S ...` trước đây. Cấu hình mặc định mở cổng 8080 cho máy của bạn.

## 3. Bật quản trị cửa hàng (tùy chọn)

Tạo tệp cấu hình môi trường lần đầu:

```powershell
Copy-Item .env.example .env
```

Mở `.env` trong VS Code. Giữ `MONO_PORT=8080` và nhập một khóa bí mật riêng **ít nhất 24 ký tự** sau `MONO_ADMIN_TOKEN=`. Không dùng một khóa có sẵn trong ví dụ hoặc chia sẻ tệp `.env`.

Áp dụng cấu hình:

```powershell
docker compose up -d --wait
```

Mở website → **Quản trị cửa hàng** ở cuối trang → nhập khóa đã đặt. Có thể chuyển đơn qua các bước chuẩn bị, vận chuyển, giao thành công và duyệt/xử lý yêu cầu trả hàng. Để trống khóa thì phần quản trị bị vô hiệu hóa; mua hàng vẫn hoạt động.

### Quản lý sản phẩm và tải ảnh

Trong **Quản trị cửa hàng → Sản phẩm**, chọn **+ Thêm sản phẩm**. Nhập tên, danh mục, đối tượng, giá bán, tồn kho, màu sắc, kích cỡ và mô tả; chọn ảnh từ máy rồi nhấn **Thêm và bày bán**. Màu sắc và kích cỡ được phân cách bằng dấu phẩy. Giá gốc là tùy chọn và phải lớn hơn giá bán.

- Ảnh nhận định dạng JPG, PNG hoặc WebP, tối đa 5 MB và 6000 × 6000 pixel. Có xem trước ảnh trước khi lưu.
- **Chỉnh sửa** cập nhật thông tin, tồn kho hoặc thay ảnh. Không chọn ảnh mới thì giữ ảnh hiện tại.
- **Gỡ khỏi cửa hàng** ngừng bày bán và chặn đặt mới. Sản phẩm được giữ trong cơ sở dữ liệu để bảo toàn đơn hàng cũ; dùng **Bày bán lại** để khôi phục.
- Chuyển sang **Đơn hàng & trả hàng** để xử lý các đơn như trước.

Sản phẩm lưu trong bảng `products` của `/var/lib/mono/shop.sqlite`; ảnh tải lên lưu trong `/var/lib/mono/uploads/`. Cả hai nằm trong volume `mono-clothes_mono_data`, được giữ khi rebuild hoặc `docker compose down`. Khi sao lưu, sao chép cả thư mục `/var/lib/mono` theo hướng dẫn bên dưới để bao gồm ảnh. Ảnh cũ được giữ lại cho lịch sử đơn hàng và bài đăng secondhand.

Kiểm thử chức năng bằng môi trường riêng (yêu cầu Node.js và Google Chrome):

```powershell
npm.cmd install --prefix .qa --package-lock=false --ignore-scripts
docker compose build
docker compose -f .qa/compose.yaml up -d --wait
node .qa/verify.mjs
docker compose -f .qa/compose.yaml down
```

Bản kiểm thử chạy ở cổng 18089 với volume riêng `mono-catalog-qa_qa_data`, không dùng dữ liệu của website ở cổng 8080. Khóa trong `.qa/compose.yaml` chỉ dành cho môi trường kiểm thử.

## 4. Các lệnh sử dụng thường xuyên

Xem trạng thái và log:

```powershell
docker compose ps
docker compose logs --tail 100
docker compose logs -f php
```

Nhấn `Ctrl+C` để thoát chế độ xem log; container vẫn chạy.

Sau khi sửa HTML, CSS hoặc PHP, build lại để đưa thay đổi vào image:

```powershell
docker compose up -d --build --wait
```

Khởi động lại:

```powershell
docker compose restart
```

Dừng và gỡ ba container, **giữ dữ liệu**:

```powershell
docker compose down
```

Chạy lại từ image đã build:

```powershell
docker compose up -d --wait
```

SQLite và phiên đăng nhập nằm trong named volume `mono_data` (tên thực tế mặc định: `mono-clothes_mono_data`). Dữ liệu vẫn còn sau khi restart, rebuild hoặc `docker compose down`. `docker compose down -v` sẽ xóa volume cùng tài khoản và đơn hàng; chỉ dùng nếu bạn chủ động muốn xóa toàn bộ dữ liệu của ứng dụng Docker.

Dữ liệu trước đây tạo bằng `php -S` trên Windows nằm ở thư mục khác; Docker bắt đầu với cơ sở dữ liệu mới.

## 5. Đổi cổng nếu 8080 đang được sử dụng

Sửa `MONO_PORT=8081` trong `.env`, rồi chạy:

```powershell
docker compose up -d --wait
```

Truy cập **http://localhost:8081**. Chỉ cần đổi cổng ở `.env`; mã HTML/CSS/PHP giữ nguyên.

## 6. Mang các image sang máy khác

Sau khi build thành công, lưu cả ba image vào một tệp:

```powershell
docker image save -o mono-images.tar mono-html:1.0.0 mono-css:1.0.0 mono-php:1.0.0
```

Chép `mono-images.tar`, `compose.yaml` và `.env.example` sang một thư mục trên máy đích có Docker Desktop/Linux containers, cùng kiến trúc CPU với máy đã build. Tại máy đích:

```powershell
docker image load -i mono-images.tar
docker compose up -d --no-build --pull never --wait
```

Tạo `.env` nếu muốn đổi cổng hoặc đặt khóa quản trị. Tệp image không chứa dữ liệu SQLite trong volume; tài khoản, đơn hàng và khóa quản trị không được đóng gói vào image.

## 7. Sao lưu dữ liệu SQLite

Để bản sao gồm cả SQLite WAL nhất quán, dừng dịch vụ PHP trước khi sao chép thư mục dữ liệu. Các lệnh sau giữ nguyên container và volume:

```powershell
docker compose stop php
New-Item -ItemType Directory -Path .\mono-backup -Force
docker compose cp php:/var/lib/mono/. .\mono-backup
docker compose start php
```

Thư mục `mono-backup` chứa dữ liệu nhạy cảm của khách hàng, cần giữ riêng. Giữ nguyên cả các tệp SQLite/WAL trong bản sao; khi phục hồi, cần dùng một volume trống để tránh trộn dữ liệu từ các lần chạy khác nhau.

Ảnh sản phẩm và phông chữ vẫn được tải từ Internet. Thanh toán dùng COD; tiến trình vận chuyển được cửa hàng cập nhật, chưa tích hợp hãng giao hàng.

Tài liệu tham khảo: [Docker Compose](https://docs.docker.com/compose/gettingstarted/), [mạng Compose](https://docs.docker.com/compose/how-tos/networking/), [image PHP chính thức](https://hub.docker.com/_/php).
