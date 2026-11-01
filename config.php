<?php
/**
 * Cấu hình cơ bản của Net Traffic.
 *
 * Phần lớn tùy chỉnh (tên trang, footer, khóa bảo mật, đơn vị...) được chỉnh
 * trong trang Quản trị (admin.php). File này chỉ chứa những thứ cần biết
 * TRƯỚC khi mở cơ sở dữ liệu.
 */

// Múi giờ hiển thị và dùng để gom dữ liệu theo giờ/ngày/tháng.
// Không nên đổi sau khi đã có dữ liệu vì các mốc giờ/ngày đã được tính sẵn.
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Ho_Chi_Minh');

// Đường dẫn tới file SQLite duy nhất chứa toàn bộ dữ liệu.
// Mặc định nằm trong thư mục data/ (đã chặn truy cập từ web bằng .htaccess).
// Nếu hosting cho phép, bạn có thể đặt ra ngoài public_html, ví dụ:
//   define('DB_PATH', '/home/tenuser/net-traffic-data/traffic.sqlite');
defined('DB_PATH') || define('DB_PATH', __DIR__ . '/data/traffic.sqlite');

// Quên mật khẩu quản trị? Đặt thành true, truy cập admin.php để tạo lại
// tài khoản (dữ liệu traffic được giữ nguyên), sau đó đặt lại thành false.
defined('ALLOW_ADMIN_RESET') || define('ALLOW_ADMIN_RESET', false);
