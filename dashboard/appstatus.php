<?php
/**
 * Returns JSON map of {userid: {lastping, ipaddress}} from local_dosman_ujian_appstatus.
 * Reads DB credentials directly from Moodle config.php — no bootstrap needed.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_MOODLE_TOKEN'] ?? ''));
if ($token === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $config_path = dirname(__DIR__) . '/config.php';
    if (!file_exists($config_path)) {
        echo json_encode(['error' => 'config.php not found']);
        exit;
    }
    $cfg = file_get_contents($config_path);

    preg_match('/\$CFG->dbhost\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m);
    $host = $m[1] ?? 'localhost';

    preg_match('/\$CFG->dbname\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m);
    $dbname = $m[1] ?? 'moodle';

    preg_match('/\$CFG->dbuser\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m);
    $user = $m[1] ?? 'root';

    preg_match('/\$CFG->dbpass\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m);
    $pass = $m[1] ?? '';

    preg_match('/\$CFG->prefix\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m);
    $prefix = $m[1] ?? 'mdl_';

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $chk = $pdo->prepare(
        "SELECT id FROM `{$prefix}external_tokens`
          WHERE token = ?
            AND (validuntil = 0 OR validuntil > UNIX_TIMESTAMP())
          LIMIT 1"
    );
    $chk->execute([$token]);
    if ($chk->fetchColumn() === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $stmt = $pdo->query("SELECT userid, lastping, ipaddress FROM `{$prefix}local_dosman_ujian_appstatus`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row['userid']] = [
            'lastping'  => (int)$row['lastping'],
            'ipaddress' => $row['ipaddress'] ?? '',
        ];
    }
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
