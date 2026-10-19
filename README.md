# MONO — Cửa hàng thời trang

Website bán quần áo bằng tiếng Việt, sử dụng HTML, CSS, JavaScript và PHP với SQLite. Docker Compose chạy ba dịch vụ: HTML, CSS và PHP.

## Chức năng

- Xem, tìm kiếm, lọc sản phẩm và lưu sản phẩm yêu thích.
- Đăng ký, đăng nhập, giỏ hàng và đặt hàng COD.
- Theo dõi đơn hàng, hủy đơn và yêu cầu trả hàng.
- Quản trị sản phẩm: tải ảnh, thêm/sửa thông tin, tồn kho, gỡ và bày bán lại.
- Quản trị trạng thái giao hàng và xử lý yêu cầu trả hàng.
- Đăng bài secondhand và bình luận.

## Chạy bằng Docker

Cần Git và Docker Desktop đang chạy ở chế độ Linux containers. Lần build đầu cần kết nối Internet.

```powershell
git clone https://github.com/Khuongg0907/web1.git
cd web1
docker compose up -d --build --wait
```

Mở **http://localhost:8080**.

Sau khi sửa mã nguồn, chạy lại `docker compose up -d --build --wait`. Để dừng ứng dụng và giữ dữ liệu, dùng `docker compose down`.

## Bật quản trị

Sao chép cấu hình mẫu:

```powershell
Copy-Item .env.example .env
```

Đặt `MONO_ADMIN_TOKEN` trong `.env` thành khóa bí mật riêng, tối thiểu 24 ký tự, rồi chạy `docker compose up -d --wait`. Giữ `.env` riêng trên máy; tệp này không được đưa lên GitHub.

Ở cuối website, chọn **Quản trị cửa hàng** và nhập khóa đã cấu hình:

- **Sản phẩm → + Thêm sản phẩm**: nhập thông tin và tải ảnh JPG, PNG hoặc WebP tối đa 5 MB, tối đa 6000 × 6000 pixel.
- **Chỉnh sửa**: cập nhật thông tin, giá, tồn kho hoặc ảnh.
- **Gỡ khỏi cửa hàng / Bày bán lại**: thay đổi trạng thái bày bán, giữ nguyên lịch sử đơn hàng.
- **Đơn hàng & trả hàng**: cập nhật giao hàng và xử lý yêu cầu trả hàng.

## Cấu trúc mã nguồn

| Tệp / thư mục | Vai trò |
| --- | --- |
| `index.html` | Giao diện và JavaScript tương tác |
| `style.css` | Định dạng giao diện, hỗ trợ điện thoại |
| `database.php` | API PHP, xác thực, SQLite và xử lý ảnh tải lên |
| `Dockerfile`, `compose.yaml` | Build và chạy ba container |
| `docker/` | Cấu hình Nginx, Apache và PHP |
| `.env.example` | Mẫu cấu hình, không chứa khóa quản trị |
| `.qa/` | Kiểm thử API, trình duyệt và cấu hình Docker kiểm thử riêng |

## Lưu trữ dữ liệu

Trong container PHP, cơ sở dữ liệu nằm ở `/var/lib/mono/shop.sqlite`, ảnh sản phẩm ở `/var/lib/mono/uploads/`. Dữ liệu được giữ trong volume `mono-clothes_mono_data` khi rebuild hoặc dừng container.

`docker compose down -v` xóa volume cùng dữ liệu tài khoản, đơn hàng và ảnh. Image Docker và mã nguồn Git không bao gồm dữ liệu trong volume. Xem cách sao lưu trong [hướng dẫn Docker](README.Docker.md#7-sao-lưu-dữ-liệu-sqlite).

## Kiểm thử

Kiểm tra cú pháp JavaScript:

```powershell
node .qa/verify.mjs --syntax-only
```

Kiểm thử API và giao diện trên Windows cần Node.js và Google Chrome ở đường dẫn cài mặc định:

```powershell
npm.cmd install --prefix .qa --package-lock=false --ignore-scripts
docker compose build
docker compose -f .qa/compose.yaml up -d --wait
node .qa/verify.mjs
docker compose -f .qa/compose.yaml down
```

Môi trường kiểm thử sử dụng cổng `18089` và volume riêng; khóa trong `.qa/compose.yaml` chỉ dành cho môi trường này. Bài kiểm thử tạo dữ liệu mẫu trong volume kiểm thử.

Ảnh sản phẩm mẫu và phông chữ cần Internet. Thanh toán hiện dùng COD; trạng thái giao hàng do admin cập nhật.

Hướng dẫn cấu hình, đổi cổng, xuất image và sao lưu: [README.Docker.md](README.Docker.md).
