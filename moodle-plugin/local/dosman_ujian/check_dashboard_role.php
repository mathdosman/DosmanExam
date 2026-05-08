<?php
/**
 * Cek apakah pemilik token punya role guru/admin.
 * Dipanggil dari login.html dashboard setelah dapat token Moodle.
 * GET ?token=...
 * Response: { allowed: bool, reason?: string }
 *
 * @package local_dosman_ujian
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode(['allowed' => false, 'reason' => 'Token diperlukan']);
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
    if (empty($cfg['dbname'])) throw new RuntimeException('DB config tidak terbaca');

    $prefix = $cfg['prefix'] ?? 'mdl_';
    $pdo = new PDO(
        'mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
        $cfg['dbuser'], $cfg['dbpass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );

    // Ambil userid dari token
    $stmt = $pdo->prepare(
        "SELECT userid FROM {$prefix}external_tokens
          WHERE token = ?
            AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP())
          LIMIT 1"
    );
    $stmt->execute([$token]);
    $userid = $stmt->fetchColumn();
    if ($userid === false) {
        echo json_encode(['allowed' => false, 'reason' => 'Token tidak valid atau sudah kedaluwarsa.']);
        exit;
    }

    // Cek apakah user adalah site admin Moodle
    $stmt = $pdo->prepare("SELECT value FROM {$prefix}config WHERE name = 'siteadmins' LIMIT 1");
    $stmt->execute();
    $siteadmins = $stmt->fetchColumn() ?: '';
    $adminIds = array_filter(array_map('trim', explode(',', $siteadmins)));
    if (in_array((string)$userid, $adminIds)) {
        echo json_encode(['allowed' => true]);
        exit;
    }

    // Cek role guru/manager di konteks sistem (10), kategori (40), atau kursus (50).
    // Konteks modul (70+) dikecualikan agar siswa yang kebetulan
    // punya role teacher di level modul tidak lolos.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$prefix}role_assignments ra
           JOIN {$prefix}role r       ON r.id = ra.roleid
           JOIN {$prefix}context ctx  ON ctx.id = ra.contextid
          WHERE ra.userid = ?
            AND r.archetype IN ('manager','coursecreator','editingteacher','teacher')
            AND ctx.contextlevel IN (10, 40, 50)"
    );
    $stmt->execute([$userid]);
    $count = (int)$stmt->fetchColumn();

    if ($count > 0) {
        echo json_encode(['allowed' => true]);
    } else {
        echo json_encode([
            'allowed' => false,
            'reason'  => 'Akun siswa tidak diizinkan mengakses dashboard guru.',
        ]);
    }

} catch (Throwable $e) {
    echo json_encode(['allowed' => false, 'reason' => 'Terjadi kesalahan server.']);
}
