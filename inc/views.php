<?php
/**
 * Các khối giao diện được render phía server. Dùng chung cho index.php
 * (lần tải đầu) và api.php (tự làm mới mà không cần tải lại trang).
 */
defined('MTS') || exit;

function value_html(array $parts, string $class = 'num'): string
{
    return '<span class="' . e($class) . '">' . e($parts[0]) . '</span><span class="unit">' . e($parts[1]) . '</span>';
}

/** Thanh tỉ lệ Download/Upload. $tall = có in % bên trong. */
function split_bar(int $rx, int $tx, bool $tall = false): string
{
    $total = $rx + $tx;
    if ($total <= 0) {
        return '<div class="split-bar ' . ($tall ? 'tall' : 'thin') . ' is-empty" aria-hidden="true"></div>';
    }
    $pr = $rx / $total;
    $pt = $tx / $total;
    $label = 'Download ' . fmt_percent($pr, 1) . ', Upload ' . fmt_percent($pt, 1);
    $html = '<div class="split-bar ' . ($tall ? 'tall' : 'thin') . '" role="img" aria-label="' . e($label) . '">';
    foreach ([['dl', $pr, $rx], ['ul', $pt, $tx]] as $seg) {
        if ($seg[2] <= 0) {
            continue;
        }
        $html .= '<span class="seg ' . $seg[0] . '" style="flex-grow:' . round($seg[1] * 1000, 2) . '">';
        if ($tall) {
            $html .= '<span class="seg-label">' . e(fmt_percent($seg[1], $seg[1] < 0.1 ? 1 : 0)) . '</span>';
        }
        $html .= '</span>';
    }
    return $html . '</div>';
}

function delta_html(int $cur, int $cmp, string $label): string
{
    if ($cmp <= 0) {
        return '<div class="kpi-delta is-none"><span>Chưa có dữ liệu để so sánh</span></div>';
    }
    $ratio = ($cur - $cmp) / $cmp;
    if (abs($ratio) < 0.005) {
        $arrow = '≈';
        $cls = 'flat';
    } elseif ($ratio > 0) {
        $arrow = '▲';
        $cls = 'up';
    } else {
        $arrow = '▼';
        $cls = 'down';
    }
    $pct = abs($ratio) >= 10 ? fmt_num(abs($ratio) * 100, 0) : fmt_num(abs($ratio) * 100, abs($ratio) < 0.1 ? 1 : 0);
    return '<div class="kpi-delta ' . $cls . '" title="' . e('Kỳ so sánh: ' . fmt_bytes($cmp)) . '">'
        . '<b>' . $arrow . ' ' . e($pct) . '%</b> <span>' . e($label) . '</span></div>';
}

function legend_html(bool $lines = false): string
{
    $k = $lines ? 'lk' : 'sw';
    return '<div class="legend"><span class="key"><i class="' . $k . ' dl"></i>Download</span>'
        . '<span class="key"><i class="' . $k . ' ul"></i>Upload</span></div>';
}

/* ---------- Trang Tổng quan ---------- */

function view_hero(array $d, array $s): string
{
    $b = $d['unitBase'];
    $a = $d['allTime'];
    $total = $a['rx'] + $a['tx'];
    ob_start(); ?>
<div class="hero-head">
  <span class="eyebrow"><?= icon('sigma') ?>Tổng lưu lượng toàn thời gian</span>
  <?php if ($s['iface_label'] !== ''): ?><span class="chip"><?= icon('router') ?><?= e($s['iface_label']) ?></span><?php endif; ?>
</div>
<div class="hero-value" data-count="<?= $total ?>" data-fmt="bytes"><?= value_html(bytes_parts($total, $b)) ?></div>
<p class="hero-sub">
  <?php if ($a['since']): ?>
  Kể từ <b><?= e(fmt_day($a['since'])) ?></b> · <?= e(fmt_num($a['spanDays'])) ?> ngày · <?= e(fmt_num($a['samples'])) ?> mẫu dữ liệu
  <?php else: ?>
  Chưa có dữ liệu — hãy cấu hình router gửi về <code>log.php</code>.
  <?php endif; ?>
</p>
<?= split_bar($a['rx'], $a['tx'], true) ?>
<?= legend_html() ?>
<?php if (count($a['years']) > 0):
        $ymax = max(array_map(function ($y) {
            return $y[1] + $y[2];
        }, $a['years'])); ?>
<div class="year-list" aria-label="Lưu lượng theo năm">
  <?php foreach ($a['years'] as $y):
        $yt = $y[1] + $y[2]; ?>
  <div class="year-row" tabindex="0" data-tip="<?= e(json_encode(['Năm ' . $y[0], fmt_bytes($y[1], $b), fmt_bytes($y[2], $b), fmt_bytes($yt, $b)], JSON_UNESCAPED_UNICODE)) ?>">
    <span class="year-name">Năm <?= e($y[0]) ?></span>
    <span class="rank-track"><span class="rank-bar" style="width:<?= $ymax > 0 ? round($yt / $ymax * 100, 2) : 0 ?>%"><span class="seg dl" style="flex-grow:<?= $y[1] ?>"></span><span class="seg ul" style="flex-grow:<?= $y[2] ?>"></span></span></span>
    <span class="year-val"><?= e(fmt_bytes($yt, $b)) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<dl class="hero-stats">
  <div><dt><?= icon('download') ?>Download</dt><dd><?= e(fmt_bytes($a['rx'], $b)) ?></dd></div>
  <div><dt><?= icon('upload') ?>Upload</dt><dd><?= e(fmt_bytes($a['tx'], $b)) ?></dd></div>
  <div><dt><?= icon('calendar') ?>Trung bình / ngày</dt><dd><?= e(fmt_bytes($a['avgDay'], $b)) ?></dd></div>
  <div><dt><?= icon('trophy') ?>Ngày cao nhất</dt><dd><?php if ($a['best']): ?><?= e(fmt_bytes($a['best'][1] + $a['best'][2], $b)) ?> <small><?= e(fmt_day($a['best'][0])) ?></small><?php else: ?>—<?php endif; ?></dd></div>
  <div><dt><?= icon('bolt') ?>Tốc độ đỉnh</dt><dd><?php if ($a['peakRx']): ?><?= e(fmt_rate($a['peakRx'][1])) ?> <small>↓ <?= e(fmt_day($a['peakRx'][0])) ?></small><?php else: ?>—<?php endif; ?></dd></div>
</dl>
<?php
    return (string) ob_get_clean();
}

function view_live(array $d): string
{
    $cur = $d['current'];
    $online = $d['status']['online'];
    $peak = $d['todayPeak'];
    ob_start(); ?>
<div class="card-head">
  <div>
    <h2><?= icon('gauge') ?>Tốc độ hiện tại</h2>
    <p class="card-sub">Trung bình trong chu kỳ gửi gần nhất<?= $cur ? ' (' . e(fmt_duration($cur['dur'])) . ')' : '' ?></p>
  </div>
</div>
<?php foreach (['rx' => ['dl', 'Download', 'download'], 'tx' => ['ul', 'Upload', 'upload']] as $k => $m):
        $val = $cur && $online ? (float) $cur[$k] : 0.0;
        $plan = (float) $d['plan'][$k];
        $allPeak = $d['allTime'][$k === 'rx' ? 'peakRx' : 'peakTx'];
        $max = $plan > 0 ? $plan : max($val, $allPeak ? (float) $allPeak[1] : 0.0);
        $pct = $max > 0 ? min(1, $val / $max) : 0;
        ?>
<div class="live-row">
  <div class="live-top">
    <span class="live-label"><i class="sw <?= $m[0] ?>"></i><?= icon($m[2]) ?><?= $m[1] ?></span>
    <span class="live-value"><?php if ($cur && $online): ?><?= value_html(rate_parts($val)) ?><?php else: ?><span class="num">—</span><?php endif; ?></span>
  </div>
  <div class="meter <?= $m[0] ?>" role="meter" aria-label="<?= e($m[1]) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($pct * 100) ?>">
    <span style="width:<?= round($pct * 100, 2) ?>%"></span>
  </div>
  <div class="live-foot">
    <span><?= $max > 0 ? e(fmt_percent($pct)) . ($plan > 0 ? ' gói ' . e(fmt_rate($plan)) : ' so với đỉnh ' . e(fmt_rate($max))) : 'Chưa có dữ liệu tốc độ' ?></span>
    <?php if ($peak[$k . 'Ts']): ?><span>Đỉnh hôm nay <b><?= e(fmt_rate($peak[$k])) ?></b> lúc <?= e(date('H:i', $peak[$k . 'Ts'])) ?></span><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php
    return (string) ob_get_clean();
}

function view_live_foot(array $d): string
{
    ob_start(); ?>
<?= icon('clock') ?>
<?php if ($d['status']['lastTs']): ?>
<span>Mẫu gần nhất lúc <b><?= e(date('H:i:s', $d['status']['lastTs'])) ?></b> · <time data-ago="<?= (int) $d['status']['lastTs'] ?>"><?= e(rel_time($d['status']['lastTs'], $d['now'])) ?></time> · router gửi mỗi ~<?= e(fmt_duration($d['status']['typical'])) ?></span>
<?php else: ?>
<span>Chưa nhận được mẫu nào từ router.</span>
<?php endif; ?>
<?php
    return (string) ob_get_clean();
}

function view_kpis(array $d): string
{
    $b = $d['unitBase'];
    $icons = ['today' => 'clock', 'yesterday' => 'history', '7d' => 'calendar', 'month' => 'calendar', 'last_month' => 'calendar', 'year' => 'chart'];
    ob_start();
    foreach ($d['kpis'] as $k):
        $total = $k['rx'] + $k['tx']; ?>
<article class="card kpi">
  <div class="kpi-label"><?= icon($icons[$k['key']] ?? 'calendar') ?><?= e($k['label']) ?></div>
  <div class="kpi-value" data-count="<?= $total ?>" data-fmt="bytes"><?= value_html(bytes_parts($total, $b)) ?></div>
  <?= split_bar($k['rx'], $k['tx']) ?>
  <div class="kpi-split">
    <span title="Download"><i class="sw dl"></i><?= e(fmt_bytes($k['rx'], $b)) ?></span>
    <span title="Upload"><i class="sw ul"></i><?= e(fmt_bytes($k['tx'], $b)) ?></span>
  </div>
  <?= delta_html($total, $k['cmp'], $k['cmpLabel']) ?>
</article>
<?php endforeach;
    return (string) ob_get_clean();
}

/** Danh sách xếp hạng: thanh ngang chồng Download/Upload trên cùng một trục. */
function view_top(array $d): string
{
    $b = $d['unitBase'];
    $top = $d['top'];
    if (!$top) {
        return '<p class="empty">Chưa có dữ liệu.</p>';
    }
    $max = max(array_map(function ($r) {
        return $r[1] + $r[2];
    }, $top));
    ob_start(); ?>
<ol class="rank-list">
<?php foreach ($top as $i => $r):
        $t = $r[1] + $r[2];
        $w = $max > 0 ? $t / $max * 100 : 0; ?>
  <li tabindex="0" data-tip="<?= e(json_encode([fmt_day($r[0], true), fmt_bytes($r[1], $b), fmt_bytes($r[2], $b), fmt_bytes($t, $b)], JSON_UNESCAPED_UNICODE)) ?>">
    <span class="rank-no"><?= $i + 1 ?></span>
    <span class="rank-date"><?= e(VN_WEEKDAYS_SHORT[(int) date('w', ymd_ts($r[0]))]) ?>, <?= e(fmt_day($r[0])) ?></span>
    <span class="rank-track"><span class="rank-bar" style="width:<?= round($w, 2) ?>%"><span class="seg dl" style="flex-grow:<?= $r[1] ?>"></span><span class="seg ul" style="flex-grow:<?= $r[2] ?>"></span></span></span>
    <span class="rank-val"><?= e(fmt_bytes($t, $b)) ?></span>
  </li>
<?php endforeach; ?>
</ol>
<?php
    return (string) ob_get_clean();
}

function view_recent(array $d): string
{
    $b = $d['unitBase'];
    if (!$d['recent']) {
        return '<p class="empty">Chưa nhận được mẫu nào từ router.</p>';
    }
    ob_start(); ?>
<div class="table-wrap">
<table class="data-table">
  <thead><tr>
    <th scope="col">Thời điểm</th>
    <th scope="col" class="n"><i class="sw dl"></i>Download</th>
    <th scope="col" class="n"><i class="sw ul"></i>Upload</th>
    <th scope="col" class="n hide-sm">Tốc độ TB ↓</th>
    <th scope="col" class="n hide-sm">Tốc độ TB ↑</th>
    <th scope="col" class="n">Chu kỳ</th>
  </tr></thead>
  <tbody>
<?php foreach ($d['recent'] as $r):
        $dur = $r[3]; ?>
    <tr>
      <td><b><?= e(date('H:i:s', $r[0])) ?></b> <span class="muted"><?= e(date('d/m', $r[0])) ?></span></td>
      <td class="n"><?= e(fmt_bytes($r[1], $b)) ?></td>
      <td class="n"><?= e(fmt_bytes($r[2], $b)) ?></td>
      <td class="n hide-sm"><?= $dur >= RATE_MIN_DUR ? e(fmt_rate($r[1] * 8 / $dur)) : '—' ?></td>
      <td class="n hide-sm"><?= $dur >= RATE_MIN_DUR ? e(fmt_rate($r[2] * 8 / $dur)) : '—' ?></td>
      <td class="n muted"><?= $dur > 0 ? e(fmt_duration($dur)) : 'mẫu đầu' ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
    return (string) ob_get_clean();
}

/** Các khối render lại khi tự làm mới. */
function overview_sections(array $d, array $s): array
{
    return [
        'hero' => view_hero($d, $s),
        'live' => view_live($d),
        'livefoot' => view_live_foot($d),
        'kpis' => view_kpis($d),
        'top' => view_top($d),
        'recent' => view_recent($d),
    ];
}

/** Dữ liệu biểu đồ gửi xuống trình duyệt (bỏ những phần đã render sẵn). */
function overview_chart_payload(array $d): array
{
    return [
        'now' => $d['now'],
        'tzOffset' => $d['tzOffset'],
        'today' => $d['today'],
        'unitBase' => $d['unitBase'],
        'status' => $d['status'],
        'speed' => $d['speed'],
        'live' => $d['live'],
        'hours' => $d['hours'],
        'daily' => $d['daily'],
        'months' => $d['months'],
        'calendar' => $d['calendar'],
        'pattern' => $d['pattern'],
    ];
}
