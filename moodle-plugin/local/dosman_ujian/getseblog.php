<?php
/**
 * Endpoint: kembalikan log download Config SEB (iOS).
 * Wajib sertakan token Moodle yang valid via ?token= atau header X-Dosman-Token.
 *
 * Response: { "log": [ { "ip", "ua", "time" }, ... ] }
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

$token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_DOSMAN_TOKEN'] ?? ''));
if ($token === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $configfile = __DIR__ . '/../../config.php';
    if (!is_readable($configfile)) throw new RuntimeException('config.php not found');

    $src = file_get_contents($configfile);
    $cfg = [];
    foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $key) {
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

    // Validasi token
    $stmtTok = $pdo->prepare(
        "SELECT id FROM {$prefix}external_tokens
          WHERE token = ?
            AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP())
          LIMIT 1"
    );
    $stmtTok->execute([$token]);
    if ($stmtTok->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Token tidak valid atau sudah kedaluwarsa']);
        exit;
    }

    // Baca log
    $stmt = $pdo->prepare(
        'SELECT value FROM ' . $prefix . 'config_plugins
          WHERE plugin = :p AND name = :n LIMIT 1'
    );
    $stmt->execute([':p' => 'local_dosman_ujian', ':n' => 'seb_download_log']);
    $raw = $stmt->fetchColumn();
    $log = ($raw !== false) ? (json_decode($raw, true) ?: []) : [];

    echo json_encode(['log' => $log]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
