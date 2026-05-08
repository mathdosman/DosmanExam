<?php
/**
 * GET  → kembalikan password keluar aplikasi saat ini
 * POST → simpan password keluar aplikasi (validasi token Moodle)
 *
 * Bypass bootstrap Moodle — akses DB langsung via PDO.
 * Token guru divalidasi lewat tabel mdl_external_tokens.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Helper: buka koneksi DB dari config.php ──────────────────────────────────
function openDb(): array {
    // Coba beberapa lokasi umum config.php Moodle
    $candidates = [
        dirname(__DIR__) . '/config.php',           // dashboard/ di dalam moodle root
        dirname(dirname(__DIR__)) . '/config.php',  // dashboard/ 2 level dalam
        __DIR__ . '/../config.php',                 // alternatif relatif
    ];
    $cfg_path = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) { $cfg_path = $c; break; }
    }
    if (!$cfg_path) {
        throw new RuntimeException(
            'config.php tidak ditemukan. Dicoba: ' . implode(', ', $candidates)
        );
    }
    $src = file_get_contents($cfg_path);
    $cfg = [];
    foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $k) {
        preg_match('/\$CFG->' . $k . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m);
        $cfg[$k] = $m[1] ?? '';
    }
    $pdo = new PDO(
        "mysql:host={$cfg['dbhost']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['dbuser'], $cfg['dbpass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    return [$pdo, $cfg['prefix'] ?: 'mdl_'];
}

// ── Helper: validasi token Moodle (cek di tabel external_tokens) ─────────────
function validateToken(PDO $pdo, string $prefix, string $token): bool {
    if ($token === '') return false;
    $stmt = $pdo->prepare(
        "SELECT id FROM {$prefix}external_tokens
          WHERE token = ?
            AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP())
          LIMIT 1"
    );
    $stmt->execute([$token]);
    return $stmt->fetchColumn() !== false;
}

function validateApiKey(string $apiKey): bool {
    if ($apiKey === '') return false;
    $expected = getenv('DOSMAN_EXIT_API_KEY') ?: '';
    if ($expected === '') return false;
    return hash_equals($expected, $apiKey);
}

// ── Helper: baca password saat ini ───────────────────────────────────────────
function readPassword(PDO $pdo, string $prefix): string {
    $stmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'admin_exit_password'
          LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetchColumn();
    return ($row !== false) ? (string)$row : '';
}

// ── Helper: simpan password ───────────────────────────────────────────────────
function writePassword(PDO $pdo, string $prefix, string $password): void {
    // Hapus dulu, lalu insert — aman di semua konfigurasi MySQL/MariaDB
    $del = $pdo->prepare(
        "DELETE FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'admin_exit_password'"
    );
    $del->execute();
    $ins = $pdo->prepare(
        "INSERT INTO {$prefix}config_plugins (plugin, name, value)
         VALUES ('local_dosman_ujian', 'admin_exit_password', ?)"
    );
    $ins->execute([$password]);
}

// ── Routing ───────────────────────────────────────────────────────────────────
try {
    [$pdo, $prefix] = openDb();
    $token  = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_MOODLE_TOKEN'] ?? ''));
    $apiKey = trim((string)($_GET['apikey'] ?? $_SERVER['HTTP_X_DOSMAN_APIKEY'] ?? ''));
    $isAuthorized = validateToken($pdo, $prefix, $token) || validateApiKey($apiKey);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$isAuthorized) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        echo json_encode(['password' => readPassword($pdo, $prefix)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body     = json_decode(file_get_contents('php://input'), true) ?: [];
        $token    = trim((string)($body['token']    ?? $_POST['token']    ?? $token));
        $apiKey   = trim((string)($body['apikey']   ?? $_POST['apikey']   ?? $apiKey));
        $password = trim((string)($body['password'] ?? $_POST['password'] ?? ''));

        if (!validateToken($pdo, $prefix, $token) && !validateApiKey($apiKey)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login ulang.']);
            exit;
        }

        // Password kosong = tidak perlu password (izinkan)
        // Password ada = minimal 4 karakter
        if ($password !== '' && strlen($password) < 4) {
            echo json_encode(['success' => false, 'message' => 'Password minimal 4 karakter, atau kosongkan untuk tidak memakai password.']);
            exit;
        }
        if (strlen($password) > 64) {
            echo json_encode(['success' => false, 'message' => 'Password terlalu panjang (maks 64 karakter).']);
            exit;
        }

        writePassword($pdo, $prefix, $password);

        $msg = $password === ''
            ? 'Password dikosongkan — siswa dapat keluar aplikasi tanpa password.'
            : 'Password keluar aplikasi berhasil diperbarui.';
        echo json_encode(['success' => true, 'message' => $msg]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
