<?php
/**
 * CLI / cron: suspend langsung siswa yang tidak mengirim app_ping lebih lama dari ambang batas
 * DAN tidak memiliki heartbeat exam aktif (perlindungan siswa orak-orek).
 *
 * Tidak ada Tier 1 (device block) — hanya suspend akun.
 *
 * Tanpa argumen token — cocok untuk crontab server (misalnya tiap 5 menit):
 *   php /path/to/moodle/local/dosman_ujian/cli_suspend_stale_app_ping.php
 *
 * Pembersihan juga otomatis jalan setiap kali ada siswa yang app_ping berhasil.
 *
 * @package    local_dosman_ujian
 */

declare(strict_types=1);

$configfile = __DIR__ . '/../../config.php';
if (!is_readable($configfile)) {
    fwrite(STDERR, "config.php not found\n");
    exit(1);
}

$src = file_get_contents($configfile);
$cfg = [];
foreach (['dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $key) {
    if (preg_match('/\$CFG->' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
        $cfg[$key] = $m[1];
    }
}
if (empty($cfg['dbname'])) {
    fwrite(STDERR, "DB config not parseable\n");
    exit(1);
}

$prefix = $cfg['prefix'] ?? 'mdl_';
$dsn    = 'mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4';
$pdo    = new PDO($dsn, $cfg['dbuser'], $cfg['dbpass'], [
    PDO::ATTR_TIMEOUT => 10,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

require_once __DIR__ . '/stale_app_ping_suspend.inc.php';

$now = time();

$suspended = dosman_ujian_suspend_stale_app_ping_users($pdo, $prefix, $now);

if ($suspended > 0) {
    echo "dosman_ujian: suspended {$suspended} user(s) (stale app ping, no active heartbeat).\n";
} else {
    echo "dosman_ujian: no stale app ping users to suspend.\n";
}

exit(0);
