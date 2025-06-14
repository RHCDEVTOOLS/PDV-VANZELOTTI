<?php
$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
$username = $_ENV['DB_USER'] ?? getenv('DB_USER');
$password = $_ENV['DB_PASS'] ?? getenv('DB_PASS');

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