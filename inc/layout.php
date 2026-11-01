<?php
/**
 * Khung trang dùng chung (header, footer).
 */
defined('MTS') || exit;

function brand_mark(): string
{
    return '<svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="bm-g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#7b6cf6"/><stop offset="1" stop-color="#2f8cf0"/></linearGradient></defs>'
        . '<rect width="32" height="32" rx="9" fill="url(#bm-g)"/>'
        . '<path d="M11.5 8.5v13m-4.2-4.2 4.2 4.2 4.2-4.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M20.5 23.5v-13m-4.2 4.2 4.2-4.2 4.2 4.2" fill="none" stroke="#fff" stroke-opacity=".72" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

/**
 * @param array $opt title, active, body_class, noindex, status (['lastTs'=>, 'typical'=>]|null)
 */
function page_start(array $s, array $opt = []): void
{
    $title = $opt['title'] ?? '';
    $active = $opt['active'] ?? '';
    $fullTitle = ($title !== '' ? $title . ' · ' : '') . $s['site_name'];
    $theme = in_array($s['default_theme'], ['light', 'dark'], true) ? $s['default_theme'] : 'auto';
    $now = time();

    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, private');
    ?><!doctype html>
<html lang="vi" data-default-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($fullTitle) ?></title>
<?php if ($s['site_tagline'] !== ''): ?>
<meta name="description" content="<?= e($s['site_tagline']) ?>">
<?php endif; ?>
<?php if (!empty($opt['noindex'])): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="theme-color" content="#f3f5fa" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0f1c" media="(prefers-color-scheme: dark)">
<link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&amp;display=swap">
<link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
<script>(function(){var d=document.documentElement,t=null;try{t=localStorage.getItem('nt-theme')}catch(e){}t=t||d.getAttribute('data-default-theme');if(t==='light'||t==='dark'){d.setAttribute('data-theme',t)}})();</script>
</head>
<body class="<?= e($opt['body_class'] ?? '') ?>" data-server-now="<?= $now ?>" data-tz-offset="<?= (int) date('Z', $now) ?>">
<a class="skip-link" href="#main">Bỏ qua tới nội dung</a>
<div class="bg-decor" aria-hidden="true"></div>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="./">
      <?= brand_mark() ?>
      <span class="brand-text">
        <span class="brand-name"><?= e($s['site_name']) ?></span>
        <?php if ($s['site_tagline'] !== ''): ?><span class="brand-tag"><?= e($s['site_tagline']) ?></span><?php endif; ?>
      </span>
    </a>
    <?php if (empty($opt['hide_nav'])): ?>
    <nav class="nav" aria-label="Điều hướng chính">
      <a href="./"<?= $active === 'overview' ? ' class="active" aria-current="page"' : '' ?>><?= icon('dashboard') ?><span>Tổng quan</span></a>
      <a href="./?view=history"<?= $active === 'history' ? ' class="active" aria-current="page"' : '' ?>><?= icon('history') ?><span>Lịch sử</span></a>
      <?php if (!empty($opt['is_admin'])): ?>
      <a href="admin.php"<?= $active === 'admin' ? ' class="active" aria-current="page"' : '' ?>><?= icon('sliders') ?><span>Quản trị</span></a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <div class="topbar-tools">
      <?php if (isset($opt['status']) && is_array($opt['status'])): ?>
      <span class="status-pill" id="status-pill" data-last-ts="<?= (int) ($opt['status']['lastTs'] ?? 0) ?>" data-typical="<?= (int) ($opt['status']['typical'] ?? 60) ?>">
        <span class="status-dot" aria-hidden="true"></span><span class="status-text">Đang kiểm tra…</span>
      </span>
      <?php endif; ?>
      <span class="clock" id="clock" title="Giờ Việt Nam (GMT+7)"><span class="clock-date"><?= e(VN_WEEKDAYS[(int) date('w', $now)] . ', ' . date('d/m/Y', $now)) ?></span><span class="clock-time"><?= e(date('H:i:s', $now)) ?></span></span>
      <button type="button" class="icon-btn" id="theme-toggle" aria-label="Đổi giao diện sáng/tối" title="Đổi giao diện sáng/tối">
        <?= icon('sun', 'icon icon-sun') ?><?= icon('moon', 'icon icon-moon') ?>
      </button>
    </div>
  </div>
</header>
<main id="main" class="wrap main">
<?php
}

function page_end(array $s, array $opt = []): void
{
    ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-inner">
    <div class="footer-text"><?= footer_html($s['footer_text']) ?></div>
    <div class="footer-meta">
      <span><?= icon('globe') ?>Giờ Việt Nam (GMT+7)</span>
      <?php if (empty($opt['hide_admin_link'])): ?>
      <a href="admin.php"><?= icon('lock') ?>Quản trị</a>
      <?php endif; ?>
    </div>
  </div>
</footer>
<div class="tooltip" id="tooltip" role="status" aria-live="polite" hidden></div>
<script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Trang báo lỗi gọn (ví dụ: không ghi được file SQLite). */
function fatal_page(string $title, string $message): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title><style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#0a0f1c;color:#e8edf6;'
        . 'display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}main{max-width:560px;background:#121a2b;border:1px solid #24304a;'
        . 'border-radius:16px;padding:28px}h1{font-size:20px;margin:0 0 12px}p{color:#a9b4c7;line-height:1.6}code{background:#0a0f1c;padding:2px 6px;border-radius:6px}</style>'
        . '</head><body><main><h1>' . e($title) . '</h1><p>' . e($message) . '</p></main></body></html>';
    exit;
}
