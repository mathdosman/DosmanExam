<?php
/**
 * Endpoint: ambil daftar riwayat QR SEB yang pernah digenerate.
 * GET ?token=... -> { history: [{id, title, date, pwd, ts, url}, ...] }
 *
 * @package local_dosman_ujian
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode(['history' => [], 'error' => 'Token diperlukan']);
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
    if (empty($cfg['dbname'])) throw new RuntimeException('DB config tidak terbaca');

    $prefix = $cfg['prefix'] ?? 'mdl_';
    $pdo = new PDO('mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
        $cfg['dbuser'], $cfg['dbpass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);

    $stmt = $pdo->prepare(
        "SELECT id FROM {$prefix}external_tokens
          WHERE token = ? AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP()) LIMIT 1"
    );
    $stmt->execute([$token]);
    if ($stmt->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['history' => [], 'error' => 'Token tidak valid']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'seb_qr_history' LIMIT 1"
    );
    $stmt->execute();
    $raw = $stmt->fetchColumn();
    $history = $raw ? (json_decode($raw, true) ?: []) : [];

    echo json_encode(['history' => $history]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['history' => [], 'error' => 'Server error']);
}
