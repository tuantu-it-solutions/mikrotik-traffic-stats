<?php
/**
 * Khởi tạo chung cho mọi trang. Giữ thật nhẹ vì log.php gọi file này
 * mỗi lần router gửi dữ liệu.
 */
if (!defined('MTS')) {
    http_response_code(403);
    exit;
}

define('MTS_VERSION', '1.0.0');
define('MTS_ROOT', dirname(__DIR__));

require MTS_ROOT . '/config.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set(APP_TIMEZONE);

if (!extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Hosting chưa bật PHP extension pdo_sqlite. Hãy bật nó trong cPanel > Select PHP Version > Extensions.";
    exit;
}

require __DIR__ . '/db.php';
