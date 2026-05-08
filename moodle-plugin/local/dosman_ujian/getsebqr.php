<?php
/**
 * Endpoint: generate QR SEB bertitel. Password aktif saat generate disimpan per-QR.
 * GET ?token=...&title=...&exam_date=YYYY-MM-DD
 * -> { id, title, date, launch_url, qr_image_url }
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
    foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix', 'wwwroot'] as $key) {
        if (preg_match('/\$CFG->' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) $cfg[$key] = $m[1];
    }
    if (empty($cfg['dbname']) || empty($cfg['wwwroot'])) throw new RuntimeException('DB config not parseable');

    $prefix = $cfg['prefix'] ?? 'mdl_';
    $pdo = new PDO('mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
        $cfg['dbuser'], $cfg['dbpass'],
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Validasi token
    $stmtTok = $pdo->prepare(
        "SELECT id FROM {$prefix}external_tokens
          WHERE token = ? AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP()) LIMIT 1"
    );
    $stmtTok->execute([$token]);
    if ($stmtTok->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Token tidak valid atau sudah kedaluwarsa']);
        exit;
    }

    // Parameter judul dan tanggal
    $title    = trim((string)($_GET['title']     ?? ''));
    $examDate = trim((string)($_GET['exam_date'] ?? ''));
    if ($title === '') $title = 'Ujian SEB';
    if ($examDate !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $examDate);
        if (!$dt || $dt->format('Y-m-d') !== $examDate) $examDate = date('Y-m-d');
    } else {
        $examDate = date('Y-m-d');
    }

    // Tolak jika judul+tanggal yang sama sudah ada di riwayat
    $chkStmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'seb_qr_history' LIMIT 1"
    );
    $chkStmt->execute();
    $chkRaw  = $chkStmt->fetchColumn();
    $chkHist = $chkRaw ? (json_decode($chkRaw, true) ?: []) : [];
    foreach ($chkHist as $entry) {
        if (strtolower(trim($entry['title'] ?? '')) === strtolower($title)
            && ($entry['date'] ?? '') === $examDate) {
            http_response_code(409);
            echo json_encode(['error' => 'QR dengan judul "' . $title . '" untuk tanggal ini sudah ada.']);
            exit;
        }
    }

    // Baca password SEB aktif saat ini
    $pwdStmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'ios_seb_quit_password' LIMIT 1"
    );
    $pwdStmt->execute();
    $currentPwd = (string)($pwdStmt->fetchColumn() ?: '');

    // Generate ID unik untuk QR ini
    $qrId = bin2hex(random_bytes(8));

    $base       = rtrim((string)$cfg['wwwroot'], '/');
    $launchUrl  = $base . '/local/dosman_ujian/sebconfig.php?qr=' . $qrId;
    $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . rawurlencode($launchUrl);

    // Simpan ke riwayat QR (max 50 entri)
    $hStmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins
          WHERE plugin = 'local_dosman_ujian' AND name = 'seb_qr_history' LIMIT 1"
    );
    $hStmt->execute();
    $raw     = $hStmt->fetchColumn();
    $history = $raw ? (json_decode($raw, true) ?: []) : [];

    array_unshift($history, [
        'id'    => $qrId,
        'title' => $title,
        'date'  => $examDate,
        'pwd'   => $currentPwd,
        'ts'    => time(),
        'url'   => $launchUrl,
    ]);
    if (count($history) > 50) $history = array_slice($history, 0, 50);

    $pdo->prepare(
        "INSERT INTO {$prefix}config_plugins (plugin, name, value)
         VALUES ('local_dosman_ujian', 'seb_qr_history', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute([json_encode($history, JSON_UNESCAPED_UNICODE)]);

    // Format tanggal untuk display — format Indonesia lengkap
    $idMonths  = ['Januari','Februari','Maret','April','Mei','Juni',
                  'Juli','Agustus','September','Oktober','November','Desember'];
    $parts     = explode('-', $examDate);
    $dateLabel = (int)$parts[2] . ' ' . $idMonths[(int)$parts[1] - 1] . ' ' . $parts[0];

    // Catat log download (IP + UA)
    $logStmt = $pdo->prepare(
        "SELECT value FROM {$prefix}config_plugins WHERE plugin = 'local_dosman_ujian' AND name = 'seb_download_log' LIMIT 1"
    );
    $logStmt->execute();
    $existing = $logStmt->fetchColumn();
    $log = $existing ? (json_decode($existing, true) ?: []) : [];
    array_unshift($log, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'time' => time()]);
    if (count($log) > 100) $log = array_slice($log, 0, 100);
    $pdo->prepare(
        "INSERT INTO {$prefix}config_plugins (plugin, name, value)
         VALUES ('local_dosman_ujian', 'seb_download_log', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute([json_encode($log)]);

    echo json_encode([
        'id'           => $qrId,
        'title'        => $title,
        'date'         => $dateLabel,
        'launch_url'   => $launchUrl,
        'qr_image_url' => $qrImageUrl,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
