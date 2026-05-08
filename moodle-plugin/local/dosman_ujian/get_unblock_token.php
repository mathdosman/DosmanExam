<?php
/**
 * Endpoint: ambil token 6 digit untuk membuka blokir perangkat siswa.
 * Token berbasis HMAC-SHA256, berubah otomatis setiap 10 menit.
 * Guru melihat kode ini di dashboard dan menyampaikannya ke siswa secara lisan.
 *
 * GET ?token=... → { token: "123456", seconds_remaining: N, valid_until: unix_ts }
 *
 * @package    local_dosman_ujian
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Dosman-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_DOSMAN_TOKEN'] ?? ''));
if ($token === '') {
    http_response_code(401);
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

    // Validasi token
    $stmtTok = $pdo->prepare(
        "SELECT userid FROM {$prefix}external_tokens
          WHERE token = ?
            AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP())
          LIMIT 1"
    );
    $stmtTok->execute([$token]);
    if ($stmtTok->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Token tidak valid']);
        exit;
    }

    // Get or create secret
    $stmtSecret = $pdo->prepare("SELECT value FROM {$prefix}config_plugins WHERE plugin = 'local_dosman_ujian' AND name = 'device_unblock_secret' LIMIT 1");
    $stmtSecret->execute();
    $secret = (string)($stmtSecret->fetchColumn() ?: '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO {$prefix}config_plugins (plugin, name, value) VALUES ('local_dosman_ujian', 'device_unblock_secret', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$secret]);
    }

    // Generate token
    $period = (int)floor(time() / 600); // changes every 10 minutes
    $hash = hash_hmac('sha256', (string)$period, $secret);
    $token6 = str_pad((int)hexdec(substr($hash, 0, 8)) % 1000000, 6, '0', STR_PAD_LEFT);
    $periodEnd = ($period + 1) * 600;
    $secondsRemaining = $periodEnd - time();

    echo json_encode(['token' => $token6, 'seconds_remaining' => $secondsRemaining, 'valid_until' => $periodEnd]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
