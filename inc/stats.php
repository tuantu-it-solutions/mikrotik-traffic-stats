<?php
/**
 * Truy vấn & tổng hợp số liệu cho trang thống kê.
 * Mọi mốc thời gian đều theo múi giờ APP_TIMEZONE.
 */
defined('MTS') || exit;

require_once __DIR__ . '/recorder.php'; // local_hour_start()

/* ---------- Mốc thời gian ---------- */

function day_start(int $ts): int
{
    return (int) strtotime('today', $ts);
}

function ymd_ts(string $ymd): int
{
    return (int) strtotime($ymd . ' 00:00:00');
}

/** Cộng/trừ ngày cho chuỗi YYYY-MM-DD. */
function ymd_add(string $ymd, int $days): string
{
    return date('Y-m-d', (int) strtotime(sprintf('%s 12:00:00 %+d days', $ymd, $days)));
}

/* ---------- Tổng lưu lượng trong một khoảng thời gian ---------- */

/**
 * Tổng byte trong [from, to) chỉ dùng bảng giờ, chia tỉ lệ ở giờ bị cắt.
 * Giờ hiện tại chỉ có dữ liệu tới $now nên được tính theo phần đã trôi qua.
 */
function hours_sum(PDO $db, int $from, int $to, int $now): array
{
    $rx = 0.0;
    $tx = 0.0;
    if ($to > $from) {
        $rows = db_all($db, 'SELECT h, rx, tx FROM hourly WHERE h > ? AND h < ?', [$from - 3600, $to]);
        foreach ($rows as $r) {
            $h = (int) $r['h'];
            $end = min($h + 3600, max($now, $h + 1));
            $overlap = min($end, $to) - max($h, $from);
            if ($overlap <= 0) {
                continue;
            }
            $f = min(1.0, $overlap / ($end - $h));
            $rx += $r['rx'] * $f;
            $tx += $r['tx'] * $f;
        }
    }
    return ['rx' => (int) round($rx), 'tx' => (int) round($tx)];
}

/** Tổng byte trong [from, to): ngày trọn vẹn lấy từ bảng ngày, phần lẻ lấy từ bảng giờ. */
function period_sum(PDO $db, int $from, int $to, int $now): array
{
    if ($to <= $from) {
        return ['rx' => 0, 'tx' => 0];
    }
    $firstFull = day_start($from);
    if ($firstFull < $from) {
        $firstFull = (int) strtotime('tomorrow', $from);
    }
    $lastFull = day_start($to);

    if ($firstFull >= $lastFull) {
        return hours_sum($db, $from, $to, $now);
    }

    $row = db_row(
        $db,
        'SELECT COALESCE(SUM(rx), 0) AS rx, COALESCE(SUM(tx), 0) AS tx FROM daily WHERE d >= ? AND d < ?',
        [date('Y-m-d', $firstFull), date('Y-m-d', $lastFull)]
    );
    $head = hours_sum($db, $from, $firstFull, $now);
    $tail = hours_sum($db, $lastFull, $to, $now);
    return [
        'rx' => (int) $row['rx'] + $head['rx'] + $tail['rx'],
        'tx' => (int) $row['tx'] + $head['tx'] + $tail['tx'],
    ];
}

/** Chu kỳ gửi dữ liệu thường gặp của router (trung vị các mẫu gần đây), giây. */
function typical_interval(PDO $db): int
{
    static $cache = null;
    if ($cache === null) {
        $cache = recent_interval($db);
    }
    return $cache;
}

/** Router còn gửi dữ liệu đều không? */
function is_online(?int $lastTs, int $typical, int $now): bool
{
    return $lastTs !== null && ($now - $lastTs) <= max(3 * $typical, $typical + 120);
}

/** Phiên bản dữ liệu - đổi khi có mẫu mới, đổi cài đặt hoặc sang giờ mới (dùng cho ETag). */
function data_rev(PDO $db, int $now): string
{
    $m = meta_get_many($db, ['last_ts', 'settings_rev']);
    return substr(md5($m['last_ts'] . '|' . $m['settings_rev'] . '|' . date('YmdH', $now) . '|' . MTS_VERSION), 0, 16);
}

/* ---------- Chuỗi tốc độ (từ mẫu chi tiết) ---------- */

function speed_series(PDO $db, int $from, int $to, int $maxPoints = 300): array
{
    $typical = typical_interval($db);
    $want = max($typical, (int) ceil(($to - $from) / $maxPoints));
    $bucket = 86400;
    foreach ([60, 120, 180, 300, 600, 900, 1200, 1800, 3600, 7200, 10800, 21600, 43200, 86400] as $b) {
        if ($b >= $want) {
            $bucket = $b;
            break;
        }
    }

    $rows = db_all(
        $db,
        'SELECT (ts / :b) * :b AS t, SUM(rx) AS rx, SUM(tx) AS tx, SUM(dur) AS dur, MIN(dur) AS mind
           FROM samples WHERE ts >= :f AND ts < :t AND dur > 0
          GROUP BY t ORDER BY t',
        [':b' => $bucket, ':f' => $from, ':t' => $to]
    );

    $points = [];
    $prev = null;
    foreach ($rows as $r) {
        $t = (int) $r['t'];
        $dur = max(1, (int) $r['dur']);
        // Coi là "mất tín hiệu" khi 2 điểm cách nhau xa hơn nhiều so với chu kỳ gửi
        // (tính cả chu kỳ của chính mẫu đó, phòng khi router từng đổi chu kỳ).
        $gap = max(2.5 * $bucket, 2.5 * $typical, 1.5 * (int) $r['mind']);
        if ($prev !== null && ($t - $prev) > $gap) {
            $points[] = [$prev + $bucket, null, null]; // khoảng trống: router không gửi dữ liệu
        }
        $points[] = [$t, round($r['rx'] * 8 / $dur), round($r['tx'] * 8 / $dur)];
        $prev = $t;
    }

    return ['from' => $from, 'to' => $to, 'bucket' => $bucket, 'points' => $points];
}

/* ---------- Dữ liệu trang Tổng quan ---------- */

function overview_data(PDO $db, array $s, int $now): array
{
    $todayStart = day_start($now);
    $today = date('Y-m-d', $now);
    $nowHour = local_hour_start($now);
    $tzOffset = (int) date('Z', $now);
    $meta = meta_get_many($db, ['last_ts']);
    $lastTs = $meta['last_ts'] !== null ? (int) $meta['last_ts'] : null;
    $typical = typical_interval($db);

    /* Tổng toàn thời gian */
    $all = db_row($db, 'SELECT COALESCE(SUM(rx),0) AS rx, COALESCE(SUM(tx),0) AS tx,
        MIN(d) AS since, COUNT(*) AS days, COALESCE(SUM(n),0) AS n FROM daily');
    $since = $all['since'] !== null ? (string) $all['since'] : null;
    $spanDays = $since ? max(1, (int) round(($todayStart - ymd_ts($since)) / 86400) + 1) : 0;
    $allTotal = (int) $all['rx'] + (int) $all['tx'];

    $years = [];
    foreach (db_all($db, 'SELECT substr(d, 1, 4) AS y, SUM(rx) AS rx, SUM(tx) AS tx FROM daily
        GROUP BY y ORDER BY y DESC LIMIT 4') as $r) {
        $years[] = [(string) $r['y'], (int) $r['rx'], (int) $r['tx']];
    }

    $peakRx = db_row($db, 'SELECT d, peak_rx AS v FROM daily ORDER BY peak_rx DESC LIMIT 1');
    $peakTx = db_row($db, 'SELECT d, peak_tx AS v FROM daily ORDER BY peak_tx DESC LIMIT 1');

    $top = [];
    foreach (db_all($db, 'SELECT d, rx, tx FROM daily WHERE rx + tx > 0 ORDER BY rx + tx DESC LIMIT 10') as $r) {
        $top[] = [(string) $r['d'], (int) $r['rx'], (int) $r['tx']];
    }

    /* Các thẻ KPI, mỗi thẻ kèm kỳ so sánh tương ứng */
    $yStart = (int) strtotime('-1 day', $todayStart);
    $yyStart = (int) strtotime('-2 days', $todayStart);
    $monthStart = (int) strtotime(date('Y-m-01 00:00:00', $now));
    $lmStart = (int) strtotime('-1 month', $monthStart);
    $llmStart = (int) strtotime('-2 months', $monthStart);
    $yearStart = (int) strtotime(date('Y-01-01 00:00:00', $now));
    $lyStart = (int) strtotime('-1 year', $yearStart);
    $end = $now + 1;

    $kpiDefs = [
        ['today', 'Hôm nay', [$todayStart, $end], [$yStart, $yStart + ($now - $todayStart)], 'so với cùng giờ hôm qua'],
        ['yesterday', 'Hôm qua', [$yStart, $todayStart], [$yyStart, $yStart], 'so với hôm kia'],
        ['7d', '7 ngày qua', [$now - 7 * 86400, $end], [$now - 14 * 86400, $now - 7 * 86400], 'so với 7 ngày trước đó'],
        ['month', 'Tháng này', [$monthStart, $end], [$lmStart, min($monthStart, $lmStart + ($now - $monthStart))], 'so với cùng kỳ tháng trước'],
        ['last_month', 'Tháng trước', [$lmStart, $monthStart], [$llmStart, $lmStart], 'so với tháng trước nữa'],
        ['year', 'Năm ' . date('Y', $now), [$yearStart, $end], [$lyStart, min($yearStart, $lyStart + ($now - $yearStart))], 'so với cùng kỳ năm trước'],
    ];
    $kpis = [];
    foreach ($kpiDefs as $k) {
        $cur = period_sum($db, $k[2][0], $k[2][1], $now);
        $cmp = period_sum($db, $k[3][0], $k[3][1], $now);
        $kpis[] = [
            'key' => $k[0],
            'label' => $k[1],
            'rx' => $cur['rx'],
            'tx' => $cur['tx'],
            'cmp' => $cmp['rx'] + $cmp['tx'],
            'cmpLabel' => $k[4],
        ];
    }

    /* Tốc độ hiện tại = tốc độ trung bình của mẫu mới nhất */
    $current = null;
    $last = db_row($db, 'SELECT ts, rx, tx, dur FROM samples ORDER BY id DESC LIMIT 1');
    if ($last && (int) $last['dur'] >= RATE_MIN_DUR) {
        $current = [
            'ts' => (int) $last['ts'],
            'dur' => (int) $last['dur'],
            'rx' => round($last['rx'] * 8 / $last['dur']),
            'tx' => round($last['tx'] * 8 / $last['dur']),
        ];
    }

    /* Tốc độ cao nhất hôm nay (kèm thời điểm) */
    $todayPeak = ['rx' => 0, 'rxTs' => null, 'tx' => 0, 'txTs' => null];
    foreach (['rx', 'tx'] as $col) {
        $r = db_row($db, "SELECT ts, $col * 8.0 / dur AS v FROM samples
            WHERE ts >= ? AND dur >= " . RATE_MIN_DUR . " ORDER BY v DESC LIMIT 1", [$todayStart]);
        if ($r) {
            $todayPeak[$col] = round((float) $r['v']);
            $todayPeak[$col . 'Ts'] = (int) $r['ts'];
        }
    }

    /* Theo giờ: hôm nay (+ hôm qua để so sánh trong tooltip) */
    $firstHour = (int) db_value($db, 'SELECT MIN(h) FROM hourly');
    $hourMap = [];
    foreach (db_all($db, 'SELECT h, rx, tx FROM hourly WHERE h >= ? AND h < ?', [$yStart, $todayStart + 86400 + 3600]) as $r) {
        $hourMap[(int) $r['h']] = [(int) $r['rx'], (int) $r['tx']];
    }
    $hours = [];
    for ($i = 0; $i < 24; $i++) {
        $h = local_hour_start($todayStart + $i * 3600 + 1800);
        $y = local_hour_start($yStart + $i * 3600 + 1800);
        $cur = $h > $nowHour || !$firstHour || $h + 3600 <= $firstHour ? null : ($hourMap[$h] ?? [0, 0]);
        $prev = $hourMap[$y] ?? null;
        $hours[] = [$h, $cur ? $cur[0] : null, $cur ? $cur[1] : null, $prev ? $prev[0] + $prev[1] : null];
    }

    /* Lịch & 30 ngày & 12 tháng - lấy một lần từ bảng ngày */
    $calStart = ymd_add($today, -370);
    $dayMap = [];
    foreach (db_all($db, 'SELECT d, rx, tx FROM daily WHERE d >= ?', [$calStart]) as $r) {
        $dayMap[(string) $r['d']] = [(int) $r['rx'], (int) $r['tx']];
    }
    $daily = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = ymd_add($today, -$i);
        $v = ($since === null || $d < $since) ? null : ($dayMap[$d] ?? [0, 0]);
        $daily[] = [$d, $v ? $v[0] : null, $v ? $v[1] : null];
    }
    $calendar = $dayMap; // [YYYY-MM-DD => [rx, tx]]

    $months = [];
    $monthStartList = [];
    for ($i = 11; $i >= 0; $i--) {
        $monthStartList[] = date('Y-m', (int) strtotime("-$i months", $monthStart));
    }
    $monthMap = [];
    foreach (db_all($db, 'SELECT substr(d, 1, 7) AS m, SUM(rx) AS rx, SUM(tx) AS tx FROM daily
        WHERE d >= ? GROUP BY m', [$monthStartList[0] . '-01']) as $r) {
        $monthMap[(string) $r['m']] = [(int) $r['rx'], (int) $r['tx']];
    }
    $sinceMonth = $since ? substr($since, 0, 7) : null;
    foreach ($monthStartList as $m) {
        $v = ($sinceMonth === null || $m < $sinceMonth) ? null : ($monthMap[$m] ?? [0, 0]);
        $months[] = [$m, $v ? $v[0] : null, $v ? $v[1] : null];
    }

    /* Thói quen: trung bình theo thứ x giờ trong 8 tuần gần nhất */
    $pattern = [];
    $patFrom = (int) strtotime('-55 days', $todayStart);
    foreach (db_all($db, "SELECT CAST(strftime('%w', h + :o, 'unixepoch') AS INTEGER) AS wd,
            CAST(strftime('%H', h + :o, 'unixepoch') AS INTEGER) AS hr,
            SUM(rx + tx) AS total, COUNT(*) AS cnt
            FROM hourly WHERE h >= :f GROUP BY wd, hr", [':o' => $tzOffset, ':f' => $patFrom]) as $r) {
        $wd = ((int) $r['wd'] + 6) % 7; // 0 = Thứ Hai ... 6 = Chủ Nhật
        $pattern[] = [$wd, (int) $r['hr'], (int) round($r['total'] / max(1, (int) $r['cnt'])), (int) $r['cnt']];
    }

    /* Mẫu gần đây */
    $recent = [];
    foreach (db_all($db, 'SELECT ts, rx, tx, dur FROM samples ORDER BY id DESC LIMIT 12') as $r) {
        $recent[] = [(int) $r['ts'], (int) $r['rx'], (int) $r['tx'], (int) $r['dur']];
    }

    return [
        'now' => $now,
        'tzOffset' => $tzOffset,
        'today' => $today,
        'unitBase' => (int) $s['unit_base'] === 1000 ? 1000 : 1024,
        'plan' => [
            'rx' => max(0, (float) $s['plan_down_mbps']) * 1e6,
            'tx' => max(0, (float) $s['plan_up_mbps']) * 1e6,
        ],
        'status' => [
            'lastTs' => $lastTs,
            'typical' => $typical,
            'online' => is_online($lastTs, $typical, $now),
        ],
        'current' => $current,
        'todayPeak' => $todayPeak,
        'allTime' => [
            'rx' => (int) $all['rx'],
            'tx' => (int) $all['tx'],
            'since' => $since,
            'spanDays' => $spanDays,
            'days' => (int) $all['days'],
            'samples' => (int) $all['n'],
            'avgDay' => $spanDays ? (int) round($allTotal / $spanDays) : 0,
            'best' => $top ? $top[0] : null,
            'years' => $years,
            'peakRx' => $peakRx && $peakRx['v'] > 0 ? [(string) $peakRx['d'], round($peakRx['v'] * 8)] : null,
            'peakTx' => $peakTx && $peakTx['v'] > 0 ? [(string) $peakTx['d'], round($peakTx['v'] * 8)] : null,
        ],
        'kpis' => $kpis,
        'speed' => speed_series($db, $now - 86400, $now + 1),
        'live' => speed_series($db, $now - 3600, $now + 1, 90),
        'hours' => $hours,
        'daily' => $daily,
        'months' => $months,
        'calendar' => $calendar,
        'pattern' => $pattern,
        'top' => $top,
        'recent' => $recent,
    ];
}

/* ---------- Trang Lịch sử ---------- */

function history_presets(): array
{
    return [
        'today' => 'Hôm nay',
        'yesterday' => 'Hôm qua',
        '7d' => '7 ngày qua',
        '30d' => '30 ngày qua',
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
        'this_year' => 'Năm nay',
        'last_year' => 'Năm trước',
        'all' => 'Toàn bộ',
    ];
}

/** @return array{0:string,1:string,2:string} [from, to, preset|custom] */
function resolve_range(string $preset, $from, $to, ?string $firstDay, int $now): array
{
    $today = date('Y-m-d', $now);
    switch ($preset) {
        case 'today':
            return [$today, $today, $preset];
        case 'yesterday':
            $y = ymd_add($today, -1);
            return [$y, $y, $preset];
        case '7d':
            return [ymd_add($today, -6), $today, $preset];
        case '30d':
            return [ymd_add($today, -29), $today, $preset];
        case 'this_month':
            return [date('Y-m-01', $now), date('Y-m-t', $now), $preset];
        case 'last_month':
            $lm = (int) strtotime('first day of last month', $now);
            return [date('Y-m-01', $lm), date('Y-m-t', $lm), $preset];
        case 'this_year':
            return [date('Y-01-01', $now), date('Y-12-31', $now), $preset];
        case 'last_year':
            $y = (int) date('Y', $now) - 1;
            return [$y . '-01-01', $y . '-12-31', $preset];
        case 'all':
            return [$firstDay && $firstDay < $today ? $firstDay : $today, $today, $preset];
    }

    if (!valid_ymd($from) || !valid_ymd($to)) {
        return [ymd_add($today, -29), $today, '30d'];
    }
    if ($from > $to) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }
    if (ymd_ts($to) - ymd_ts($from) > 20 * 366 * 86400) {
        $from = ymd_add($to, -20 * 366);
    }
    return [$from, $to, 'custom'];
}

function history_data(PDO $db, array $s, string $from, string $to, int $now, ?string $forceGran = null): array
{
    $fromTs = ymd_ts($from);
    $toTs = (int) strtotime($to . ' 00:00:00 +1 day');
    $days = max(1, (int) round(($toTs - $fromTs) / 86400));
    $today = date('Y-m-d', $now);
    $nowHour = local_hour_start($now);
    $firstDay = db_value($db, 'SELECT MIN(d) FROM daily');
    $firstDay = $firstDay !== null ? (string) $firstDay : null;

    $tot = db_row($db, 'SELECT COALESCE(SUM(rx),0) AS rx, COALESCE(SUM(tx),0) AS tx, COALESCE(SUM(n),0) AS n,
        COALESCE(MAX(peak_rx),0) AS prx, COALESCE(MAX(peak_tx),0) AS ptx, COUNT(*) AS days
        FROM daily WHERE d >= ? AND d <= ?', [$from, $to]);
    $best = db_row($db, 'SELECT d, rx, tx FROM daily WHERE d >= ? AND d <= ? AND rx + tx > 0
        ORDER BY rx + tx DESC LIMIT 1', [$from, $to]);

    $effFrom = $firstDay && $firstDay > $from ? $firstDay : $from;
    $effTo = $to < $today ? $to : $today;
    $effDays = $effFrom <= $effTo ? (int) round((ymd_ts($effTo) - ymd_ts($effFrom)) / 86400) + 1 : 0;
    $total = (int) $tot['rx'] + (int) $tot['tx'];

    $monthsSpan = ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12
        + (int) substr($to, 5, 2) - (int) substr($from, 5, 2) + 1;
    if ($days <= 2) {
        $gran = 'hour';
    } elseif ($days <= 92) {
        $gran = 'day';
    } elseif ($monthsSpan <= 36) {
        $gran = 'month';
    } else {
        $gran = 'year';
    }
    // Cho phép ép độ chi tiết (xuất CSV); giới hạn số dòng theo giờ cho nhẹ hosting.
    if (in_array($forceGran, ['day', 'month', 'year'], true) || ($forceGran === 'hour' && $days <= 400)) {
        $gran = $forceGran;
    }

    $items = [];
    if ($gran === 'hour') {
        $firstHour = (int) db_value($db, 'SELECT MIN(h) FROM hourly');
        $map = [];
        foreach (db_all($db, 'SELECT h, rx, tx FROM hourly WHERE h >= ? AND h < ?', [$fromTs, $toTs]) as $r) {
            $map[(int) $r['h']] = [(int) $r['rx'], (int) $r['tx']];
        }
        for ($h = local_hour_start($fromTs); $h < $toTs; $h += 3600) {
            $v = ($h > $nowHour || !$firstHour || $h + 3600 <= $firstHour) ? null : ($map[$h] ?? [0, 0]);
            $items[] = [$h, $v ? $v[0] : null, $v ? $v[1] : null];
        }
    } elseif ($gran === 'day') {
        $map = [];
        foreach (db_all($db, 'SELECT d, rx, tx FROM daily WHERE d >= ? AND d <= ?', [$from, $to]) as $r) {
            $map[(string) $r['d']] = [(int) $r['rx'], (int) $r['tx']];
        }
        for ($d = $from; $d <= $to; $d = ymd_add($d, 1)) {
            $v = ($d > $today || !$firstDay || $d < $firstDay) ? null : ($map[$d] ?? [0, 0]);
            $items[] = [$d, $v ? $v[0] : null, $v ? $v[1] : null];
        }
    } else {
        $len = $gran === 'month' ? 7 : 4;
        $map = [];
        foreach (db_all($db, "SELECT substr(d, 1, $len) AS k, SUM(rx) AS rx, SUM(tx) AS tx FROM daily
            WHERE d >= ? AND d <= ? GROUP BY k", [$from, $to]) as $r) {
            $map[(string) $r['k']] = [(int) $r['rx'], (int) $r['tx']];
        }
        $curKey = substr($today, 0, $len);
        $firstKey = $firstDay ? substr($firstDay, 0, $len) : null;
        if ($gran === 'month') {
            $keys = [];
            $cursor = (int) strtotime(substr($from, 0, 7) . '-01 12:00:00');
            $stop = substr($to, 0, 7);
            while (($k = date('Y-m', $cursor)) <= $stop) {
                $keys[] = $k;
                $cursor = (int) strtotime('+1 month', $cursor);
            }
        } else {
            $keys = array_map('strval', range((int) substr($from, 0, 4), (int) substr($to, 0, 4)));
        }
        foreach ($keys as $k) {
            $v = ($k > $curKey || $firstKey === null || $k < $firstKey) ? null : ($map[$k] ?? [0, 0]);
            $items[] = [$k, $v ? $v[0] : null, $v ? $v[1] : null];
        }
    }

    $speed = null;
    if ($gran === 'hour' && $fromTs <= $now) {
        $oldest = db_value($db, 'SELECT MIN(ts) FROM samples');
        if ($oldest !== null && (int) $oldest < $toTs) {
            $speed = speed_series($db, $fromTs, min($toTs, $now + 1), 288);
            $speed['to'] = $toTs;
        }
    }

    return [
        'from' => $from,
        'to' => $to,
        'days' => $days,
        'effDays' => $effDays,
        'gran' => $gran,
        'now' => $now,
        'tzOffset' => (int) date('Z', $now),
        'unitBase' => (int) $s['unit_base'] === 1000 ? 1000 : 1024,
        'totals' => [
            'rx' => (int) $tot['rx'],
            'tx' => (int) $tot['tx'],
            'samples' => (int) $tot['n'],
            'activeDays' => (int) $tot['days'],
            'avgDay' => $effDays ? (int) round($total / $effDays) : 0,
            'peakRx' => round((float) $tot['prx'] * 8),
            'peakTx' => round((float) $tot['ptx'] * 8),
        ],
        'best' => $best ? [(string) $best['d'], (int) $best['rx'], (int) $best['tx']] : null,
        'items' => $items,
        'speed' => $speed,
    ];
}
