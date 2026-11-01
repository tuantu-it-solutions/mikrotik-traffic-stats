<?php
/**
 * Trang thống kê: Tổng quan (mặc định) và Lịch sử (?view=history).
 */
define('MTS', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/stats.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/views.php';

try {
    $db = db();
    $s = settings($db);
} catch (Throwable $e) {
    fatal_page('Không mở được cơ sở dữ liệu', $e->getMessage());
}

$isAdmin = auth_user($db) !== null;
if ($s['private_mode'] === '1' && !$isAdmin) {
    header('Location: admin.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '')));
    exit;
}

$now = time();
$view = ($_GET['view'] ?? '') === 'history' ? 'history' : 'overview';
$lastTs = meta_get($db, 'last_ts');
$status = ['lastTs' => $lastTs !== null ? (int) $lastTs : null, 'typical' => typical_interval($db)];

if ($view === 'overview') {
    $d = overview_data($db, $s, $now);
    $sec = overview_sections($d, $s);
    $config = [
        'page' => 'overview',
        'refresh' => max(0, (int) $s['refresh_sec']),
        'rev' => data_rev($db, $now),
        'data' => overview_chart_payload($d),
    ];
    page_start($s, ['title' => 'Tổng quan', 'active' => 'overview', 'body_class' => 'page-overview', 'status' => $status, 'is_admin' => $isAdmin]);
    $empty = $d['allTime']['samples'] === 0 && !$d['recent']; ?>

<?php if ($empty): ?>
<section class="card notice">
  <?= icon('router', 'icon notice-icon') ?>
  <div>
    <h2>Đang chờ dữ liệu từ router</h2>
    <p>Trang đã sẵn sàng nhưng chưa nhận được mẫu nào. Hãy để script MikroTik gửi dữ liệu về
      <code><?= e(base_url()) ?>log.php</code>. Xem hướng dẫn và script mẫu trong trang <a href="admin.php?tab=router">Quản trị › Router</a>.</p>
  </div>
</section>
<?php endif; ?>

<div class="grid grid-hero">
  <section class="card hero" data-section="hero" aria-label="Tổng lưu lượng toàn thời gian"><?= $sec['hero'] ?></section>
  <section class="card live" aria-label="Tốc độ hiện tại">
    <div data-section="live"><?= $sec['live'] ?></div>
    <div class="live-spark">
      <div class="live-spark-head"><span>60 phút qua</span><?= legend_html(true) ?></div>
      <div class="chart" id="chart-live" data-height="92"></div>
    </div>
    <div class="live-updated" data-section="livefoot"><?= $sec['livefoot'] ?></div>
  </section>
</div>

<section class="kpi-grid" data-section="kpis" aria-label="Lưu lượng theo kỳ"><?= $sec['kpis'] ?></section>

<section class="card chart-card">
  <div class="card-head">
    <div>
      <h2><?= icon('activity') ?>Tốc độ 24 giờ qua</h2>
      <p class="card-sub" id="speed-sub">Tốc độ trung bình theo từng khoảng thời gian · rê chuột để xem chi tiết</p>
    </div>
    <div class="card-tools"><?= legend_html(true) ?><button type="button" class="btn-ghost sm" data-table-toggle="chart-speed"><?= icon('table') ?>Bảng</button></div>
  </div>
  <div class="chart" id="chart-speed" data-height="280"></div>
</section>

<div class="grid grid-2">
  <section class="card chart-card">
    <div class="card-head">
      <div>
        <h2><?= icon('clock') ?>Theo giờ · hôm nay</h2>
        <p class="card-sub"><?= e(VN_WEEKDAYS[(int) date('w', $now)] . ', ' . date('d/m/Y', $now)) ?></p>
      </div>
      <div class="card-tools"><?= legend_html() ?><button type="button" class="btn-ghost sm" data-table-toggle="chart-hours"><?= icon('table') ?>Bảng</button></div>
    </div>
    <div class="chart" id="chart-hours" data-height="240" data-fill style="min-height:240px"></div>
  </section>

  <section class="card chart-card">
    <div class="card-head">
      <div>
        <h2><?= icon('grid') ?>Thói quen sử dụng</h2>
        <p class="card-sub">Lưu lượng trung bình mỗi giờ theo thứ trong tuần · 8 tuần gần nhất</p>
      </div>
      <div class="card-tools"><button type="button" class="btn-ghost sm" data-table-toggle="chart-pattern"><?= icon('table') ?>Bảng</button></div>
    </div>
    <div class="chart" id="chart-pattern"></div>
  </section>
</div>

<section class="card chart-card">
  <div class="card-head">
    <div>
      <h2><?= icon('calendar') ?>30 ngày gần nhất</h2>
      <p class="card-sub">Tổng lưu lượng mỗi ngày</p>
    </div>
    <div class="card-tools"><?= legend_html() ?><button type="button" class="btn-ghost sm" data-table-toggle="chart-daily"><?= icon('table') ?>Bảng</button></div>
  </div>
  <div class="chart" id="chart-daily" data-height="260"></div>
</section>

<div class="grid grid-2">
  <section class="card chart-card">
    <div class="card-head">
      <div>
        <h2><?= icon('chart') ?>12 tháng gần nhất</h2>
        <p class="card-sub">Tổng lưu lượng mỗi tháng</p>
      </div>
      <div class="card-tools"><?= legend_html() ?><button type="button" class="btn-ghost sm" data-table-toggle="chart-months"><?= icon('table') ?>Bảng</button></div>
    </div>
    <div class="chart" id="chart-months" data-height="260" data-fill style="min-height:260px"></div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2><?= icon('trophy') ?>Top 10 ngày dùng nhiều nhất</h2>
        <p class="card-sub">Toàn thời gian</p>
      </div>
      <div class="card-tools"><?= legend_html() ?></div>
    </div>
    <div data-section="top"><?= $sec['top'] ?></div>
  </section>
</div>

<section class="card chart-card">
  <div class="card-head">
    <div>
      <h2><?= icon('grid') ?>Lịch sử dụng 12 tháng</h2>
      <p class="card-sub">Mỗi ô là một ngày · màu càng đậm dùng càng nhiều</p>
    </div>
    <div class="card-tools"><button type="button" class="btn-ghost sm" data-table-toggle="chart-calendar"><?= icon('table') ?>Bảng</button></div>
  </div>
  <div class="chart" id="chart-calendar"></div>
</section>

<section class="card">
  <div class="card-head">
    <div>
      <h2><?= icon('router') ?>Dữ liệu router gửi gần đây</h2>
      <p class="card-sub">12 mẫu mới nhất nhận qua log.php</p>
    </div>
  </div>
  <div data-section="recent"><?= $sec['recent'] ?></div>
</section>

<script type="application/json" id="app-config"><?= json_script($config) ?></script>
<?php
    page_end($s);
    exit;
}

/* ---------------- Lịch sử ---------------- */

$firstDay = db_value($db, 'SELECT MIN(d) FROM daily');
$firstDay = $firstDay !== null ? (string) $firstDay : null;
$preset = isset($_GET['range']) && is_string($_GET['range']) ? $_GET['range'] : '';
if ($preset === '' && !isset($_GET['from'])) {
    $preset = '30d';
}
list($from, $to, $preset) = resolve_range($preset, $_GET['from'] ?? null, $_GET['to'] ?? null, $firstDay, $now);
$h = history_data($db, $s, $from, $to, $now);
$b = $h['unitBase'];
$granLabel = ['hour' => 'theo giờ', 'day' => 'theo ngày', 'month' => 'theo tháng', 'year' => 'theo năm'][$h['gran']];
$t = $h['totals'];
$total = $t['rx'] + $t['tx'];
$config = ['page' => 'history', 'refresh' => 0, 'data' => $h];
$csvUrl = 'api.php?a=csv&from=' . rawurlencode($from) . '&to=' . rawurlencode($to);

page_start($s, ['title' => 'Lịch sử', 'active' => 'history', 'body_class' => 'page-history', 'status' => $status, 'is_admin' => $isAdmin]);
?>

<section class="card filter-card" aria-label="Chọn khoảng thời gian">
  <div class="presets" role="group" aria-label="Khoảng thời gian có sẵn">
    <?php foreach (history_presets() as $key => $label): ?>
    <a class="chip-btn<?= $preset === $key ? ' active' : '' ?>" href="?view=history&amp;range=<?= e($key) ?>"<?= $preset === $key ? ' aria-current="true"' : '' ?>><?= $preset === $key ? icon('check') : '' ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="custom-range" method="get" action="./">
    <input type="hidden" name="view" value="history">
    <label><span>Từ ngày</span><input type="date" name="from" value="<?= e($from) ?>" required></label>
    <label><span>Đến ngày</span><input type="date" name="to" value="<?= e($to) ?>" required></label>
    <button type="submit" class="btn btn-primary"><?= icon('calendar') ?>Xem</button>
  </form>
</section>

<div class="range-head">
  <h1>
    <?php if ($from === $to): ?><?= e(fmt_day($from, true)) ?><?php else: ?><?= e(fmt_day($from)) ?> – <?= e(fmt_day($to)) ?><?php endif; ?>
  </h1>
  <p><?= e(fmt_num($h['days'])) ?> ngày · biểu đồ <?= e($granLabel) ?><?= $preset !== 'custom' ? ' · ' . e(history_presets()[$preset]) : '' ?></p>
</div>

<section class="kpi-grid kpi-grid-history" aria-label="Tổng hợp khoảng thời gian">
  <article class="card kpi kpi-accent">
    <div class="kpi-label"><?= icon('sigma') ?>Tổng lưu lượng</div>
    <div class="kpi-value" data-count="<?= $total ?>" data-fmt="bytes"><?= value_html(bytes_parts($total, $b)) ?></div>
    <?= split_bar($t['rx'], $t['tx']) ?>
    <div class="kpi-split"><span><i class="sw dl"></i><?= e($total ? fmt_percent($t['rx'] / $total) : '0%') ?></span><span><i class="sw ul"></i><?= e($total ? fmt_percent($t['tx'] / $total) : '0%') ?></span></div>
  </article>
  <article class="card kpi">
    <div class="kpi-label"><?= icon('download') ?>Download</div>
    <div class="kpi-value" data-count="<?= $t['rx'] ?>" data-fmt="bytes"><?= value_html(bytes_parts($t['rx'], $b)) ?></div>
    <div class="kpi-note">Tốc độ đỉnh <b><?= e(fmt_rate($t['peakRx'])) ?></b></div>
  </article>
  <article class="card kpi">
    <div class="kpi-label"><?= icon('upload') ?>Upload</div>
    <div class="kpi-value" data-count="<?= $t['tx'] ?>" data-fmt="bytes"><?= value_html(bytes_parts($t['tx'], $b)) ?></div>
    <div class="kpi-note">Tốc độ đỉnh <b><?= e(fmt_rate($t['peakTx'])) ?></b></div>
  </article>
  <article class="card kpi">
    <div class="kpi-label"><?= icon('calendar') ?>Trung bình / ngày</div>
    <div class="kpi-value"><?= value_html(bytes_parts($t['avgDay'], $b)) ?></div>
    <div class="kpi-note"><?= e(fmt_num($h['effDays'])) ?> ngày có tính · <?= e(fmt_num($t['samples'])) ?> mẫu</div>
  </article>
  <article class="card kpi">
    <div class="kpi-label"><?= icon('trophy') ?>Ngày cao nhất</div>
    <?php if ($h['best']): ?>
    <div class="kpi-value"><?= value_html(bytes_parts($h['best'][1] + $h['best'][2], $b)) ?></div>
    <div class="kpi-note"><?= e(fmt_day($h['best'][0], true)) ?></div>
    <?php else: ?>
    <div class="kpi-value"><span class="num">—</span></div>
    <div class="kpi-note">Không có dữ liệu</div>
    <?php endif; ?>
  </article>
</section>

<section class="card chart-card">
  <div class="card-head">
    <div>
      <h2><?= icon('chart') ?>Lưu lượng <?= e($granLabel) ?></h2>
      <p class="card-sub">Rê chuột hoặc chạm vào cột để xem chi tiết</p>
    </div>
    <div class="card-tools"><?= legend_html() ?><button type="button" class="btn-ghost sm" data-table-toggle="chart-history"><?= icon('table') ?>Bảng</button></div>
  </div>
  <div class="chart" id="chart-history" data-height="300"></div>
</section>

<?php if ($h['speed']): ?>
<section class="card chart-card">
  <div class="card-head">
    <div>
      <h2><?= icon('activity') ?>Tốc độ</h2>
      <p class="card-sub">Tốc độ trung bình theo từng khoảng thời gian</p>
    </div>
    <div class="card-tools"><?= legend_html(true) ?><button type="button" class="btn-ghost sm" data-table-toggle="chart-speed"><?= icon('table') ?>Bảng</button></div>
  </div>
  <div class="chart" id="chart-speed" data-height="260"></div>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head">
    <div>
      <h2><?= icon('table') ?>Chi tiết <?= e($granLabel) ?></h2>
      <p class="card-sub">Toàn bộ số liệu của khoảng thời gian đã chọn</p>
    </div>
    <div class="card-tools"><a class="btn-ghost sm" href="<?= e($csvUrl) ?>"><?= icon('file') ?>Tải CSV</a></div>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th scope="col">Thời gian</th>
        <th scope="col" class="n"><i class="sw dl"></i>Download</th>
        <th scope="col" class="n"><i class="sw ul"></i>Upload</th>
        <th scope="col" class="n">Tổng</th>
        <th scope="col" class="share-col hide-sm">Tỉ lệ ↓/↑</th>
      </tr></thead>
      <tbody>
      <?php
        $rows = array_reverse($h['items']);
        $shown = 0;
        foreach ($rows as $it):
            if ($it[1] === null) {
                continue;
            }
            $shown++;
            $k = $it[0];
            if ($h['gran'] === 'hour') {
                $label = date('H:00', $k) . '–' . date('H:00', $k + 3600) . ' <span class="muted">' . e(date('d/m', $k)) . '</span>';
            } elseif ($h['gran'] === 'day') {
                $label = e(VN_WEEKDAYS_SHORT[(int) date('w', ymd_ts($k))] . ', ' . fmt_day($k));
            } elseif ($h['gran'] === 'month') {
                $label = e(fmt_month($k));
            } else {
                $label = 'Năm ' . e($k);
            } ?>
        <tr>
          <td><?= $label ?></td>
          <td class="n"><?= e(fmt_bytes($it[1], $b)) ?></td>
          <td class="n"><?= e(fmt_bytes($it[2], $b)) ?></td>
          <td class="n"><b><?= e(fmt_bytes($it[1] + $it[2], $b)) ?></b></td>
          <td class="share-col hide-sm"><?= split_bar($it[1], $it[2]) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shown): ?>
        <tr><td colspan="5" class="empty">Không có dữ liệu trong khoảng thời gian này.</td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($shown): ?>
      <tfoot><tr>
        <th scope="row">Tổng cộng</th>
        <td class="n"><?= e(fmt_bytes($t['rx'], $b)) ?></td>
        <td class="n"><?= e(fmt_bytes($t['tx'], $b)) ?></td>
        <td class="n"><b><?= e(fmt_bytes($total, $b)) ?></b></td>
        <td class="share-col hide-sm"><?= split_bar($t['rx'], $t['tx']) ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>

<script type="application/json" id="app-config"><?= json_script($config) ?></script>
<?php
page_end($s);
