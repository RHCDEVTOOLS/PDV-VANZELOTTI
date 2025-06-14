<?php
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');

if (!$host || !$dbname || !$username || $password === false) {
    $configFile = __DIR__ . '/config.ini';
    if (is_readable($configFile)) {
        $config = parse_ini_file($configFile);
        $host = $host ?: ($config['DB_HOST'] ?? null);
        $dbname = $dbname ?: ($config['DB_NAME'] ?? null);
        $username = $username ?: ($config['DB_USER'] ?? null);
        if ($password === false || $password === null) {
            $password = $config['DB_PASS'] ?? null;
        }
    }
}

if (!$host || !$dbname || !$username || $password === null || $password === false) {
    die('Database configuration not provided.');
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES utf8");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações padrão
if (!isset($_SESSION['current_order'])) {
    $_SESSION['current_order'] = [
        'id' => null,
        'table' => 1,
        'items' => [],
        'subtotal' => 0,
        'serviceTax' => 0,
        'total' => 0,
        'paymentMethod' => 'dinheiro',
        'status' => 'open',
        'customer_id' => null,
        'cash_received' => 0,
        'change' => 0
    ];
}

?>
