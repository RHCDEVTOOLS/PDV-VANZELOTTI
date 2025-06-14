<?php
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'u693220259_pdv';
$username = getenv('DB_USER') ?: 'u693220259_pdvadm';
$password = getenv('DB_PASS') ?: '6G&]N/vi~';

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