<?php
/**
 * Kết nối SQLite, tạo/nâng cấp cấu trúc bảng và đọc/ghi cài đặt.
 *
 * Toàn bộ dữ liệu nằm trong MỘT file SQLite (DB_PATH):
 *   samples  - từng mẫu router gửi lên (giữ N ngày, dùng cho biểu đồ tốc độ)
 *   hourly   - tổng hợp theo giờ (giữ vĩnh viễn)
 *   daily    - tổng hợp theo ngày (giữ vĩnh viễn, dùng cho tổng toàn thời gian)
 *   settings - cài đặt do admin chỉnh
 *   meta     - trạng thái nội bộ (mốc mẫu cuối, tài khoản admin...)
 */
defined('MTS') || exit;

const DB_SCHEMA_VERSION = 1;

class DbUnavailable extends RuntimeException
{
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new DbUnavailable('Không tạo được thư mục dữ liệu: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new DbUnavailable('PHP không có quyền ghi vào thư mục dữ liệu: ' . $dir
            . ' (hãy chmod 755 hoặc 775 cho thư mục này).');
    }
    if (file_exists(DB_PATH) && !is_writable(DB_PATH)) {
        throw new DbUnavailable('PHP không có quyền ghi file dữ liệu: ' . DB_PATH);
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10, // chờ tối đa 10 giây nếu file đang bị khóa
        ]);
    } catch (PDOException $e) {
        throw new DbUnavailable('Không mở được file SQLite: ' . $e->getMessage());
    }

    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= DB_SCHEMA_VERSION) {
        return;
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        if ($version < 1) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS samples (
                id  INTEGER PRIMARY KEY,
                ts  INTEGER NOT NULL,           -- thời điểm server nhận (unix)
                rx  INTEGER NOT NULL,           -- byte download kể từ mẫu trước
                tx  INTEGER NOT NULL,           -- byte upload kể từ mẫu trước
                dur INTEGER NOT NULL DEFAULT 0  -- số giây kể từ mẫu trước (0 = chưa rõ)
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_samples_ts ON samples(ts)');

            $pdo->exec('CREATE TABLE IF NOT EXISTS hourly (
                h       INTEGER PRIMARY KEY,    -- unix time của đầu giờ (giờ địa phương)
                rx      INTEGER NOT NULL DEFAULT 0,
                tx      INTEGER NOT NULL DEFAULT 0,
                n       INTEGER NOT NULL DEFAULT 0,
                peak_rx REAL NOT NULL DEFAULT 0, -- tốc độ TB cao nhất của 1 mẫu (byte/giây)
                peak_tx REAL NOT NULL DEFAULT 0
            )');

            $pdo->exec('CREATE TABLE IF NOT EXISTS daily (
                d       TEXT PRIMARY KEY,       -- YYYY-MM-DD theo giờ địa phương
                rx      INTEGER NOT NULL DEFAULT 0,
                tx      INTEGER NOT NULL DEFAULT 0,
                n       INTEGER NOT NULL DEFAULT 0,
                peak_rx REAL NOT NULL DEFAULT 0,
                peak_tx REAL NOT NULL DEFAULT 0
            ) WITHOUT ROWID');

            $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL) WITHOUT ROWID');
            $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL) WITHOUT ROWID');
            $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
                ip    TEXT PRIMARY KEY,
                fails INTEGER NOT NULL,
                last  INTEGER NOT NULL
            ) WITHOUT ROWID');
        }

        $pdo->exec('PRAGMA user_version = ' . DB_SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/* ---------- Truy vấn tiện dụng ---------- */

function db_all(PDO $db, string $sql, array $params = []): array
{
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_row(PDO $db, string $sql, array $params = []): ?array
{
    $st = $db->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** @return mixed */
function db_value(PDO $db, string $sql, array $params = [])
{
    $st = $db->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/* ---------- meta: trạng thái nội bộ ---------- */

function meta_get(PDO $db, string $key, ?string $default = null): ?string
{
    $v = db_value($db, 'SELECT v FROM meta WHERE k = ?', [$key]);
    return $v === null ? $default : (string) $v;
}

function meta_get_many(PDO $db, array $keys): array
{
    $out = array_fill_keys($keys, null);
    if (!$keys) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($keys), '?'));
    foreach (db_all($db, "SELECT k, v FROM meta WHERE k IN ($in)", array_values($keys)) as $r) {
        $out[$r['k']] = (string) $r['v'];
    }
    return $out;
}

function meta_set(PDO $db, string $key, string $value): void
{
    $db->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$key, $value]);
}

function meta_delete(PDO $db, array $keys): void
{
    $st = $db->prepare('DELETE FROM meta WHERE k = ?');
    foreach ($keys as $k) {
        $st->execute([$k]);
    }
}

/* ---------- settings: cài đặt do admin chỉnh ---------- */

function settings_defaults(): array
{
    return [
        'site_name' => 'Thống kê lưu lượng Internet',
        'site_tagline' => 'Theo dõi traffic từ router MikroTik theo thời gian thực',
        'footer_text' => '© {year} · Thống kê lưu lượng Internet từ MikroTik RouterOS',
        'iface_label' => 'pppoe-1',
        'default_theme' => 'auto',   // auto | dark | light
        'private_mode' => '0',       // 1 = phải đăng nhập mới xem được thống kê
        'unit_base' => '1024',       // 1024 hoặc 1000
        'refresh_sec' => '60',       // 0 = không tự làm mới
        'plan_down_mbps' => '0',     // băng thông gói cước (0 = tự co theo đỉnh)
        'plan_up_mbps' => '0',
        'log_key' => '',             // khóa bảo mật cho log.php (rỗng = không yêu cầu)
        'raw_retention_days' => '30',
    ];
}

function settings(PDO $db, bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = settings_defaults();
    foreach (db_all($db, 'SELECT k, v FROM settings') as $r) {
        if (array_key_exists($r['k'], $cache)) {
            $cache[$r['k']] = (string) $r['v'];
        }
    }
    return $cache;
}

function settings_save(PDO $db, array $values): void
{
    $defaults = settings_defaults();
    $st = $db->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)');
    foreach ($values as $k => $v) {
        if (array_key_exists($k, $defaults)) {
            $st->execute([$k, (string) $v]);
        }
    }
    meta_set($db, 'settings_rev', (string) (time() . mt_rand(100, 999)));
    settings($db, true);
}
