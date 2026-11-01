<?php
/**
 * Trang quản trị: tạo tài khoản lần đầu, đăng nhập, cài đặt giao diện,
 * khóa bảo mật cho router, quản lý dữ liệu, đổi mật khẩu.
 */
define('MTS', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/stats.php';
require __DIR__ . '/inc/layout.php';

try {
    $db = db();
    $s = settings($db);
} catch (Throwable $e) {
    fatal_page('Không mở được cơ sở dữ liệu', $e->getMessage());
}

/* ---------- Tiện ích nhập liệu ---------- */

function str_limit(string $s, int $max): string
{
    return preg_match('/^.{0,' . $max . '}/su', $s, $m) ? $m[0] : '';
}

function post_line(string $key, int $max = 200): string
{
    $v = $_POST[$key] ?? '';
    $v = is_string($v) ? $v : '';
    return trim(str_limit(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '', $max));
}

function post_text(string $key, int $max = 1000): string
{
    $v = $_POST[$key] ?? '';
    $v = is_string($v) ? str_replace(["\r\n", "\r"], "\n", $v) : '';
    return trim(str_limit(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', '', $v) ?? '', $max));
}

function post_raw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

function redirect_to(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

function safe_next(string $next): string
{
    $base = base_path();
    if ($next !== '' && strpos($next, $base) === 0 && strpos($next, '//') === false && strpos($next, '\\') === false) {
        return $next;
    }
    return 'admin.php';
}

function interface_name(string $label): string
{
    return preg_match('/^[A-Za-z0-9._<>\-]{1,40}$/', $label) ? $label : 'pppoe-1';
}

function script_legacy(string $url, string $iface, string $key): string
{
    $tpl = <<<'RSC'
:global rxLast
:global txLast

:local iface "{IFACE}"
:local url "{URL}"

:local curRx [/interface get $iface rx-byte]
:local curTx [/interface get $iface tx-byte]

:if ([:typeof $rxLast] != "num") do={ :set rxLast $curRx }
:if ([:typeof $txLast] != "num") do={ :set txLast $curTx }

# counter reset (PPPoE reconnect / reboot): count from 0
:if ($curRx < $rxLast) do={ :set rxLast 0 }
:if ($curTx < $txLast) do={ :set txLast 0 }

:local diffRx ($curRx - $rxLast)
:local diffTx ($curTx - $txLast)

/tool fetch url=($url . "?rx=$diffRx&tx=$diffTx{KEY}") keep-result=no

:set rxLast $curRx
:set txLast $curTx
RSC;
    return strtr($tpl, ['{IFACE}' => $iface, '{URL}' => $url, '{KEY}' => $key !== '' ? '&key=' . $key : '']);
}

function script_counter(string $url, string $iface, string $key): string
{
    $tpl = <<<'RSC'
:local iface "{IFACE}"
:local url "{URL}"

:local rxc [/interface get $iface rx-byte]
:local txc [/interface get $iface tx-byte]

/tool fetch url=($url . "?rxc=$rxc&txc=$txc{KEY}") keep-result=no
RSC;
    return strtr($tpl, ['{IFACE}' => $iface, '{URL}' => $url, '{KEY}' => $key !== '' ? '&key=' . $key : '']);
}

/* ---------- Xử lý form ---------- */

$ip = client_ip();
$user = auth_user($db);
$resetMode = ALLOW_ADMIN_RESET && admin_exists($db);
// Chế độ khôi phục: chỉ hiện form tạo tài khoản khi chưa đăng nhập.
$needSetup = !admin_exists($db) || ($resetMode && $user === null);
$errors = [];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['general', 'router', 'data', 'account'], true) ? $_GET['tab'] : 'general';
$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = post_raw('action');

    if (!csrf_check($db)) {
        $errors[] = 'Phiên làm việc đã hết hạn hoặc không hợp lệ. Vui lòng thử lại.';
    } elseif ($action === 'setup' && $needSetup) {
        $u = post_line('username', 32);
        $p1 = post_raw('password');
        $p2 = post_raw('password2');
        $name = post_line('site_name', 100);
        if (!preg_match('/^[A-Za-z0-9_.@\-]{3,32}$/', $u)) {
            $errors[] = 'Tên đăng nhập 3–32 ký tự, chỉ gồm chữ không dấu, số và . _ - @';
        }
        if (strlen($p1) < 8) {
            $errors[] = 'Mật khẩu cần ít nhất 8 ký tự.';
        } elseif ($p1 !== $p2) {
            $errors[] = 'Mật khẩu nhập lại không khớp.';
        }
        if (!$errors) {
            admin_save($db, $u, $p1);
            if ($name !== '') {
                settings_save($db, ['site_name' => $name]);
            }
            login_succeeded($db, $ip);
            auth_login($db, $u, true);
            redirect_to('admin.php?msg=welcome');
        }
    } elseif ($action === 'login' && !$needSetup) {
        $wait = login_locked($db, $ip);
        if ($wait > 0) {
            $errors[] = 'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau ' . fmt_duration($wait) . '.';
        } else {
            $u = post_line('username', 64);
            $p = post_raw('password');
            $okUser = hash_equals((string) meta_get($db, 'admin_user', ''), $u);
            $okPass = password_verify($p, (string) meta_get($db, 'admin_pass', ''));
            if ($okUser && $okPass) {
                login_succeeded($db, $ip);
                auth_login($db, $u, !empty($_POST['remember']));
                redirect_to($next);
            }
            login_failed($db, $ip);
            usleep(random_int(200000, 500000));
            $errors[] = 'Tên đăng nhập hoặc mật khẩu không đúng.';
        }
    } elseif ($user !== null && !$needSetup) {
        switch ($action) {
            case 'logout':
                auth_logout();
                redirect_to('./');
                break;

            case 'save_general':
                $tab = 'general';
                $name = post_line('site_name', 100);
                $refresh = (int) post_raw('refresh_sec');
                $values = [
                    'site_name' => $name !== '' ? $name : settings_defaults()['site_name'],
                    'site_tagline' => post_line('site_tagline', 200),
                    'footer_text' => post_text('footer_text', 1000),
                    'iface_label' => post_line('iface_label', 60),
                    'default_theme' => in_array(post_raw('default_theme'), ['auto', 'light', 'dark'], true) ? post_raw('default_theme') : 'auto',
                    'unit_base' => post_raw('unit_base') === '1000' ? '1000' : '1024',
                    'refresh_sec' => (string) ($refresh <= 0 ? 0 : max(15, min(3600, $refresh))),
                    'plan_down_mbps' => (string) max(0, min(1000000, round((float) str_replace(',', '.', post_raw('plan_down_mbps')), 2))),
                    'plan_up_mbps' => (string) max(0, min(1000000, round((float) str_replace(',', '.', post_raw('plan_up_mbps')), 2))),
                    'private_mode' => !empty($_POST['private_mode']) ? '1' : '0',
                ];
                settings_save($db, $values);
                redirect_to('admin.php?tab=general&msg=saved');
                break;

            case 'save_router':
                $tab = 'router';
                $key = trim(post_raw('log_key'));
                if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $key)) {
                    $errors[] = 'Khóa bảo mật dài 8–64 ký tự, chỉ gồm chữ không dấu, số, "-" và "_".';
                    break;
                }
                settings_save($db, ['log_key' => $key]);
                redirect_to('admin.php?tab=router&msg=saved');
                break;

            case 'save_data':
                $tab = 'data';
                $days = max(1, min(3650, (int) post_raw('raw_retention_days')));
                settings_save($db, ['raw_retention_days' => (string) $days]);
                meta_delete($db, ['last_prune']); // dọn lại ngay ở lần router gửi tiếp theo
                redirect_to('admin.php?tab=data&msg=saved');
                break;

            case 'vacuum':
                $tab = 'data';
                try {
                    $db->exec('VACUUM');
                    redirect_to('admin.php?tab=data&msg=vacuumed');
                } catch (PDOException $e) {
                    $errors[] = 'Không tối ưu được lúc này (file đang bận), hãy thử lại: ' . $e->getMessage();
                }
                break;

            case 'reset_data':
                $tab = 'data';
                if (strtoupper(post_line('confirm', 10)) !== 'XOA') {
                    $errors[] = 'Gõ đúng chữ XOA để xác nhận xóa dữ liệu.';
                    break;
                }
                try {
                    $db->exec('BEGIN IMMEDIATE');
                    $db->exec('DELETE FROM samples');
                    $db->exec('DELETE FROM hourly');
                    $db->exec('DELETE FROM daily');
                    meta_delete($db, ['last_ts', 'last_rxc', 'last_txc', 'last_prune', 'last_mode', 'last_ip',
                        'negative_ts', 'negative_count', 'counter_reset_ts']);
                    meta_set($db, 'settings_rev', (string) time());
                    $db->exec('COMMIT');
                } catch (PDOException $e) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (PDOException $ignored) {
                    }
                    $errors[] = 'Không xóa được dữ liệu: ' . $e->getMessage();
                    break;
                }
                try {
                    $db->exec('VACUUM');
                } catch (PDOException $ignored) {
                }
                redirect_to('admin.php?tab=data&msg=reset');
                break;

            case 'save_account':
                $tab = 'account';
                $cur = post_raw('current_password');
                $u = post_line('username', 32);
                $p1 = post_raw('new_password');
                $p2 = post_raw('new_password2');
                if (!password_verify($cur, (string) meta_get($db, 'admin_pass', ''))) {
                    $errors[] = 'Mật khẩu hiện tại không đúng.';
                }
                if (!preg_match('/^[A-Za-z0-9_.@\-]{3,32}$/', $u)) {
                    $errors[] = 'Tên đăng nhập 3–32 ký tự, chỉ gồm chữ không dấu, số và . _ - @';
                }
                if ($p1 !== '' && strlen($p1) < 8) {
                    $errors[] = 'Mật khẩu mới cần ít nhất 8 ký tự.';
                } elseif ($p1 !== $p2) {
                    $errors[] = 'Mật khẩu mới nhập lại không khớp.';
                }
                if (!$errors) {
                    admin_save($db, $u, $p1 !== '' ? $p1 : $cur);
                    auth_login($db, $u, true); // khóa ký đổi theo mật khẩu -> đăng nhập lại
                    redirect_to('admin.php?tab=account&msg=account');
                }
                break;

            default:
                $errors[] = 'Thao tác không hợp lệ.';
        }
    } else {
        $errors[] = 'Bạn cần đăng nhập để thực hiện thao tác này.';
    }
}

$messages = [
    'welcome' => 'Tạo tài khoản quản trị thành công. Chào mừng bạn!',
    'saved' => 'Đã lưu cài đặt.',
    'vacuumed' => 'Đã tối ưu (VACUUM) cơ sở dữ liệu.',
    'reset' => 'Đã xóa toàn bộ dữ liệu lưu lượng.',
    'account' => 'Đã cập nhật tài khoản.',
];
$msg = $messages[(string) ($_GET['msg'] ?? '')] ?? null;
$csrf = csrf_token($db);

/* ---------- Giao diện: tạo tài khoản / đăng nhập ---------- */

if ($needSetup || $user === null) {
    $isSetup = $needSetup;
    page_start($s, [
        'title' => $isSetup ? 'Thiết lập quản trị' : 'Đăng nhập',
        'body_class' => 'page-auth',
        'noindex' => true,
        'hide_nav' => $s['private_mode'] === '1',
    ]); ?>
<section class="auth-wrap">
  <div class="card auth-card">
    <div class="auth-icon"><?= icon($isSetup ? 'key' : 'lock') ?></div>
    <h1><?= $isSetup ? 'Tạo tài khoản quản trị' : 'Đăng nhập quản trị' ?></h1>
    <p class="muted"><?= $isSetup
        ? 'Lần đầu sử dụng: đặt tên đăng nhập và mật khẩu để quản lý trang thống kê.'
        : 'Đăng nhập để tùy chỉnh tên trang, footer, khóa bảo mật và dữ liệu.' ?></p>

    <?php if ($resetMode): ?>
    <div class="alert warn"><?= icon('alert') ?><div>Đang bật <code>ALLOW_ADMIN_RESET</code> trong <code>config.php</code>. Sau khi tạo lại tài khoản, hãy đặt lại thành <code>false</code> ngay.</div></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
    <div class="alert error" role="alert"><?= icon('alert') ?><div><?= e($err) ?></div></div>
    <?php endforeach; ?>

    <form method="post" class="form-stack" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="<?= $isSetup ? 'setup' : 'login' ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <?php if ($isSetup): ?>
      <label class="field"><span>Tên trang web</span>
        <input type="text" name="site_name" maxlength="100" value="<?= e($s['site_name']) ?>"></label>
      <?php endif; ?>
      <label class="field"><span>Tên đăng nhập</span>
        <input type="text" name="username" required maxlength="32" autocomplete="username" autofocus
               value="<?= e(post_line('username', 32)) ?>"></label>
      <label class="field"><span>Mật khẩu</span>
        <input type="password" name="password" required <?= $isSetup ? 'minlength="8" autocomplete="new-password"' : 'autocomplete="current-password"' ?>></label>
      <?php if ($isSetup): ?>
      <label class="field"><span>Nhập lại mật khẩu</span>
        <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      <?php else: ?>
      <label class="check"><input type="checkbox" name="remember" value="1" checked><span>Ghi nhớ đăng nhập 30 ngày</span></label>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary btn-block"><?= icon($isSetup ? 'check' : 'lock') ?><?= $isSetup ? 'Tạo tài khoản' : 'Đăng nhập' ?></button>
    </form>
  </div>
</section>
<?php
    page_end($s, ['hide_admin_link' => true]);
    exit;
}

/* ---------- Giao diện quản trị ---------- */

$meta = meta_get_many($db, ['last_ts', 'last_ip', 'last_mode', 'negative_ts', 'negative_count', 'counter_reset_ts', 'last_prune']);
$typical = typical_interval($db);
$lastTs = $meta['last_ts'] !== null ? (int) $meta['last_ts'] : null;
$endpoint = base_url() . 'log.php';
$iface = interface_name($s['iface_label']);

page_start($s, [
    'title' => 'Quản trị',
    'active' => 'admin',
    'body_class' => 'page-admin',
    'noindex' => true,
    'is_admin' => true,
    'status' => ['lastTs' => $lastTs, 'typical' => $typical],
]);

$tabs = [
    'general' => ['sliders', 'Cài đặt chung'],
    'router' => ['router', 'Router MikroTik'],
    'data' => ['database', 'Dữ liệu'],
    'account' => ['user', 'Tài khoản'],
];
?>
<div class="page-title">
  <h1>Quản trị</h1>
  <p class="muted">Xin chào <b><?= e($user) ?></b> · mọi thay đổi áp dụng ngay cho trang thống kê.</p>
</div>

<?php if (ALLOW_ADMIN_RESET): ?>
<div class="alert warn"><?= icon('alert') ?><div><code>ALLOW_ADMIN_RESET</code> đang bật trong <code>config.php</code> — bất kỳ ai cũng có thể tạo lại tài khoản quản trị. Hãy đặt lại thành <code>false</code>.</div></div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="alert success" role="status"><?= icon('check') ?><div><?= e($msg) ?></div></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
<div class="alert error" role="alert"><?= icon('alert') ?><div><?= e($err) ?></div></div>
<?php endforeach; ?>

<div class="admin-layout">
  <nav class="admin-tabs" aria-label="Mục quản trị">
    <?php foreach ($tabs as $key => $t): ?>
    <a href="?tab=<?= $key ?>"<?= $tab === $key ? ' class="active" aria-current="page"' : '' ?>><?= icon($t[0]) ?><span><?= e($t[1]) ?></span></a>
    <?php endforeach; ?>
    <form method="post" class="logout-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit"><?= icon('logout') ?><span>Đăng xuất</span></button>
    </form>
  </nav>

  <div class="admin-content">
<?php if ($tab === 'general'): ?>
    <form method="post" class="card form-card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_general">
      <div class="card-head"><div><h2><?= icon('sliders') ?>Nhận diện trang</h2><p class="card-sub">Tên, mô tả và chân trang hiển thị cho người xem.</p></div></div>
      <div class="form-grid">
        <label class="field span-2"><span>Tên trang web</span>
          <input type="text" name="site_name" maxlength="100" required value="<?= e($s['site_name']) ?>"></label>
        <label class="field span-2"><span>Dòng mô tả (hiện dưới tên trang)</span>
          <input type="text" name="site_tagline" maxlength="200" value="<?= e($s['site_tagline']) ?>"></label>
        <label class="field span-2"><span>Nội dung footer</span>
          <textarea name="footer_text" rows="3" maxlength="1000" id="footer-input"><?= e($s['footer_text']) ?></textarea>
          <small class="hint">Hỗ trợ <code>{year}</code> = năm hiện tại, <code>[chữ](https://link)</code> = liên kết, <code>**chữ đậm**</code>, xuống dòng. Không cho phép HTML.</small></label>
        <div class="field span-2"><span>Footer đang hiển thị</span>
          <div class="footer-preview"><?= footer_html($s['footer_text']) ?></div></div>
        <label class="field"><span>Nhãn kết nối</span>
          <input type="text" name="iface_label" maxlength="60" value="<?= e($s['iface_label']) ?>" placeholder="pppoe-1">
          <small class="hint">Hiện ở thẻ tổng; cũng dùng làm tên interface trong script mẫu nếu không có dấu cách.</small></label>
        <label class="field"><span>Giao diện mặc định</span>
          <select name="default_theme">
            <?php foreach (['auto' => 'Tự động theo thiết bị', 'dark' => 'Tối', 'light' => 'Sáng'] as $v => $l): ?>
            <option value="<?= $v ?>"<?= $s['default_theme'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="hint">Người xem vẫn có thể tự đổi bằng nút mặt trời/mặt trăng.</small></label>
      </div>

      <div class="card-head sub"><div><h2><?= icon('gauge') ?>Hiển thị số liệu</h2></div></div>
      <div class="form-grid">
        <fieldset class="field">
          <legend>Đơn vị dung lượng</legend>
          <label class="check"><input type="radio" name="unit_base" value="1024"<?= $s['unit_base'] !== '1000' ? ' checked' : '' ?>><span>1 KB = 1024 byte (giống Windows, Winbox)</span></label>
          <label class="check"><input type="radio" name="unit_base" value="1000"<?= $s['unit_base'] === '1000' ? ' checked' : '' ?>><span>1 KB = 1000 byte (chuẩn SI, giống nhà mạng)</span></label>
        </fieldset>
        <label class="field"><span>Tự làm mới trang Tổng quan</span>
          <select name="refresh_sec">
            <?php foreach (['0' => 'Tắt', '15' => 'Mỗi 15 giây', '30' => 'Mỗi 30 giây', '60' => 'Mỗi 1 phút', '120' => 'Mỗi 2 phút', '300' => 'Mỗi 5 phút'] as $v => $l): ?>
            <option value="<?= $v ?>"<?= $s['refresh_sec'] === (string) $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="hint">Chỉ tải lại khi có dữ liệu mới, tạm dừng khi tab bị ẩn.</small></label>
        <label class="field"><span>Băng thông gói cước · Download (Mbps)</span>
          <input type="number" name="plan_down_mbps" min="0" step="any" value="<?= e($s['plan_down_mbps']) ?>">
          <small class="hint">Dùng làm thang đo tốc độ hiện tại. Để 0 = so với tốc độ đỉnh.</small></label>
        <label class="field"><span>Băng thông gói cước · Upload (Mbps)</span>
          <input type="number" name="plan_up_mbps" min="0" step="any" value="<?= e($s['plan_up_mbps']) ?>"></label>
        <label class="check span-2"><input type="checkbox" name="private_mode" value="1"<?= $s['private_mode'] === '1' ? ' checked' : '' ?>><span><b>Chế độ riêng tư</b> — phải đăng nhập quản trị mới xem được thống kê.</span></label>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?>Lưu cài đặt</button></div>
    </form>

<?php elseif ($tab === 'router'): ?>
    <section class="card">
      <div class="card-head"><div><h2><?= icon('activity') ?>Trạng thái nhận dữ liệu</h2></div></div>
      <dl class="info-grid">
        <div><dt>Mẫu gần nhất</dt><dd><?php if ($lastTs): ?><?= e(fmt_datetime($lastTs)) ?> <small class="muted">(<time data-ago="<?= $lastTs ?>"><?= e(rel_time($lastTs)) ?></time>)</small><?php else: ?>Chưa có<?php endif; ?></dd></div>
        <div><dt>Chu kỳ gửi</dt><dd>~<?= e(fmt_duration($typical)) ?></dd></div>
        <div><dt>IP gửi gần nhất</dt><dd><?= e($meta['last_ip'] ?: '—') ?></dd></div>
        <div><dt>Kiểu script</dt><dd><?= $meta['last_mode'] === 'counter' ? 'Bộ đếm (rxc/txc) — khuyên dùng' : ($meta['last_mode'] === 'legacy' ? 'Chênh lệch (rx/tx) — script cũ' : '—') ?></dd></div>
        <?php if ((int) $meta['negative_count'] > 0): ?>
        <div class="span-2"><dt>Giá trị âm đã bỏ qua</dt><dd><?= e(fmt_num((int) $meta['negative_count'])) ?> lần, gần nhất <?= e(fmt_datetime((int) $meta['negative_ts'])) ?> <small class="muted">(bộ đếm router bị reset — nên dùng script cải tiến bên dưới)</small></dd></div>
        <?php endif; ?>
        <?php if ($meta['counter_reset_ts']): ?>
        <div class="span-2"><dt>Bộ đếm reset gần nhất</dt><dd><?= e(fmt_datetime((int) $meta['counter_reset_ts'])) ?> <small class="muted">(đã tự xử lý)</small></dd></div>
        <?php endif; ?>
      </dl>
    </section>

    <form method="post" class="card form-card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_router">
      <div class="card-head"><div><h2><?= icon('key') ?>Endpoint &amp; khóa bảo mật</h2><p class="card-sub">Router gửi dữ liệu về địa chỉ dưới đây.</p></div></div>
      <div class="form-grid">
        <div class="field span-2"><span>Địa chỉ nhận dữ liệu (log.php)</span>
          <div class="input-group"><input type="text" readonly value="<?= e($endpoint) ?>" id="endpoint-url"><button type="button" class="btn" data-copy="#endpoint-url"><?= icon('copy') ?>Sao chép</button></div></div>
        <label class="field span-2"><span>Khóa bảo mật (tùy chọn)</span>
          <div class="input-group"><input type="text" name="log_key" id="log-key" maxlength="64" pattern="[A-Za-z0-9_\-]{8,64}" value="<?= e($s['log_key']) ?>" placeholder="Để trống = không yêu cầu khóa" autocomplete="off" spellcheck="false">
            <button type="button" class="btn" data-gen-key="#log-key"><?= icon('refresh') ?>Tạo ngẫu nhiên</button></div>
          <small class="hint">Khi đặt khóa, mọi yêu cầu tới log.php phải kèm <code>&amp;key=…</code>, tránh người lạ gửi số liệu giả. <b>Nhớ cập nhật script trên router ngay sau khi lưu.</b></small></label>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?>Lưu khóa</button></div>
    </form>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('bolt') ?>Script cải tiến <span class="badge">khuyên dùng</span></h2>
        <p class="card-sub">Gửi thẳng bộ đếm của interface, server tự tính chênh lệch: không mất số liệu khi PPPoE quay số lại hay router khởi động lại, gửi lỗi/gửi trùng cũng không bị đếm sai. Không cần biến global.</p></div>
        <div class="card-tools"><button type="button" class="btn-ghost sm" data-copy="#script-counter"><?= icon('copy') ?>Sao chép</button></div></div>
      <pre class="code" id="script-counter"><?= e(script_counter($endpoint, $iface, $s['log_key'])) ?></pre>
    </section>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('router') ?>Script hiện tại của bạn (vẫn dùng được)</h2>
        <p class="card-sub">log.php vẫn nhận kiểu <code>?rx=…&amp;tx=…</code> như cũ. Bản dưới đây chỉ thêm 2 dòng xử lý khi bộ đếm bị reset<?= $s['log_key'] !== '' ? ' và tham số <code>key</code>' : '' ?>.</p></div>
        <div class="card-tools"><button type="button" class="btn-ghost sm" data-copy="#script-legacy"><?= icon('copy') ?>Sao chép</button></div></div>
      <pre class="code" id="script-legacy"><?= e(script_legacy($endpoint, $iface, $s['log_key'])) ?></pre>
    </section>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('clock') ?>Cài đặt chạy định kỳ</h2></div>
        <div class="card-tools"><button type="button" class="btn-ghost sm" data-copy="#script-scheduler"><?= icon('copy') ?>Sao chép lệnh</button></div></div>
      <ol class="steps">
        <li>Winbox › <b>System › Scripts</b> › thêm script tên <code>net-traffic</code>, quyền <code>read, write, policy, test</code>, dán một trong hai script trên.</li>
        <li><b>System › Scheduler</b> › thêm lịch chạy mỗi 1 phút (hoặc dán lệnh dưới vào Terminal):</li>
      </ol>
      <pre class="code wrap" id="script-scheduler">/system scheduler add name=net-traffic interval=1m on-event="/system script run net-traffic" policy=read,write,policy,test</pre>
      <p class="muted small">Chỉ dùng <b>một</b> trong hai script. Khi chuyển sang script cải tiến, lần gửi đầu tiên chỉ ghi mốc bộ đếm (0 byte), từ lần sau số liệu được tính bình thường.</p>
    </section>

<?php elseif ($tab === 'data'):
    $counts = [
        'samples' => (int) db_value($db, 'SELECT COUNT(*) FROM samples'),
        'hourly' => (int) db_value($db, 'SELECT COUNT(*) FROM hourly'),
        'daily' => (int) db_value($db, 'SELECT COUNT(*) FROM daily'),
    ];
    $oldest = db_value($db, 'SELECT MIN(ts) FROM samples');
    $firstDay = db_value($db, 'SELECT MIN(d) FROM daily');
    clearstatcache();
    $size = is_file(DB_PATH) ? (int) filesize(DB_PATH) : 0;
    ?>
    <section class="card">
      <div class="card-head"><div><h2><?= icon('database') ?>Cơ sở dữ liệu SQLite</h2><p class="card-sub">Toàn bộ dữ liệu nằm trong một file duy nhất.</p></div></div>
      <dl class="info-grid">
        <div class="span-2"><dt>File</dt><dd><code><?= e(strpos(DB_PATH, MTS_ROOT . '/') === 0 ? substr(DB_PATH, strlen(MTS_ROOT) + 1) : DB_PATH) ?></code></dd></div>
        <div><dt>Dung lượng file</dt><dd><?= e(fmt_bytes($size, 1024)) ?></dd></div>
        <div><dt>Phiên bản</dt><dd>SQLite <?= e((string) db_value($db, 'SELECT sqlite_version()')) ?> · PHP <?= e(PHP_VERSION) ?></dd></div>
        <div><dt>Mẫu chi tiết</dt><dd><?= e(fmt_num($counts['samples'])) ?> dòng<?= $oldest !== null ? ' · từ ' . e(fmt_datetime((int) $oldest)) : '' ?></dd></div>
        <div><dt>Tổng hợp theo giờ / ngày</dt><dd><?= e(fmt_num($counts['hourly'])) ?> / <?= e(fmt_num($counts['daily'])) ?> dòng<?= $firstDay !== null ? ' · từ ' . e(fmt_day((string) $firstDay)) : '' ?></dd></div>
      </dl>
    </section>

    <form method="post" class="card form-card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_data">
      <div class="card-head"><div><h2><?= icon('clock') ?>Thời gian giữ mẫu chi tiết</h2>
        <p class="card-sub">Mẫu chi tiết dùng cho biểu đồ tốc độ. Số liệu tổng hợp theo giờ/ngày (và tổng toàn thời gian) luôn được giữ vĩnh viễn.</p></div></div>
      <div class="form-grid">
        <label class="field"><span>Giữ mẫu chi tiết (ngày)</span>
          <input type="number" name="raw_retention_days" min="1" max="3650" value="<?= e($s['raw_retention_days']) ?>">
          <small class="hint">Gửi mỗi phút ≈ 1.440 dòng/ngày (~60 KB). Mặc định 30 ngày.</small></label>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?>Lưu</button></div>
    </form>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('file') ?>Xuất dữ liệu (CSV)</h2><p class="card-sub">Mở được bằng Excel / Google Sheets.</p></div></div>
      <div class="btn-row">
        <a class="btn" href="api.php?a=csv&amp;range=all&amp;g=day"><?= icon('file') ?>Theo ngày (toàn bộ)</a>
        <a class="btn" href="api.php?a=csv&amp;range=all&amp;g=month"><?= icon('file') ?>Theo tháng (toàn bộ)</a>
        <a class="btn" href="api.php?a=csv&amp;range=30d&amp;g=hour"><?= icon('file') ?>Theo giờ (30 ngày)</a>
        <a class="btn" href="api.php?a=raw"><?= icon('file') ?>Mẫu chi tiết</a>
      </div>
    </section>

    <form method="post" class="card form-card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="vacuum">
      <div class="card-head"><div><h2><?= icon('refresh') ?>Tối ưu cơ sở dữ liệu</h2><p class="card-sub">Chạy VACUUM để thu gọn file sau khi xóa nhiều dữ liệu. Không làm mất dữ liệu.</p></div></div>
      <div class="form-actions"><button type="submit" class="btn"><?= icon('refresh') ?>Tối ưu ngay</button></div>
    </form>

    <form method="post" class="card form-card danger-zone" data-confirm="Xóa TOÀN BỘ dữ liệu lưu lượng? Không thể hoàn tác.">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="reset_data">
      <div class="card-head"><div><h2><?= icon('trash') ?>Xóa toàn bộ dữ liệu lưu lượng</h2><p class="card-sub">Xóa mọi mẫu và số liệu tổng hợp (kể cả tổng toàn thời gian). Cài đặt và tài khoản được giữ nguyên.</p></div></div>
      <div class="form-grid">
        <label class="field"><span>Gõ <b>XOA</b> để xác nhận</span><input type="text" name="confirm" maxlength="10" autocomplete="off" required></label>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-danger"><?= icon('trash') ?>Xóa dữ liệu</button></div>
    </form>

<?php else: ?>
    <form method="post" class="card form-card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_account">
      <div class="card-head"><div><h2><?= icon('user') ?>Tài khoản quản trị</h2><p class="card-sub">Sau khi lưu, các thiết bị khác đang đăng nhập sẽ bị đăng xuất.</p></div></div>
      <div class="form-grid">
        <label class="field"><span>Tên đăng nhập</span>
          <input type="text" name="username" required maxlength="32" value="<?= e($user) ?>" autocomplete="username"></label>
        <label class="field"><span>Mật khẩu hiện tại</span>
          <input type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="field"><span>Mật khẩu mới</span>
          <input type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Để trống nếu không đổi"></label>
        <label class="field"><span>Nhập lại mật khẩu mới</span>
          <input type="password" name="new_password2" minlength="8" autocomplete="new-password"></label>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?>Cập nhật tài khoản</button></div>
    </form>
    <section class="card">
      <div class="card-head"><div><h2><?= icon('info') ?>Quên mật khẩu?</h2></div></div>
      <p class="muted">Mở <code>config.php</code>, đặt <code>ALLOW_ADMIN_RESET</code> thành <code>true</code>, vào lại trang này để tạo tài khoản mới (dữ liệu được giữ nguyên), rồi đặt lại thành <code>false</code>.</p>
    </section>
<?php endif; ?>
  </div>
</div>
<?php
page_end($s, ['hide_admin_link' => true]);
