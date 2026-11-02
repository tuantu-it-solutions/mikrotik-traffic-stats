<?php
/**
 * Endpoint nhận dữ liệu từ router MikroTik.
 *
 * 1) Tương thích script cũ (gửi lượng byte phát sinh kể từ lần gửi trước):
 *      log.php?rx=<byte download>&tx=<byte upload>
 *    Giá trị âm (bộ đếm router bị reset) được tính là 0.
 *
 * 2) Chế độ bộ đếm - khuyên dùng (gửi thẳng rx-byte/tx-byte của interface):
 *      log.php?rxc=<rx-byte>&txc=<tx-byte>[&up=<uptime router>]
 *    Server tự tính chênh lệch nên gửi lỗi/gửi trùng không làm sai số liệu.
 *    Có thêm uptime thì sau khi router tắt lâu/khởi động lại, lượng byte được
 *    ghi đúng vào khoảng thời gian router thực sự chạy.
 *
 * Nếu admin đặt "Khóa bảo mật" thì thêm &key=<khóa> vào URL.
 * Trả về "OK" (HTTP 200) khi ghi thành công.
 */
define('MTS', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/recorder.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function log_reply(int $code, string $message): void
{
    http_response_code($code);
    echo $message, "\n";
    exit;
}

$input = $_GET + $_POST;

try {
    $db = db();
    $settings = settings($db);
} catch (Throwable $e) {
    error_log('[net-traffic] ' . $e->getMessage());
    log_reply(500, 'ERR database');
}

if ($settings['log_key'] !== '') {
    $key = isset($input['key']) && is_string($input['key']) ? $input['key'] : '';
    if (!hash_equals($settings['log_key'], $key)) {
        log_reply(403, 'ERR key');
    }
}

$now = time();
$meta = ['last_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')];

try {
    if (isset($input['rxc']) || isset($input['txc'])) {
        $rxc = parse_int_param($input['rxc'] ?? null);
        $txc = parse_int_param($input['txc'] ?? null);
        if ($rxc === null || $txc === null || $rxc < 0 || $txc < 0) {
            log_reply(400, 'ERR invalid rxc/txc');
        }
        $meta['last_mode'] = 'counter';
        // Uptime của router (tùy chọn) giúp biết chính xác lúc router khởi động lại.
        $uptime = isset($input['up']) ? parse_uptime($input['up']) : null;
        record_counters($db, $rxc, $txc, $now, $meta, $uptime);
    } elseif (isset($input['rx']) || isset($input['tx'])) {
        $rx = parse_int_param($input['rx'] ?? null);
        $tx = parse_int_param($input['tx'] ?? null);
        if ($rx === null || $tx === null) {
            log_reply(400, 'ERR invalid rx/tx');
        }
        $meta['last_mode'] = 'legacy';
        if ($rx < 0 || $tx < 0) {
            // Bộ đếm trên router bị reset -> phần chênh lệch âm, bỏ qua.
            $meta['negative_ts'] = (string) $now;
            $meta['negative_count'] = (string) ((int) meta_get($db, 'negative_count', '0') + 1);
        }
        record_sample($db, $rx, $tx, $now, $meta);
    } else {
        log_reply(400, 'ERR missing rx/tx');
    }
} catch (Throwable $e) {
    error_log('[net-traffic] ' . $e->getMessage());
    log_reply(500, 'ERR write');
}

try {
    recorder_housekeeping($db, $now);
} catch (Throwable $e) {
    error_log('[net-traffic] housekeeping: ' . $e->getMessage());
}

log_reply(200, 'OK');
