<?php
/**
 * Đăng nhập quản trị không dùng PHP session (tránh lỗi session trên shared
 * hosting): cookie được ký HMAC bằng khóa bí mật lưu trong SQLite. Đổi mật
 * khẩu sẽ vô hiệu hóa mọi phiên đăng nhập cũ.
 */
defined('MTS') || exit;

const AUTH_COOKIE = 'nt_auth';
const CSRF_COOKIE = 'nt_csrf';
const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 900;

function app_secret(PDO $db): string
{
    $secret = meta_get($db, 'app_secret');
    if ($secret === null || strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        meta_set($db, 'app_secret', $secret);
    }
    return $secret;
}

function admin_exists(PDO $db): bool
{
    $m = meta_get_many($db, ['admin_user', 'admin_pass']);
    return !empty($m['admin_user']) && !empty($m['admin_pass']);
}

function admin_save(PDO $db, string $user, string $password): void
{
    meta_set($db, 'admin_user', $user);
    meta_set($db, 'admin_pass', password_hash($password, PASSWORD_DEFAULT));
}

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $r = base64_decode(strtr($s, '-_', '+/'), true);
    return $r === false ? '' : $r;
}

function cookie_options(int $expires, bool $httpOnly = true): array
{
    return [
        'expires' => $expires,
        'path' => base_path(),
        'secure' => is_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ];
}

function auth_key(PDO $db): string
{
    return app_secret($db) . '|' . (string) meta_get($db, 'admin_pass', '');
}

function auth_login(PDO $db, string $user, bool $remember): void
{
    $exp = time() + ($remember ? 30 * 86400 : 12 * 3600);
    $payload = b64url_encode(json_encode(['u' => $user, 'e' => $exp, 'n' => bin2hex(random_bytes(6))]));
    $token = $payload . '.' . hash_hmac('sha256', $payload, auth_key($db));
    setcookie(AUTH_COOKIE, $token, cookie_options($remember ? $exp : 0));
    $_COOKIE[AUTH_COOKIE] = $token;
}

function auth_logout(): void
{
    setcookie(AUTH_COOKIE, '', cookie_options(time() - 3600));
    unset($_COOKIE[AUTH_COOKIE]);
}

/** Tên admin đang đăng nhập, hoặc null. */
function auth_user(PDO $db): ?string
{
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($token) || strpos($token, '.') === false) {
        return null;
    }
    list($payload, $sig) = explode('.', $token, 2);
    if (!hash_equals(hash_hmac('sha256', $payload, auth_key($db)), $sig)) {
        return null;
    }
    $data = json_decode(b64url_decode($payload), true);
    if (!is_array($data) || ($data['e'] ?? 0) < time()) {
        return null;
    }
    $user = (string) ($data['u'] ?? '');
    return $user !== '' && hash_equals((string) meta_get($db, 'admin_user', ''), $user) ? $user : null;
}

/* ---------- CSRF (double-submit cookie, ký HMAC) ---------- */

function csrf_token(PDO $db): string
{
    $seed = $_COOKIE[CSRF_COOKIE] ?? '';
    if (!is_string($seed) || !preg_match('/^[a-f0-9]{32}$/', $seed)) {
        $seed = bin2hex(random_bytes(16));
        setcookie(CSRF_COOKIE, $seed, cookie_options(0));
        $_COOKIE[CSRF_COOKIE] = $seed;
    }
    return hash_hmac('sha256', 'csrf|' . $seed, app_secret($db));
}

function csrf_check(PDO $db): bool
{
    $seed = $_COOKIE[CSRF_COOKIE] ?? '';
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($seed) || $seed === '' || !is_string($sent) || $sent === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', 'csrf|' . $seed, app_secret($db)), $sent);
}

/* ---------- Chống dò mật khẩu ---------- */

/** Số giây còn bị khóa đăng nhập (0 = không bị khóa). */
function login_locked(PDO $db, string $ip): int
{
    $r = db_row($db, 'SELECT fails, last FROM login_attempts WHERE ip = ?', [$ip]);
    if (!$r || (int) $r['fails'] < LOGIN_MAX_FAILS) {
        return 0;
    }
    return max(0, LOGIN_LOCK_SECONDS - (time() - (int) $r['last']));
}

function login_failed(PDO $db, string $ip): void
{
    $now = time();
    $r = db_row($db, 'SELECT fails, last FROM login_attempts WHERE ip = ?', [$ip]);
    $fails = ($r && $now - (int) $r['last'] < LOGIN_LOCK_SECONDS) ? (int) $r['fails'] + 1 : 1;
    $db->prepare('INSERT OR REPLACE INTO login_attempts (ip, fails, last) VALUES (?, ?, ?)')
        ->execute([$ip, $fails, $now]);
}

function login_succeeded(PDO $db, string $ip): void
{
    $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}
