<?php
/**
 * Browser heartbeat — bukti kehadiran siswa di LockedBrowserScreen.
 * Dikirim setiap 20 detik oleh app. Jika berhenti → force-close terdeteksi.
 *
 * POST body (JSON): { apikey, userid, active }
 *   active = 1  → siswa aktif di browser
 *   active = 0  → siswa keluar dengan benar (exit password / logout Moodle)
 *
 * Response: { success: bool }
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

define('BHB_APIKEY', 'dosman-exam-sman1gianyar-2024');

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$apikey = trim((string)($body['apikey'] ?? ''));
$userid = (int)($body['userid'] ?? 0);
$active = isset($body['active']) ? (int)$body['active'] : 1; // 1=aktif, 0=keluar normal

if (!hash_equals(BHB_APIKEY, $apikey) || $userid <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

try {
    $configfile = __DIR__ . '/../../config.php';
    $src = file_get_contents($configfile);
    $cfg = [];
    foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $k) {
        if (preg_match('/\$CFG->' . $k . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
            $cfg[$k] = $m[1];
        }
    }
    $dsn    = 'mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4';
    $pdo    = new PDO($dsn, $cfg['dbuser'], $cfg['dbpass'], [
        PDO::ATTR_TIMEOUT => 3,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $prefix = $cfg['prefix'] ?? 'mdl_';

    if ($active === 0) {
        // Siswa keluar aplikasi: hapus dari appstatus + sesi monitoring + logout Moodle
        $pdo->prepare(
            "DELETE FROM {$prefix}local_dosman_ujian_appstatus WHERE userid = ?"
        )->execute([$userid]);
        $pdo->prepare(
            "DELETE FROM {$prefix}local_dosman_ujian_sessions WHERE userid = ?"
        )->execute([$userid]);
        $pdo->prepare(
            "DELETE FROM {$prefix}sessions WHERE userid = ?"
        )->execute([$userid]);
        echo json_encode(['success' => true, 'action' => 'logout']);
        exit;
    }

    // active = 1: catat heartbeat agar server tahu app masih terbuka
    $val = json_encode(['ts' => time(), 'active' => 1]);
    $pdo->prepare(
        "INSERT INTO {$prefix}config_plugins (plugin, name, value)
         VALUES ('local_dosman_ujian', ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute(['browser_hb_' . $userid, $val]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // Gagal simpan heartbeat — tidak kritis, app tetap jalan
    echo json_encode(['success' => false]);
}
