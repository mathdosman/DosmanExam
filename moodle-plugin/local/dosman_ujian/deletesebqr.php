<?php
/**
 * Endpoint: hapus satu entri dari riwayat QR SEB berdasarkan ID.
 * POST { token, qr_id } -> { success: true } | { error: ... }
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($input['token'] ?? $_GET['token'] ?? ''));
$qrId  = trim((string)($input['qr_id'] ?? ''));

if ($token === '' || $qrId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter tidak lengkap']);
    exit;
}

try {
    $configfile = __DIR__ . '/../../config.php';
    if (!is_readable($configfile)) throw new RuntimeException('config.php not found');

    $src = file_get_contents($configfile);
    $cfg = [];
    foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $key) {
        if (preg_match('/\$CFG->' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) $cfg[$key] = $m[1];
    }
    if (empty($cfg['dbname'])) throw new RuntimeException('DB config not parseable');

    $prefix = $cfg['prefix'] ?? 'mdl_';
    $pdo = new PDO('mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
        $cfg['dbuser'], $cfg['dbpass'],
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Validasi token
    $stmt = $pdo->prepare(
        "SELECT id FROM {$prefix}external_tokens
          WHERE token = ? AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP()) LIMIT 1"
    );
    $stmt->execute([$token]);
    if ($stmt->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Token tidak valid']);
        exit;
    }

    // Baca riwayat
    $stmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'seb_qr_history' LIMIT 1"
    );
    $stmt->execute();
    $raw     = $stmt->fetchColumn();
    $history = $raw ? (json_decode($raw, true) ?: []) : [];

    // Hapus entri yang cocok
    $before  = count($history);
    $history = array_values(array_filter($history, function ($e) use ($qrId) {
        return ($e['id'] ?? '') !== $qrId;
    }));

    if (count($history) === $before) {
        echo json_encode(['success' => false, 'error' => 'QR tidak ditemukan']);
        exit;
    }

    // Simpan kembali
    $pdo->prepare(
        "INSERT INTO {$prefix}config_plugins (plugin, name, value)
         VALUES ('local_dosman_ujian', 'seb_qr_history', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute([json_encode($history, JSON_UNESCAPED_UNICODE)]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
