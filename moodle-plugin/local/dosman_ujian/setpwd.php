<?php
/**
 * Endpoint: simpan exit password keluar aplikasi.
 * POST { token, password } — token guru divalidasi lewat mdl_external_tokens.
 * Path DB sama dengan exitpwd.php (2 level naik ke Moodle root) → dijamin bisa.
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Path DB: sama persis dengan exitpwd.php — 2 level naik ke Moodle root
    $configfile = __DIR__ . '/../../config.php';

    if (!is_readable($configfile)) {
        throw new RuntimeException('config.php tidak dapat dibaca');
    }

    $src = file_get_contents($configfile);
    $cfg = [];
    foreach (['dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $key) {
        if (preg_match('/\$CFG->' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
            $cfg[$key] = $m[1];
        }
    }

    if (empty($cfg['dbname'])) {
        throw new RuntimeException('Tidak dapat membaca konfigurasi DB');
    }

    $prefix = $cfg['prefix'] ?? 'mdl_';
    $dsn    = 'mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4';
    $pdo    = new PDO($dsn, $cfg['dbuser'], $cfg['dbpass'], [
        PDO::ATTR_TIMEOUT  => 3,
        PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
    ]);

    // Baca body JSON
    $body     = json_decode(file_get_contents('php://input'), true) ?: [];
    $token    = trim((string)($body['token']    ?? $_POST['token']    ?? ''));
    $password = trim((string)($body['password'] ?? $_POST['password'] ?? ''));

    // Validasi token
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token tidak boleh kosong.']);
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM {$prefix}external_tokens
          WHERE token = ?
            AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP())
          LIMIT 1"
    );
    $stmt->execute([$token]);
    if ($stmt->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login ulang.']);
        exit;
    }

    // Validasi panjang password
    if ($password !== '' && strlen($password) < 4) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 4 karakter, atau kosongkan.']);
        exit;
    }
    if (strlen($password) > 64) {
        echo json_encode(['success' => false, 'message' => 'Password terlalu panjang (maks 64 karakter).']);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO {$prefix}config_plugins (plugin, name, value)
         VALUES ('local_dosman_ujian', 'admin_exit_password', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute([$password]);

    $msg = $password === ''
        ? 'Password dikosongkan — siswa dapat keluar aplikasi tanpa password.'
        : 'Password keluar aplikasi berhasil diperbarui.';
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server. Silakan coba lagi.']);
}
