<?php
/**
 * Hàm tiện ích: định dạng số/ngày giờ kiểu Việt Nam, URL, icon, footer.
 */
defined('MTS') || exit;

const VN_WEEKDAYS = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
const VN_WEEKDAYS_SHORT = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- Số & dung lượng ---------- */

function fmt_num($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

/** Số chữ số thập phân hợp lý theo độ lớn: 1,23 / 12,3 / 123 */
function auto_decimals(float $v): int
{
    $a = abs($v);
    if ($a < 10) {
        return 2;
    }
    if ($a < 100) {
        return 1;
    }
    return 0;
}

/** @return array{0:string,1:string} [giá trị, đơn vị] */
function bytes_parts($bytes, int $base = 1024): array
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $v = (float) $bytes;
    $i = 0;
    while (abs($v) >= $base && $i < count($units) - 1) {
        $v /= $base;
        $i++;
    }
    return [fmt_num($v, $i === 0 ? 0 : auto_decimals($v)), $units[$i]];
}

function fmt_bytes($bytes, int $base = 1024): string
{
    $p = bytes_parts($bytes, $base);
    return $p[0] . ' ' . $p[1];
}

/** @return array{0:string,1:string} tốc độ bit/giây -> [giá trị, đơn vị] */
function rate_parts($bps): array
{
    $units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    $v = (float) $bps;
    $i = 0;
    while (abs($v) >= 1000 && $i < count($units) - 1) {
        $v /= 1000;
        $i++;
    }
    return [fmt_num($v, $i === 0 ? 0 : auto_decimals($v)), $units[$i]];
}

function fmt_rate($bps): string
{
    $p = rate_parts($bps);
    return $p[0] . ' ' . $p[1];
}

function fmt_percent(float $ratio, int $decimals = 0): string
{
    return fmt_num($ratio * 100, $decimals) . '%';
}

/* ---------- Ngày giờ (múi giờ APP_TIMEZONE) ---------- */

function fmt_day(string $ymd, bool $weekday = false): string
{
    $ts = strtotime($ymd . ' 00:00:00');
    $s = date('d/m/Y', $ts);
    return $weekday ? VN_WEEKDAYS[(int) date('w', $ts)] . ', ' . $s : $s;
}

function fmt_month(string $ym): string
{
    $p = explode('-', $ym);
    return 'Tháng ' . (int) $p[1] . '/' . $p[0];
}

function fmt_datetime(int $ts): string
{
    return date('H:i:s d/m/Y', $ts);
}

function fmt_duration(int $sec): string
{
    if ($sec < 60) {
        return $sec . ' giây';
    }
    if ($sec < 3600) {
        $m = intdiv($sec, 60);
        $s = $sec % 60;
        return $m . ' phút' . ($s ? ' ' . $s . ' giây' : '');
    }
    if ($sec < 86400) {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        return $h . ' giờ' . ($m ? ' ' . $m . ' phút' : '');
    }
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    return $d . ' ngày' . ($h ? ' ' . $h . ' giờ' : '');
}

function rel_time(int $ts, ?int $now = null): string
{
    $diff = ($now ?? time()) - $ts;
    if ($diff < 5) {
        return 'vừa xong';
    }
    if ($diff < 60) {
        return $diff . ' giây trước';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . ' phút trước';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . ' giờ trước';
    }
    return intdiv($diff, 86400) . ' ngày trước';
}

function valid_ymd($s): bool
{
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/* ---------- HTTP / URL ---------- */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
        return true;
    }
    return strpos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false;
}

/** Đường dẫn thư mục chứa app, ví dụ "/linh-tinh/net-traffic/". */
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    return rtrim($dir, '/') . '/';
}

function base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    return (is_https() ? 'https' : 'http') . '://' . $host . base_path();
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** URL tài nguyên tĩnh kèm phiên bản để trình duyệt cache lâu dài. */
function asset(string $path): string
{
    $file = MTS_ROOT . '/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : MTS_VERSION;
    return $path . '?v=' . $v;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

/** JSON an toàn để nhúng vào <script type="application/json">. */
function json_script($data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $json === false ? 'null' : $json;
}

/**
 * Footer do admin nhập: văn bản thường, hỗ trợ
 *   {year}            -> năm hiện tại
 *   [chữ](https://..) -> liên kết
 *   https://...       -> tự thành liên kết
 *   **chữ đậm**
 *   xuống dòng        -> <br>
 * Mọi thứ khác đều được escape nên không thể chèn HTML/JS.
 */
function footer_html(string $text): string
{
    $text = str_replace('{year}', date('Y'), $text);
    $pattern = '~\[([^\]\n]{1,200})\]\((https?://[^\s()<>"]{1,500})\)|(https?://[^\s<>"]{1,500})|\*\*([^*\n]{1,200})\*\*~u';
    $out = '';
    $pos = 0;
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            $out .= e(substr($text, $pos, $m[0][1] - $pos));
            if (isset($m[1]) && $m[1][1] >= 0 && $m[1][0] !== '') {
                $out .= '<a href="' . e($m[2][0]) . '" target="_blank" rel="noopener">' . e($m[1][0]) . '</a>';
            } elseif (isset($m[3]) && $m[3][1] >= 0 && $m[3][0] !== '') {
                $url = rtrim($m[3][0], '.,;:!?');
                $tail = substr($m[3][0], strlen($url));
                $out .= '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($url) . '</a>' . e($tail);
            } else {
                $out .= '<strong>' . e($m[4][0]) . '</strong>';
            }
            $pos = $m[0][1] + strlen($m[0][0]);
        }
    }
    $out .= e(substr($text, $pos));
    return nl2br($out, false);
}

/* ---------- Icon SVG (nét vẽ đơn giản, dùng currentColor) ---------- */

function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'download' => '<path d="M12 4v12"/><path d="m6.5 10.5 5.5 5.5 5.5-5.5"/><path d="M5 20h14"/>',
        'upload' => '<path d="M12 20V8"/><path d="m6.5 13.5 5.5-5.5 5.5 5.5"/><path d="M5 4h14"/>',
        'sigma' => '<path d="M18 5H6l6 7-6 7h12"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'gauge' => '<path d="M3.5 17a9 9 0 1 1 17 0"/><path d="m12 15 4-4.5"/><circle cx="12" cy="15" r="1.4"/>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'sliders' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'logout' => '<path d="M9 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'router' => '<rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 16.5h.01M11 16.5h.01M15 13V9"/><path d="M11.5 6.5a5 5 0 0 1 7 0M9 4a8.5 8.5 0 0 1 12 0"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 11v6M9 14l3 3 3-3"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7"/>',
        'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'trophy' => '<path d="M7 4h10v4a5 5 0 0 1-10 0z"/><path d="M7 6H4v1a3 3 0 0 0 3 3M17 6h3v1a3 3 0 0 1-3 3"/><path d="M12 13v4M8 21h8M9 17h6"/>',
        'chart' => '<path d="M4 20h16"/><path d="M7 16v-5M12 16V7M17 16v-8"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M14 9l2 2"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 10v10"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    ];
    $inner = $paths[$name] ?? $paths['info'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}
