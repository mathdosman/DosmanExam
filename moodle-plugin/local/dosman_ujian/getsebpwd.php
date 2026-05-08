<?php
/**
 * Endpoint publik: kembalikan password SEB (iOS) dalam plaintext untuk dashboard admin.
 * Pola sama dengan exitpwd.php — tidak perlu autentikasi karena dashboard
 * sudah dilindungi login dan endpoint ini hanya mengembalikan string biasa.
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

// Wajibkan token Moodle yang valid.
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

    $stmt = $pdo->prepare(
        'SELECT value FROM ' . $prefix . 'config_plugins
          WHERE plugin = :plugin AND name = :name LIMIT 1'
    );
    $stmt->execute([':plugin' => 'local_dosman_ujian', ':name' => 'ios_seb_quit_password']);
    $row = $stmt->fetchColumn();

    echo json_encode(['password' => ($row !== false) ? (string)$row : '']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
