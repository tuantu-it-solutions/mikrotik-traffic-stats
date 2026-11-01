<?php
/**
 * API cho trang thống kê:
 *   api.php?a=overview                      JSON để tự làm mới trang Tổng quan (hỗ trợ ETag/304)
 *   api.php?a=csv&from=YYYY-MM-DD&to=...    Xuất CSV theo giờ/ngày/tháng/năm (tự chọn theo độ dài,
 *                                           hoặc ép bằng &g=hour|day|month|year; &range=all = toàn bộ)
 *   api.php?a=raw                           Xuất CSV toàn bộ mẫu chi tiết (chỉ admin)
 */
define('MTS', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/stats.php';
require __DIR__ . '/inc/views.php';

send_security_headers();

function api_error(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();
    $s = settings($db);
} catch (Throwable $e) {
    error_log('[net-traffic] ' . $e->getMessage());
    api_error(500, 'Không mở được cơ sở dữ liệu');
}

$isAdmin = auth_user($db) !== null;
if ($s['private_mode'] === '1' && !$isAdmin) {
    api_error(401, 'Cần đăng nhập');
}

$action = (string) ($_GET['a'] ?? 'overview');
$now = time();

if ($action === 'overview') {
    $rev = data_rev($db, $now);
    $etag = '"' . $rev . '"';
    header('Cache-Control: no-cache, private');
    header('ETag: ' . $etag);
    $inm = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && strpos($inm, $etag) !== false) {
        http_response_code(304);
        exit;
    }
    $d = overview_data($db, $s, $now);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'rev' => $rev,
        'data' => overview_chart_payload($d),
        'html' => overview_sections($d, $s),
    ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Gửi CSV (UTF-8 có BOM để Excel hiển thị đúng tiếng Việt). */
function csv_start(string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
}

function csv_line(array $cols): void
{
    $out = [];
    foreach ($cols as $c) {
        $c = (string) $c;
        $out[] = preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
    }
    echo implode(',', $out), "\r\n";
}

if ($action === 'csv') {
    $firstDay = db_value($db, 'SELECT MIN(d) FROM daily');
    list($from, $to) = resolve_range(
        (string) ($_GET['range'] ?? ''),
        $_GET['from'] ?? null,
        $_GET['to'] ?? null,
        $firstDay !== null ? (string) $firstDay : null,
        $now
    );
    $gran = isset($_GET['g']) && is_string($_GET['g']) ? $_GET['g'] : null;
    $h = history_data($db, $s, $from, $to, $now, $gran);
    $b = $h['unitBase'];
    csv_start('traffic_' . $from . '_' . $to . '_' . $h['gran'] . '.csv');
    csv_line(['Thời gian', 'Download (byte)', 'Upload (byte)', 'Tổng (byte)', 'Download', 'Upload', 'Tổng']);
    foreach ($h['items'] as $it) {
        if ($it[1] === null) {
            continue;
        }
        $k = $it[0];
        $label = $h['gran'] === 'hour' ? date('Y-m-d H:00', $k) : (string) $k;
        csv_line([$label, $it[1], $it[2], $it[1] + $it[2], fmt_bytes($it[1], $b), fmt_bytes($it[2], $b), fmt_bytes($it[1] + $it[2], $b)]);
    }
    exit;
}

if ($action === 'raw') {
    if (!$isAdmin) {
        api_error(401, 'Cần đăng nhập');
    }
    csv_start('traffic_samples_' . date('Ymd_His', $now) . '.csv');
    csv_line(['Thời điểm', 'Unix time', 'Download (byte)', 'Upload (byte)', 'Chu kỳ (giây)', 'Tốc độ ↓ (bps)', 'Tốc độ ↑ (bps)']);
    $st = $db->query('SELECT ts, rx, tx, dur FROM samples ORDER BY id');
    while ($r = $st->fetch()) {
        $dur = (int) $r['dur'];
        csv_line([
            date('Y-m-d H:i:s', (int) $r['ts']), $r['ts'], $r['rx'], $r['tx'], $dur,
            $dur >= RATE_MIN_DUR ? round($r['rx'] * 8 / $dur) : '',
            $dur >= RATE_MIN_DUR ? round($r['tx'] * 8 / $dur) : '',
        ]);
    }
    exit;
}

api_error(400, 'Yêu cầu không hợp lệ');
