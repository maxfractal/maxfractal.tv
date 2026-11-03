<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'http://localhost:3000' || $origin === 'http://127.0.0.1:3000') {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Support maxfractal.tv under XAMPP's /dev/ directory.
$apiPrefix = '/dev/maxfractal.tv/api';

if ($path === $apiPrefix || str_starts_with($path, $apiPrefix . '/')) {
    $route = ltrim(substr($path, strlen($apiPrefix)), '/');
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$route = trim($route, '/');

if ($route === 'health' || $route === 'health/') {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'service' => 'maxfractal.tv API']);
    exit;
}

if ($route === 'db-health' || $route === 'db-health/') {
    $configFile = dirname(__DIR__) . '/.env';
    $config = is_file($configFile) ? parse_ini_file($configFile, false, INI_SCANNER_RAW) : [];
    $config = is_array($config) ? $config : [];
    $host = $config['DB_HOST'] ?? '127.0.0.1';
    $port = $config['DB_PORT'] ?? '3306';
    $name = $config['DB_NAME'] ?? 'maxfractal_tv';
    $user = $config['DB_USER'] ?? 'root';
    $password = $config['DB_PASSWORD'] ?? '';

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $pdo->query('SELECT 1');
        echo json_encode(['status' => 'ok', 'database' => 'connected']);
    } catch (Throwable $error) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'database' => 'unavailable']);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
