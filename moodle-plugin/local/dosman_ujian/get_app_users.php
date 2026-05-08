<?php
/**
 * Endpoint: ambil semua siswa yang sudah login via aplikasi Android maupun iOS/SEB.
 *
 * GET  ?token=XXX
 * Response: { users: [ {userid, firstname, lastname, email, department, lock_status, lastping, ipaddress, client_type} ] }
 *   client_type: 'android' | 'ios'
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token diperlukan']);
    exit;
}

try {
    $configfile = __DIR__ . '/../../config.php';
    if (!is_readable($configfile)) throw new RuntimeException('config.php not found');

    $src = file_get_contents($configfile);
    $cfg = [];
    foreach (['dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $key) {
        if (preg_match('/\$CFG->' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
            $cfg[$key] = $m[1];
        }
    }
    if (empty($cfg['dbname'])) throw new RuntimeException('DB config not parseable');

    $prefix = $cfg['prefix'] ?? 'mdl_';
    $dsn    = 'mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4';
    $pdo    = new PDO($dsn, $cfg['dbuser'], $cfg['dbpass'], [
        PDO::ATTR_TIMEOUT => 3,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Validasi token admin
    $st = $pdo->prepare(
        "SELECT userid FROM {$prefix}external_tokens
          WHERE token = ? AND (validuntil = 0 OR validuntil > ?) LIMIT 1"
    );
    $st->execute([$token, time()]);
    $adminId = $st->fetchColumn();
    if ($adminId === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Token tidak valid']);
        exit;
    }

    // Jalankan stale-check setiap kali dashboard refresh — suspend siswa yang
    // sudah tidak mengirim ping tanpa menunggu ping dari siswa lain.
    $now = time();
    require_once __DIR__ . '/stale_app_ping_suspend.inc.php';
    dosman_ujian_suspend_stale_app_ping_users($pdo, $prefix, $now);

    // Tampilkan siswa yang aktif di aplikasi:
    //   lock_status = 2 (diblokir) atau 3 (dijeda) → selalu tampil (tidak ada token, tidak bisa ping)
    //   lock_status = 1 (aktif Android) → hanya jika lastping < 10 menit (via app_ping.php / heartbeat)
    //   lock_status = 0 (aktif iOS/SEB) → hanya jika lastping < 5 menit
    // Siswa tersuspend dikecualikan — mereka ada di panel "Akun Tersuspend".
    $sql = "SELECT a.userid, a.lastping, a.ipaddress, a.lock_status,
                   IFNULL(a.current_quizid, 0) AS current_quizid,
                   u.firstname, u.lastname, u.username, u.email, u.department,
                   q.name AS quiz_name,
                   CASE WHEN a.lock_status > 0 THEN 'android' ELSE 'ios' END AS client_type
              FROM {$prefix}local_dosman_ujian_appstatus a
              JOIN {$prefix}user u ON u.id = a.userid AND u.suspended = 0 AND u.deleted = 0
              LEFT JOIN {$prefix}quiz q ON q.id = a.current_quizid AND a.current_quizid > 0
             WHERE a.lock_status IN (2, 3)
                OR (a.lock_status = 1 AND a.lastping > UNIX_TIMESTAMP() - 600)
                OR (a.lock_status = 0 AND a.lastping > UNIX_TIMESTAMP() - 300)
          ORDER BY a.lock_status DESC, a.current_quizid DESC, a.lastping DESC";

    $rows  = $pdo->query($sql)->fetchAll(PDO::FETCH_OBJ);
    $users = [];
    foreach ($rows as $r) {
        $users[] = [
            'userid'          => (int)$r->userid,
            'firstname'       => $r->firstname,
            'lastname'        => $r->lastname,
            'username'        => $r->username ?? '',
            'email'           => $r->email ?? '',
            'department'      => $r->department ?? '',
            'lock_status'     => (int)$r->lock_status,
            'lastping'        => (int)$r->lastping,
            'ipaddress'       => $r->ipaddress ?? '',
            'current_quizid'  => (int)$r->current_quizid,
            'quiz_name'       => $r->quiz_name ?? '',
            'client_type'     => $r->client_type,
        ];
    }

    echo json_encode(['users' => $users]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
