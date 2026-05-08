<?php
/**
 * Logika suspend otomatis: siswa mode app (lock_status=1) tidak mengirim app_ping
 * lebih lama dari ambang batas → dianggap keluar aplikasi → akun langsung di-suspend.
 *
 * Tidak ada Tier 1 (device block). Hanya satu level: suspend akun.
 *
 * Dipanggil dari app_ping.php (setelah ping valid) dan opsional dari
 * cli_suspend_stale_app_ping.php (cron server).
 *
 * @package    local_dosman_ujian
 */

declare(strict_types=1);

/**
 * Detik tanpa app_ping sebelum akun di-suspend langsung.
 * Interval ping Flutter = 60 dtk; 90 dtk = 1,5 siklus ping sebagai margin aman
 * agar siswa yang masih di layar CourseList tidak terkena suspend false-positive.
 * Siswa yang sedang ujian (orak-orek di meja) terlindungi secara terpisah lewat
 * pengecekan last_heartbeat di query kandidat.
 */
const DOSMAN_UJIAN_STALE_APP_PING_SEC = 90;

/**
 * Suspend akun siswa yang tidak mengirim app_ping lebih dari ambang batas
 * DAN tidak memiliki heartbeat exam aktif (perlindungan siswa orak-orek).
 *
 * Siswa yang masih berada dalam aplikasi saat ujian (heartbeat segar ≤ 45 dtk)
 * dikecualikan sepenuhnya dari proses ini.
 *
 * @return int jumlah akun yang di-suspend pada pemanggilan ini
 */
function dosman_ujian_suspend_stale_app_ping_users(PDO $pdo, string $prefix, int $now): int {
    $pingThreshold = $now - DOSMAN_UJIAN_STALE_APP_PING_SEC;
    // Siswa yang masih mengerjakan ujian di meja (orak-orek) tetap mengirim
    // heartbeat setiap 10 detik. Kita kecualikan mereka agar tidak ter-suspend.
    $hbThreshold   = $now - 45;

    $stmtSite = $pdo->query(
        "SELECT value FROM {$prefix}config WHERE name = 'siteadmins' LIMIT 1"
    );
    $siteAdminRaw = $stmtSite ? (string)($stmtSite->fetchColumn() ?: '') : '';
    $siteAdmins = array_filter(array_map('trim', explode(',', $siteAdminRaw)));

    // Kandidat: lock_status=1, ping sudah stale, DAN tidak ada heartbeat exam aktif
    $sql = "SELECT a.userid
              FROM {$prefix}local_dosman_ujian_appstatus a
              JOIN {$prefix}user u ON u.id = a.userid AND u.deleted = 0 AND u.suspended = 0
             WHERE a.lock_status = 1
               AND a.lastping < :ping_threshold
               AND NOT EXISTS (
                   SELECT 1
                     FROM {$prefix}local_dosman_ujian_sessions s
                    WHERE s.userid = a.userid
                      AND s.status = 'active'
                      AND s.last_heartbeat >= :hb_threshold
               )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'ping_threshold' => $pingThreshold,
        'hb_threshold'   => $hbThreshold,
    ]);
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($candidates === false || $candidates === []) {
        return 0;
    }

    $svcStmt = $pdo->prepare(
        "SELECT id FROM {$prefix}external_services
          WHERE shortname = 'dosman_ujian_mobile' LIMIT 1"
    );
    $svcStmt->execute();
    $serviceId = $svcStmt->fetchColumn();

    $courseStmt = $pdo->query("SELECT MIN(id) AS cid FROM {$prefix}course");
    $logCourseId = (int)($courseStmt ? $courseStmt->fetchColumn() : 1);
    if ($logCourseId < 1) {
        $logCourseId = 1;
    }

    $reason = 'Keluar aplikasi (otomatis)';

    $mgrStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$prefix}role_assignments ra
          JOIN {$prefix}role r ON r.id = ra.roleid
         WHERE ra.userid = ? AND r.shortname = 'manager'"
    );

    $suspended = 0;

    foreach ($candidates as $uid) {
        $userid = (int)$uid;
        if ($userid <= 1) {
            continue;
        }
        if (in_array((string)$userid, $siteAdmins, true)) {
            continue;
        }
        $mgrStmt->execute([$userid]);
        if ((int)$mgrStmt->fetchColumn() > 0) {
            continue;
        }

        try {
            $pdo->beginTransaction();

            // Suspend akun Moodle langsung (tidak ada Tier 1 device block)
            $pdo->prepare(
                "UPDATE {$prefix}user
                    SET suspended = 1, timemodified = ?
                  WHERE id = ? AND suspended = 0"
            )->execute([$now, $userid]);

            if ($serviceId !== false) {
                $pdo->prepare(
                    "DELETE FROM {$prefix}external_tokens
                      WHERE externalserviceid = ? AND userid = ?"
                )->execute([(int)$serviceId, $userid]);
            }

            $pdo->prepare(
                "DELETE FROM {$prefix}sessions WHERE userid = ?"
            )->execute([$userid]);

            // Set lock_status=2 dan blokir sesi dosman
            $pdo->prepare(
                "UPDATE {$prefix}local_dosman_ujian_appstatus
                    SET lock_status = 2, lastping = ?
                  WHERE userid = ?"
            )->execute([$now, $userid]);

            $pdo->prepare(
                "UPDATE {$prefix}local_dosman_ujian_sessions
                    SET status = 'blocked',
                        blocked_reason = ?,
                        blocked_at = ?,
                        timemodified = ?
                  WHERE userid = ? AND status = 'active'"
            )->execute([$reason, $now, $now, $userid]);

            $pdo->prepare(
                "INSERT INTO {$prefix}local_dosman_ujian_logs
                    (userid, courseid, quizid, eventtype, eventdata, suspicious, timecreated)
                 VALUES (?, ?, 0, 'app_ping_stale_auto_suspend', ?, 1, ?)"
            )->execute([
                $userid,
                $logCourseId,
                json_encode([
                    'reason'          => 'no_ping_direct_suspend',
                    'stale_seconds'   => DOSMAN_UJIAN_STALE_APP_PING_SEC,
                    'threshold_unix'  => $pingThreshold,
                ], JSON_UNESCAPED_UNICODE),
                $now,
            ]);

            $pdo->commit();
            $suspended++;
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    return $suspended;
}
