<?php
/**
 * Ghi mẫu dữ liệu router gửi lên và cộng dồn vào bảng giờ/ngày.
 *
 * Mỗi mẫu là lượng byte phát sinh trong khoảng [ts - dur, ts]. Nếu khoảng đó
 * trải qua nhiều giờ (ví dụ server tạm mất kết nối, router gửi dồn), lượng
 * byte được chia tỉ lệ theo thời gian vào từng giờ để biểu đồ không bị "gai".
 * Tổng byte luôn được bảo toàn chính xác.
 */
defined('MTS') || exit;

const SPLIT_MAX_SECONDS = 604800; // chỉ chia tỉ lệ nếu khoảng trống <= 7 ngày
const RATE_MIN_DUR = 2;           // bỏ qua tốc độ khi 2 mẫu cách nhau quá sát

/** Unix time của đầu giờ (theo múi giờ địa phương) chứa thời điểm $ts. */
function local_hour_start(int $ts): int
{
    $off = (int) date('Z', $ts);
    return intdiv($ts + $off, 3600) * 3600 - $off;
}

/**
 * Chia lượng byte của một mẫu vào các giờ mà mẫu đó trải qua.
 * @return array<int, array{0:int,1:int}> [đầu giờ => [rx, tx]]
 */
function allocate_hours(int $rx, int $tx, int $now, int $dur): array
{
    $endHour = local_hour_start($now);
    if ($dur <= 0 || $dur > SPLIT_MAX_SECONDS || ($rx === 0 && $tx === 0)) {
        return [$endHour => [$rx, $tx]];
    }
    $start = $now - $dur;
    $hour = local_hour_start($start);
    if ($hour === $endHour) {
        return [$endHour => [$rx, $tx]];
    }

    $out = [];
    $remRx = $rx;
    $remTx = $tx;
    $cursor = $start;
    while ($hour < $endHour) {
        $next = $hour + 3600;
        $frac = ($next - $cursor) / $dur;
        $partRx = (int) floor($rx * $frac);
        $partTx = (int) floor($tx * $frac);
        $out[$hour] = [$partRx, $partTx];
        $remRx -= $partRx;
        $remTx -= $partTx;
        $cursor = $next;
        $hour = $next;
    }
    $out[$endHour] = [$remRx, $remTx]; // phần còn lại -> tổng luôn khớp
    return $out;
}

function record_begin(PDO $db): void
{
    $db->exec('BEGIN IMMEDIATE');
}

function record_rollback(PDO $db): void
{
    try {
        $db->exec('ROLLBACK');
    } catch (Throwable $ignored) {
    }
}

/**
 * Ghi một mẫu. Phải được gọi bên trong transaction.
 * @return array{rx:int, tx:int, dur:int}
 */
function record_sample_tx(PDO $db, int $rx, int $tx, int $now, array $meta = []): array
{
    $rx = max(0, $rx);
    $tx = max(0, $tx);

    $lastTs = (int) meta_get($db, 'last_ts', '0');
    $dur = ($lastTs > 0 && $now > $lastTs) ? $now - $lastTs : 0;

    $db->prepare('INSERT INTO samples (ts, rx, tx, dur) VALUES (?, ?, ?, ?)')
        ->execute([$now, $rx, $tx, $dur]);

    $rateRx = $dur >= RATE_MIN_DUR ? $rx / $dur : 0.0;
    $rateTx = $dur >= RATE_MIN_DUR ? $tx / $dur : 0.0;

    $arrivalHour = local_hour_start($now);
    $alloc = allocate_hours($rx, $tx, $now, $dur);

    $insHour = $db->prepare('INSERT OR IGNORE INTO hourly (h) VALUES (?)');
    // CAST: PDO gửi tham số dạng chuỗi, mà MAX() của SQLite luôn coi chuỗi lớn hơn số.
    $updHour = $db->prepare('UPDATE hourly SET rx = rx + ?, tx = tx + ?, n = n + ?,
        peak_rx = MAX(peak_rx, CAST(? AS REAL)), peak_tx = MAX(peak_tx, CAST(? AS REAL)) WHERE h = ?');

    $days = [];
    foreach ($alloc as $hour => $part) {
        $isArrival = $hour === $arrivalHour;
        $insHour->execute([$hour]);
        $updHour->execute([$part[0], $part[1], $isArrival ? 1 : 0, $rateRx, $rateTx, $hour]);

        $day = date('Y-m-d', $hour);
        if (!isset($days[$day])) {
            $days[$day] = [0, 0, 0];
        }
        $days[$day][0] += $part[0];
        $days[$day][1] += $part[1];
        if ($isArrival) {
            $days[$day][2] = 1;
        }
    }

    $insDay = $db->prepare('INSERT OR IGNORE INTO daily (d) VALUES (?)');
    $updDay = $db->prepare('UPDATE daily SET rx = rx + ?, tx = tx + ?, n = n + ?,
        peak_rx = MAX(peak_rx, CAST(? AS REAL)), peak_tx = MAX(peak_tx, CAST(? AS REAL)) WHERE d = ?');
    foreach ($days as $day => $sum) {
        $insDay->execute([$day]);
        $updDay->execute([$sum[0], $sum[1], $sum[2], $rateRx, $rateTx, $day]);
    }

    $meta['last_ts'] = (string) $now;
    foreach ($meta as $k => $v) {
        meta_set($db, $k, (string) $v);
    }

    return ['rx' => $rx, 'tx' => $tx, 'dur' => $dur];
}

/** Chế độ cũ: router gửi lượng byte chênh lệch (rx, tx). */
function record_sample(PDO $db, int $rx, int $tx, int $now, array $meta = []): array
{
    record_begin($db);
    try {
        $res = record_sample_tx($db, $rx, $tx, $now, $meta);
        $db->exec('COMMIT');
        return $res;
    } catch (Throwable $e) {
        record_rollback($db);
        throw $e;
    }
}

/**
 * Chế độ bộ đếm: router gửi thẳng rx-byte/tx-byte của interface,
 * server tự tính chênh lệch. Gửi trùng hay gửi lỗi đều không làm sai số liệu;
 * khi bộ đếm bị reset (router khởi động lại, PPPoE quay số lại) thì lấy luôn
 * giá trị hiện tại làm lượng phát sinh.
 */
function record_counters(PDO $db, int $rxc, int $txc, int $now, array $meta = []): array
{
    record_begin($db);
    try {
        $last = meta_get_many($db, ['last_rxc', 'last_txc']);
        $rx = 0;
        $tx = 0;
        if ($last['last_rxc'] !== null && $last['last_txc'] !== null) {
            $lastRx = (int) $last['last_rxc'];
            $lastTx = (int) $last['last_txc'];
            $rx = $rxc >= $lastRx ? $rxc - $lastRx : $rxc;
            $tx = $txc >= $lastTx ? $txc - $lastTx : $txc;
            if ($rxc < $lastRx || $txc < $lastTx) {
                $meta['counter_reset_ts'] = (string) $now;
            }
        }
        $meta['last_rxc'] = (string) $rxc;
        $meta['last_txc'] = (string) $txc;
        $res = record_sample_tx($db, $rx, $tx, $now, $meta);
        $db->exec('COMMIT');
        return $res;
    } catch (Throwable $e) {
        record_rollback($db);
        throw $e;
    }
}

/** Dọn dữ liệu chi tiết cũ, chạy tối đa 1 lần/ngày (không cần cron). */
function recorder_housekeeping(PDO $db, int $now): void
{
    $today = date('Y-m-d', $now);
    if (meta_get($db, 'last_prune') === $today) {
        return;
    }
    $days = max(1, (int) settings($db)['raw_retention_days']);
    $db->prepare('DELETE FROM samples WHERE ts < ?')->execute([$now - $days * 86400]);
    $db->prepare('DELETE FROM login_attempts WHERE last < ?')->execute([$now - 86400]);
    meta_set($db, 'last_prune', $today);
}

/** Đọc tham số số nguyên từ query string. Trả về null nếu không hợp lệ. */
function parse_int_param($value): ?int
{
    if ($value === null) {
        return 0;
    }
    if (!is_scalar($value)) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    if (!preg_match('/^-?\d{1,19}$/', $value)) {
        return null;
    }
    return (int) $value;
}
