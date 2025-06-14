<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

if (!isset($_SESSION['current_order'])) {
    $_SESSION['current_order'] = [
        'id' => generateOrderId(),
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

$settings = getSettings($conn);
$menuItems = getMenuItems($conn);
$customers = getCustomers($conn);
$categories = ['all', 'entradas', 'principais', 'bebidas', 'sobremesas'];
$currentCategory = isset($_GET['category']) ? $_GET['category'] : 'all';

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    switch ($_GET['ajax']) {
        case 'search_products':
            $search = $_GET['q'] ?? '';
            $category = $_GET['category'] ?? 'all';
            $status = $_GET['status'] ?? 'all';
            $filteredItems = array_filter($menuItems, function($item) use ($search, $category, $status) {
                $matchesSearch = empty($search) || stripos($item['name'], $search) !== false;
                $matchesCategory = $category === 'all' || $item['category'] === $category;
                $matchesStatus = $status === 'all' || $item['status'] === $status;
                return $matchesSearch && $matchesCategory && $matchesStatus;
            });
            echo json_encode(array_values($filteredItems));
            exit;
        case 'search_customers':
            $search = $_GET['q'] ?? '';
            $status = $_GET['status'] ?? 'all';
            $filteredCustomers = array_filter($customers, function($customer) use ($search, $status) {
                $matchesSearch = empty($search) || stripos($customer['name'], $search) !== false || stripos($customer['phone'], $search) !== false || stripos($customer['email'], $search) !== false;
                $matchesStatus = $status === 'all' || $customer['status'] === $status;
                return $matchesSearch && $matchesStatus;
            });
            echo json_encode(array_values($filteredCustomers));
            exit;
        case 'filter_sales':
            $period = $_GET['period'] ?? 'today';
            $payment = $_GET['payment'] ?? 'all';
            $startDate = $_GET['start_date'] ?? '';
            $endDate = $_GET['end_date'] ?? '';
            $sales = getSales($conn);
            $filteredSales = array_filter($sales, function($sale) use ($period, $payment, $startDate, $endDate) {
                $saleDate = strtotime($sale['date']);
                $today = strtotime(date('Y-m-d'));
                $matchesPeriod = true;
                if ($period === 'today') {
                    $matchesPeriod = date('Y-m-d', $saleDate) === date('Y-m-d');
                } elseif ($period === 'week') {
                    $matchesPeriod = $saleDate >= strtotime('-7 days', $today);
                } elseif ($period === 'month') {
                    $matchesPeriod = $saleDate >= strtotime('-30 days', $today);
                } elseif ($period === 'custom' && $startDate && $endDate) {
                    $matchesPeriod = $saleDate >= strtotime($startDate) && $saleDate <= strtotime($endDate . ' 23:59:59');
                }
                $matchesPayment = $payment === 'all' || $sale['payment_method'] === $payment;
                return $matchesPeriod && $matchesPayment;
            });
            $totalSalesCount = count($filteredSales);
            $totalSalesAmount = array_sum(array_column($filteredSales, 'total'));
            $averageSale = $totalSalesCount > 0 ? $totalSalesAmount / $totalSalesCount : 0;
            echo json_encode([
                'sales' => array_values($filteredSales),
                'totalSalesCount' => $totalSalesCount,
                'totalSalesAmount' => number_format($totalSalesAmount, 2, ',', '.'),
                'averageSale' => number_format($averageSale, 2, ',', '.')
            ]);
            exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        $response = ['status' => 'error', 'message' => 'Ação inválida'];
        switch ($_POST['action']) {
            case 'add_to_order':
                if (addItemToOrder($_POST['item_id'])) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao adicionar item ao pedido';
                }
                break;
            case 'update_quantity':
                if (updateItemQuantity($_POST['item_id'], $_POST['quantity'])) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao atualizar quantidade';
                }
                break;
            case 'remove_item':
                if (removeItemFromOrder($_POST['item_id'])) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao remover item';
                }
                break;
            case 'new_order':
                createNewOrder();
                $response = ['status' => 'success', 'order_id' => $_SESSION['current_order']['id']];
                break;
            case 'cancel_order':
                createNewOrder();
                $response = ['status' => 'success', 'order_id' => $_SESSION['current_order']['id']];
                break;
            case 'finish_order':
                if (count($_SESSION['current_order']['items']) > 0) {
                    $saleId = saveSale($conn);
                    $_SESSION['last_sale_id'] = $saleId;
                    createNewOrder();
                    $response = [
                        'status' => 'success',
                        'sale_id' => $saleId,
                        'auto_print' => $settings['auto_print']
                    ];
                } else {
                    $response['message'] = 'O pedido está vazio';
                }
                break;
            case 'save_product':
                if (saveProduct($conn, $_POST, $_FILES['product_image'] ?? null)) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao salvar produto';
                }
                break;
            case 'delete_product':
                if (deleteProduct($conn, $_POST['item_id'])) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao excluir produto';
                }
                break;
            case 'save_customer':
                if (saveCustomer($conn, $_POST)) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao salvar cliente';
                }
                break;
            case 'delete_customer':
                if (deleteCustomer($conn, $_POST['customer_id'])) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao excluir cliente';
                }
                break;
            case 'save_settings':
                if (saveSettings($conn, $_POST, $_FILES['logo_upload'] ?? null)) {
                    $settings = getSettings($conn);
                    $response = [
                        'status' => 'success',
                        'logo' => $settings['logo']
                    ];
                } else {
                    $response['message'] = 'Erro ao salvar configurações';
                }
                break;
            case 'cancel_sale':
                if (cancelSale($conn, $_POST['sale_id'])) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao cancelar venda';
                }
                break;
            case 'reset_order_number':
                if (resetOrderNumber($conn)) {
                    $response = ['status' => 'success'];
                } else {
                    $response['message'] = 'Erro ao reiniciar numeração';
                }
                break;
        }
        echo json_encode($response);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de PDV Restaurante</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.2s;
        }
        .btn-active:active {
            transform: translateY(0);
            box-shadow: none;
        }
        .tab-active {
            background-color: rgba(255, 255, 255, 0.3) !important;
        }
        .category-active {
            background-color: rgba(255, 255, 255, 0.3) !important;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        select, select option {
            color: black !important;
            background-color: white !important;
        }
        select:focus, select option:focus {
            color: black !important;
            background-color: white !important;
        }
        input[type="number"] {
            -moz-appearance: textfield;
        }
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid white;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* ... (outros estilos) */
        @media print {
            body * {
                visibility: hidden;
            }
            #receipt-print, #receipt-print * {
                visibility: visible;
            }
            #receipt-print {
                position: absolute;
                left: 0;
                top: 0;
                width: 80mm; /* Largura típica para impressoras térmicas */
                padding: 10px;
                background: white;
                color: black;
                font-family: 'Arial', sans-serif;
                font-size: 12px;
                line-height: 1.4;
            }
            #receipt-print img {
                max-width: 40mm;
                height: auto;
            }
            #receipt-print .border-t,
            #receipt-print .border-b {
                border-color: black !important;
            }
        }
        /* ... (outros estilos) *
        @media (max-width: 640px) {
            .grid-cols-1 {
                grid-template-columns: 1fr;
            }
            .lg\\:col-span-2 {
                grid-column: span 1;
            }
            .grid-cols-2 {
                grid-template-columns: 1fr;
            }
            .md\\:grid-cols-3 {
                grid-template-columns: 1fr;
            }
            .md\\:grid-cols-4 {
                grid-template-columns: 1fr;
            }
            .menu-item {
                flex-direction: row;
                align-items: center;
                text-align: left;
            }
            .menu-item img {
                width: 48px;
                height: 48px;
                margin-right: 10px;
            }
            .order-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .order-item .flex {
                margin-top: 10px;
            }
            .tab-btn {
                padding: 8px 12px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-green-900 via-green-800 to-green-700 flex items-start justify-center p-4 font-sans">
    
     <div class="w-full max-w-7xl mx-auto">
        <div class="flex flex-col sm:flex-row justify-between items-center mb-6 glass p-6">
            <div class="flex items-center mb-4 sm:mb-0">
                <?php if ($settings['logo']): ?>
                    <img id="restaurant-logo" src="Uploads/<?php echo htmlspecialchars($settings['logo']); ?>" alt="Logo" class="h-12 w-12 rounded-full mr-4">
                <?php endif; ?>
                <h1 id="restaurant-name" class="text-3xl font-bold text-white"><?php echo htmlspecialchars($settings['name']); ?></h1>
            </div>
            <div class="flex flex-wrap justify-center space-x-3">
                <button id="pos-tab" class="tab-btn bg-blue-600 text-white px-5 py-2 rounded-lg transition-all btn-hover btn-active tab-active" aria-label="Aba PDV">PDV</button>
                <button id="products-tab" class="tab-btn bg-green-600 text-white px-5 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Aba Produtos">Produtos</button>
                <button id="customers-tab" class="tab-btn bg-purple-600 text-white px-5 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Aba Clientes">Clientes</button>
                <button id="sales-tab" class="tab-btn bg-yellow-600 text-white px-5 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Aba Vendas">Vendas</button>
                <button id="settings-tab" class="tab-btn bg-gray-600 text-white px-5 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Aba Configurações">Configurações</button>
            </div>
        </div>

        <!-- POS Content -->
        <div id="pos-content" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="glass p-6 lg:col-span-2 animate-fade-in">
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4">
                    <h1 class="text-2xl font-bold text-white mb-2 sm:mb-0">Menu do Restaurante</h1>
                    <div class="flex space-x-2">
                        <button id="new-order-btn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Nova Venda">
                            <i class="fas fa-receipt mr-2"></i>Nova Venda
                        </button>
                        <button id="search-btn" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Buscar Itens">
                            <i class="fas fa-search mr-2"></i>Buscar
                        </button>
                    </div>
                </div>

                <div class="flex overflow-x-auto pb-2 mb-4 scrollbar-hide">
                    <?php foreach ($categories as $cat): ?>
                        <a href="?category=<?php echo $cat; ?>" class="category-btn bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg mr-2 transition-all btn-hover btn-active <?php echo $currentCategory === $cat ? 'category-active' : ''; ?>" data-category="<?php echo $cat; ?>" aria-label="Categoria <?php echo ucfirst($cat === 'all' ? 'Todos' : $cat); ?>">
                            <?php echo ucfirst($cat === 'all' ? 'Todos' : $cat); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="relative mb-4 hidden" id="search-bar">
                    <input type="text" id="menu-search" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2 pl-10" placeholder="Buscar item..." aria-label="Buscar item no menu">
                    <i class="fas fa-search absolute left-3 top-3 text-white opacity-70"></i>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 h-[500px] overflow-y-auto p-2 scrollbar-hide" id="menu-items">
                    <?php
                    $filteredItems = $currentCategory === 'all' ? $menuItems : array_filter($menuItems, fn($item) => $item['category'] === $currentCategory);
                    foreach ($filteredItems as $item) {
                        if ($item['status'] === 'active') {
                            $imageSrc = $item['image'] ? 'Uploads/' . htmlspecialchars($item['image']) : 'https://via.placeholder.com/100?text=Sem+Imagem';
                            echo '
                            <div class="menu-item bg-white bg-opacity-10 rounded-lg p-4 text-white flex flex-col items-center cursor-pointer transition-all hover:bg-opacity-20 btn-hover btn-active" data-id="' . $item['id'] . '" role="button" aria-label="Adicionar ' . htmlspecialchars($item['name']) . ' ao pedido">
                                <img src="' . $imageSrc . '" class="h-20 w-20 rounded object-cover mb-2" alt="' . htmlspecialchars($item['name']) . '">
                                <span class="text-sm font-medium text-center line-clamp-2">' . htmlspecialchars($item['name']) . '</span>
                                <span class="text-xs mt-1">R$ ' . number_format($item['price'], 2, ',', '.') . '</span>
                            </div>';
                        }
                    }
                    ?>
                </div>
            </div>

            <div class="glass p-6 animate-fade-in">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-white">Pedido #<?php echo sprintf('%04d', $_SESSION['current_order']['id']); ?></h2>
                    <div class="flex items-center">
                        <span class="text-white mr-2">Mesa:</span>
                        <select id="table-number" class="bg-white bg-opacity-20 text-white rounded px-2 py-1" aria-label="Selecionar número da mesa">
                            <?php for ($i = 1; $i <= $settings['tables_count']; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $_SESSION['current_order']['table'] == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4 h-[300px] overflow-y-auto scrollbar-hide" id="order-items">
                    <?php if (empty($_SESSION['current_order']['items'])): ?>
                        <div class="text-center text-white py-10">
                            <i class="fas fa-utensils text-3xl mb-2"></i>
                            <p>Adicione itens ao pedido</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($_SESSION['current_order']['items'] as $item): ?>
                            <div class="order-item flex justify-between items-center text-white mb-2 p-2 rounded" data-id="<?php echo $item['id']; ?>">
                                <div>
                                    <div class="line-clamp-1"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="text-sm opacity-70">R$ <?php echo number_format($item['price'], 2, ',', '.'); ?></div>
                                </div>
                                <div class="flex items-center">
                                    <button class="decrease-qty bg-red-500 hover:bg-red-600 text-white w-6 h-6 rounded flex items-center justify-center" aria-label="Diminuir quantidade">-</button>
                                    <input type="number" class="qty-input w-12 mx-2 text-center bg-transparent border border-white border-opacity-20 rounded" value="<?php echo $item['quantity']; ?>" min="1" aria-label="Quantidade">
                                    <button class="increase-qty bg-green-500 hover:bg-green-600 text-white w-6 h-6 rounded flex items-center justify-center" aria-label="Aumentar quantidade">+</button>
                                    <button class="remove-item bg-gray-500 hover:bg-gray-600 text-white w-6 h-6 rounded flex items-center justify-center ml-2" aria-label="Remover item"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                    <div class="flex justify-between text-white mb-2">
                        <span>Subtotal:</span>
                        <span id="subtotal">R$ <?php echo number_format($_SESSION['current_order']['subtotal'], 2, ',', '.'); ?></span>
                    </div>
                    <div class="flex justify-between text-white mb-2">
                        <span>Taxa de serviço (<?php echo $settings['service_tax_percent']; ?>%):</span>
                        <span id="service-tax">R$ <?php echo number_format($_SESSION['current_order']['serviceTax'], 2, ',', '.'); ?></span>
                    </div>
                    <div class="flex justify-between text-white font-bold text-lg">
                        <span>Total:</span>
                        <span id="total">R$ <?php echo number_format($_SESSION['current_order']['total'], 2, ',', '.'); ?></span>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-white mb-2">Forma de Pagamento:</label>
                    <select id="payment-method" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Selecionar forma de pagamento">
                        <?php foreach ($settings['payment_methods'] as $method): ?>
                            <option value="<?php echo $method; ?>" <?php echo $_SESSION['current_order']['paymentMethod'] === $method ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('-', ' ', $method)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="cash-payment" class="mb-4 <?php echo $_SESSION['current_order']['paymentMethod'] === 'dinheiro' ? '' : 'hidden'; ?>">
                    <label class="block text-white mb-2">Valor Recebido (R$):</label>
                    <input type="number" id="cash-received" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" step="0.01" min="0" value="<?php echo number_format($_SESSION['current_order']['cash_received'], 2, '.', ''); ?>" aria-label="Valor recebido em dinheiro">
                    <div class="flex justify-between text-white mt-2">
                        <span>Troco:</span>
                        <span id="change">R$ <?php echo number_format($_SESSION['current_order']['change'], 2, ',', '.'); ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button id="cancel-order-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition-all btn-hover btn-active" aria-label="Cancelar pedido">
                        <i class="fas fa-times mr-2"></i>Cancelar
                    </button>
                    <button id="finish-order-btn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-lg transition-all btn-hover btn-active" aria-label="Finalizar pedido">
                        <i class="fas fa-check mr-2"></i>Finalizar
                    </button>
                </div>
            </div>
        </div>

        <!-- Finalize Order Modal -->
        <div id="finalize-order-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" role="dialog" aria-labelledby="finalize-modal-title">
            <div class="glass p-6 rounded-lg w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="finalize-modal-title" class="text-xl font-bold text-white">Finalizar Pedido #<?php echo sprintf('%04d', $_SESSION['current_order']['id']); ?></h3>
                    <button id="close-finalize-modal-btn" class="text-white hover:text-gray-300" aria-label="Fechar modal"><i class="fas fa-times"></i></button>
                </div>
                <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                    <div class="mb-4">
                        <label class="block text-white mb-2">Cliente:</label>
                        <select id="customer-select" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Selecionar cliente">
                            <option value="">Venda Direta (Sem Cliente)</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo $_SESSION['current_order']['customer_id'] == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-white mb-2">Forma de Pagamento:</label>
                        <select id="finalize-payment-method" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Selecionar forma de pagamento">
                            <?php foreach ($settings['payment_methods'] as $method): ?>
                                <option value="<?php echo $method; ?>" <?php echo $_SESSION['current_order']['paymentMethod'] === $method ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(str_replace('-', ' ', $method)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="finalize-cash-payment" class="mb-4 <?php echo $_SESSION['current_order']['paymentMethod'] === 'dinheiro' ? '' : 'hidden'; ?>">
                        <label class="block text-white mb-2">Valor Recebido (R$):</label>
                        <input type="number" id="finalize-cash-received" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" step="0.01" min="0" value="<?php echo number_format($_SESSION['current_order']['cash_received'], 2, '.', ''); ?>" aria-label="Valor recebido em dinheiro">
                        <div class="flex justify-between text-white mt-2">
                            <span>Troco:</span>
                            <span id="finalize-change">R$ <?php echo number_format($_SESSION['current_order']['change'], 2, ',', '.'); ?></span>
                        </div>
                    </div>
                    <div class="flex justify-between text-white mb-2">
                        <span>Total:</span>
                        <span id="finalize-total">R$ <?php echo number_format($_SESSION['current_order']['total'], 2, ',', '.'); ?></span>
                    </div>
                </div>
                <div class="flex justify-between">
                    <button id="cancel-finalize-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Cancelar finalização">Cancelar</button>
                    <button id="confirm-finalize-btn" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Confirmar finalização">Confirmar</button>
                </div>
            </div>
        </div>

        <!-- Products Content -->
        <div id="products-content" class="glass p-6 animate-fade-in hidden">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-white mb-2 sm:mb-0">Gerenciamento de Produtos</h1>
                <button id="add-product-btn" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Adicionar produto">
                    <i class="fas fa-plus mr-2"></i>Adicionar Produto
                </button>
            </div>

            <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="block text-white mb-2">Categoria:</label>
                        <select id="product-category-filter" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Filtrar por categoria">
                            <option value="all">Todas</option>
                            <?php foreach (array_slice($categories, 1) as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo ucfirst($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-white mb-2">Buscar Produto:</label>
                        <div class="relative">
                            <input type="text" id="product-search" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2 pl-10" placeholder="Nome do produto..." aria-label="Buscar produto">
                            <i class="fas fa-search absolute left-3 top-3 text-white opacity-70"></i>
                        </div>
                    </div>
                    <div>
                        <label class="block text-white mb-2">Status:</label>
                        <select id="product-status-filter" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Filtrar por status">
                            <option value="all">Todos</option>
                            <option value="active">Ativos</option>
                            <option value="inactive">Inativos</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-white">
                        <thead>
                            <tr class="border-b border-white border-opacity-20">
                                <th class="text-left py-2">Imagem</th>
                                <th class="text-left py-2">Nome</th>
                                <th class="text-left py-2">Categoria</th>
                                <th class="text-left py-2">Preço</th>
                                <th class="text-left py-2">Status</th>
                                <th class="text-right py-2">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="products-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Customers Content -->
        <div id="customers-content" class="glass p-6 animate-fade-in hidden">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-white mb-2 sm:mb-0">Gerenciamento de Clientes</h1>
                <button id="add-customer-btn" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Adicionar cliente">
                    <i class="fas fa-plus mr-2"></i>Adicionar Cliente
                </button>
            </div>

            <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="md:col-span-2">
                        <label class="block text-white mb-2">Buscar Cliente:</label>
                        <div class="relative">
                            <input type="text" id="customer-search" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2 pl-10" placeholder="Nome, telefone ou email..." aria-label="Buscar cliente">
                            <i class="fas fa-search absolute left-3 top-3 text-white opacity-70"></i>
                        </div>
                    </div>
                    <div>
                        <label class="block text-white mb-2">Status:</label>
                        <select id="customer-status-filter" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Filtrar por status">
                            <option value="all">Todos</option>
                            <option value="active">Ativos</option>
                            <option value="inactive">Inativos</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-white">
                        <thead>
                            <tr class="border-b border-white border-opacity-20">
                                <th class="text-left py-2">Nome</th>
                                <th class="text-left py-2">Telefone</th>
                                <th class="text-left py-2">Email</th>
                                <th class="text-left py-2">Endereço</th>
                                <th class="text-left py-2">Status</th>
                                <th class="text-right py-2">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="customers-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sales Content -->
        <div id="sales-content" class="glass p-6 animate-fade-in hidden">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-white mb-2 sm:mb-0">Histórico de Vendas</h1>
                <button id="export-sales-btn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Exportar vendas">
                    <i class="fas fa-file-export mr-2"></i>Exportar
                </button>
            </div>

            <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="block text-white mb-2">Período:</label>
                        <select id="sales-period-filter" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Filtrar por período">
                            <option value="today">Hoje</option>
                            <option value="week">Esta Semana</option>
                            <option value="month">Este Mês</option>
                            <option value="custom">Personalizado</option>
                        </select>
                    </div>
                    <div id="custom-date-start" class="hidden">
                        <label class="block text-white mb-2">Data Início:</label>
                        <input type="date" id="sales-start-date" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" value="<?php echo date('Y-m-d'); ?>" aria-label="Data de início">
                    </div>
                    <div id="custom-date-end" class="hidden">
                        <label class="block text-white mb-2">Data Fim:</label>
                        <input type="date" id="sales-end-date" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" value="<?php echo date('Y-m-d'); ?>" aria-label="Data de fim">
                    </div>
                    <div>
                        <label class="block text-white mb-2">Forma de Pagamento:</label>
                        <select id="sales-payment-filter" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Filtrar por forma de pagamento">
                            <option value="all">Todas</option>
                            <?php foreach ($settings['payment_methods'] as $method): ?>
                                <option value="<?php echo $method; ?>"><?php echo ucfirst(str_replace('-', ' ', $method)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="bg-green-800 bg-opacity-50 rounded-lg p-3 text-center">
                        <div class="text-white text-sm">Total de Vendas</div>
                        <div class="text-white text-2xl font-bold" id="total-sales-count">0</div>
                    </div>
                    <div class="bg-blue-800 bg-opacity-50 rounded-lg p-3 text-center">
                        <div class="text-white text-sm">Valor Total</div>
                        <div class="text-white text-2xl font-bold" id="total-sales-amount">R$ 0,00</div>
                    </div>
                    <div class="bg-purple-800 bg-opacity-50 rounded-lg p-3 text-center">
                        <div class="text-white text-sm">Ticket Médio</div>
                        <div class="text-white text-2xl font-bold" id="average-sale">R$ 0,00</div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-white">
                        <thead>
                            <tr class="border-b border-white border-opacity-20">
                                <th class="text-left py-2">Data</th>
                                <th class="text-left py-2">Pedido #</th>
                                <th class="text-left py-2">Mesa</th>
                                <th class="text-left py-2">Itens</th>
                                <th class="text-left py-2">Pagamento</th>
                                <th class="text-right py-2">Total</th>
                                <th class="text-right py-2">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="sales-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Settings Content -->
        <div id="settings-content" class="glass p-6 animate-fade-in hidden">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-white mb-2 sm:mb-0">Configurações do Sistema</h1>
            </div>

            <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                <form id="settings-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="tab" value="settings">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h2 class="text-xl font-bold text-white mb-4">Configurações do Restaurante</h2>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Nome do Restaurante:</label>
                                <input type="text" name="restaurant_name" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" value="<?php echo htmlspecialchars($settings['name']); ?>" required aria-label="Nome do restaurante">
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Logo:</label>
                                <div class="flex items-center">
                                    <img id="logo-preview" src="<?php echo $settings['logo'] ? 'Uploads/' . htmlspecialchars($settings['logo']) : ''; ?>" alt="Pré-visualização do logo" class="h-16 w-16 rounded-full mr-3 <?php echo $settings['logo'] ? '' : 'hidden'; ?>">
                                    <input type="file" id="logo-upload" name="logo_upload" class="hidden" accept="image/png,image/jpeg" aria-label="Carregar logo">
                                    <button type="button" id="upload-logo-btn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Carregar logo">
                                        <i class="fas fa-upload mr-2"></i>Carregar Logo
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">CNPJ:</label>
                                <input type="text" name="restaurant_cnpj" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" placeholder="00.000.000/0000-00" value="<?php echo htmlspecialchars($settings['cnpj']); ?>" aria-label="CNPJ do restaurante">
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Endereço:</label>
                                <input type="text" name="restaurant_address" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" value="<?php echo htmlspecialchars($settings['address']); ?>" aria-label="Endereço do restaurante">
                            </div>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-white mb-4">Configurações do PDV</h2>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Taxa de Serviço (%):</label>
                                <input type="number" name="service_tax_percent" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" min="0" max="100" step="0.1" value="<?php echo $settings['service_tax_percent']; ?>" aria-label="Taxa de serviço">
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Impressão Automática:</label>
                                <select name="auto_print" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Impressão automática">
                                    <option value="enabled" <?php echo $settings['auto_print'] === 'enabled' ? 'selected' : ''; ?>>Ativada</option>
                                    <option value="disabled" <?php echo $settings['auto_print'] === 'disabled' ? 'selected' : ''; ?>>Desativada</option>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Número de Mesas:</label>
                                <input type="number" name="tables_count" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" min="1" max="50" value="<?php echo $settings['tables_count']; ?>" aria-label="Número de mesas">
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Formas de Pagamento:</label>
                                <div class="space-y-2">
                                    <?php $paymentMethods = ['dinheiro' => 'Dinheiro', 'cartao-debito' => 'Cartão de Débito', 'cartao-credito' => 'Cartão de Crédito', 'pix' => 'PIX', 'conta-cliente' => 'Conta do Cliente']; ?>
                                    <?php foreach ($paymentMethods as $key => $label): ?>
                                        <div class="flex items-center">
                                            <input type="checkbox" name="payment_methods[]" value="<?php echo $key; ?>" class="mr-2" <?php echo in_array($key, $settings['payment_methods']) ? 'checked' : ''; ?> aria-label="<?php echo $label; ?>">
                                            <label class="text-white"><?php echo $label; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="block text-white mb-2">Reiniciar Numeração de Pedidos:</label>
                                <button type="button" id="reset-order-number-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active w-full" aria-label="Reiniciar numeração de pedidos">
                                    <i class="fas fa-undo mr-2"></i>Reiniciar para #0001
                                </button>
                            </div>
                            <div class="mt-6">
                                <button type="submit" id="save-settings-btn" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active w-full" aria-label="Salvar configurações">
                                    <i class="fas fa-save mr-2"></i>Salvar Configurações
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Order Details Modal -->
        <div id="order-details-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" role="dialog" aria-labelledby="order-details-title">
            <div class="glass p-6 rounded-lg w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="order-details-title" class="text-xl font-bold text-white">Detalhes do Pedido #<span id="modal-order-number"></span></h3>
                    <button id="close-modal-btn" class="text-white hover:text-gray-300" aria-label="Fechar modal"><i class="fas fa-times"></i></button>
                </div>
                <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4 max-h-64 overflow-y-auto">
                    <div id="modal-order-items"></div>
                    <div class="border-t border-white border-opacity-20 pt-2 mt-2">
                        <div class="flex justify-between text-white mb-1">
                            <span>Subtotal:</span>
                            <span id="modal-subtotal"></span>
                        </div>
                        <div class="flex justify-between text-white">
                            <span>Taxa de serviço (<span id="modal-service-tax-percent"><?php echo $settings['service_tax_percent']; ?></span>%):</span>
                            <span id="modal-service-tax"></span>
                        </div>
                        <div class="flex justify-between text-white font-bold mt-2">
                            <span>Total:</span>
                            <span id="modal-total"></span>
                        </div>
                    </div>
                </div>
                <div class="flex justify-between">
                    <button id="cancel-sale-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Cancelar venda">
                        <i class="fas fa-times mr-2"></i>Cancelar Venda
                    </button>
                    <button id="print-order-btn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Imprimir pedido">
                        <i class="fas fa-print mr-2"></i>Imprimir
                    </button>
                </div>
            </div>
        </div>

        <!-- Product Form Modal -->
        <div id="product-form-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" role="dialog" aria-labelledby="product-form-title">
            <div class="glass p-6 rounded-lg w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="product-form-title" class="text-xl font-bold text-white">Adicionar Produto</h3>
                    <button id="close-product-form-btn" class="text-white hover:text-gray-300" aria-label="Fechar modal"><i class="fas fa-times"></i></button>
                </div>
                <form id="product-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="tab" value="products">
                    <input type="hidden" name="product_id" id="product-id">
                    <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                        <div class="mb-4">
                            <label class="block text-white mb-2">Nome do Produto:</label>
                            <input type="text" name="product_name" id="product-name" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" required aria-label="Nome do produto">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Categoria:</label>
                            <select name="product_category" id="product-category" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Selecionar categoria">
                                <?php foreach (array_slice($categories, 1) as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo ucfirst($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Preço (R$):</label>
                            <input type="number" name="product_price" id="product-price" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" min="0" step="0.01" required aria-label="Preço do produto">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Imagem do Produto:</label>
                            <div class="flex items-center">
                                <img id="product-image-preview" src="" alt="Pré-visualização da imagem" class="h-16 w-16 rounded mr-3 hidden">
                                <input type="file" id="product-image-upload" name="product_image" class="hidden" accept="image/png,image/jpeg" aria-label="Carregar imagem do produto">
                                <button type="button" id="upload-product-image-btn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Carregar imagem">
                                    <i class="fas fa-upload mr-2"></i>Carregar Imagem
                                </button>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Status:</label>
                            <select name="product_status" id="product-status" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Selecionar status">
                                <option value="active">Ativo</option>
                                <option value="inactive">Inativo</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Descrição:</label>
                            <textarea name="product_description" id="product-description" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" rows="3" aria-label="Descrição do produto"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-between">
                        <button type="button" id="cancel-product-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Cancelar">Cancelar</button>
                        <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Salvar produto">Salvar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Customer Form Modal -->
        <div id="customer-form-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" role="dialog" aria-labelledby="customer-form-title">
            <div class="glass p-6 rounded-lg w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="customer-form-title" class="text-xl font-bold text-white">Adicionar Cliente</h3>
                    <button id="close-customer-form-btn" class="text-white hover:text-gray-300" aria-label="Fechar modal"><i class="fas fa-times"></i></button>
                </div>
                <form id="customer-form">
                    <input type="hidden" name="action" value="save_customer">
                    <input type="hidden" name="tab" value="customers">
                    <input type="hidden" name="customer_id" id="customer-id">
                    <div class="bg-white bg-opacity-10 rounded-lg p-4 mb-4">
                        <div class="mb-4">
                            <label class="block text-white mb-2">Nome Completo:</label>
                            <input type="text" name="customer_name" id="customer-name" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" required aria-label="Nome do cliente">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Telefone:</label>
                            <input type="text" name="customer_phone" id="customer-phone" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" placeholder="(00) 00000-0000" required aria-label="Telefone do cliente">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Email:</label>
                            <input type="email" name="customer_email" id="customer-email" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Email do cliente">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">CPF:</label>
                            <input type="text" name="customer_cpf" id="customer-cpf" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" placeholder="000.000.000-00" aria-label="CPF do cliente">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Data de Nascimento:</label>
                            <input type="date" name="customer_birthdate" id="customer-birthdate" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Data de nascimento">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Endereço:</label>
                            <input type="text" name="customer_address" id="customer-address" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" placeholder="Rua, número, bairro, cidade" aria-label="Endereço do cliente">
                        </div>
                        <div class="mb-4">
                            <label class="block text-white mb-2">Status:</label>
                            <select name="customer_status" id="customer-status" class="w-full bg-white bg-opacity-20 text-white rounded px-3 py-2" aria-label="Selecionar status">
                                <option value="active">Ativo</option>
                                <option value="inactive">Inativo</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-between">
                        <button type="button" id="cancel-customer-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Cancelar">Cancelar</button>
                        <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-all btn-hover btn-active" aria-label="Salvar cliente">Salvar</button>
                    </div>
                </form>
            </div>
        </div>

    <!-- Receipt Print -->
    <div id="receipt-print" class="hidden">
        <div class="text-center mb-4">
            <?php if ($settings['logo']): ?>
                <img id="receipt-logo" src="Uploads/<?php echo htmlspecialchars($settings['logo']); ?>" alt="Logo" class="mx-auto mb-2">
            <?php endif; ?>
            <div id="receipt-restaurant-name" class="font-bold text-lg"><?php echo htmlspecialchars($settings['name']); ?></div>
            <div id="receipt-restaurant-address" class="text-sm"><?php echo htmlspecialchars($settings['address']); ?></div>
            <div id="receipt-restaurant-cnpj" class="text-sm">CNPJ: <?php echo htmlspecialchars($settings['cnpj']); ?></div>
        </div>
        <div class="border-t border-b border-black py-2 my-2 text-center">
            <div class="font-bold">COMPROVANTE DE PEDIDO</div>
            <div>Pedido #<span id="receipt-order-number"></span></div>
            <div>Data: <span id="receipt-order-date"></span></div>
            <div>Mesa: <span id="receipt-table-number"></span></div>
            <div id="receipt-customer-info" class="text-sm"></div>
        </div>
        <div id="receipt-items" class="mb-2"></div>
        <div class="border-t border-black pt-2">
            <div class="flex justify-between">
                <span>Subtotal:</span>
                <span id="receipt-subtotal"></span>
            </div>
            <div class="flex justify-between">
                <span>Taxa de serviço (<?php echo $settings['service_tax_percent']; ?>%):</span>
                <span id="receipt-service-tax"></span>
            </div>
            <div class="flex justify-between font-bold">
                <span>Total:</span>
                <span id="receipt-total"></span>
            </div>
            <div class="flex justify-between mt-1">
                <span>Forma de pagamento:</span>
                <span id="receipt-payment-method"></span>
            </div>
            <div id="receipt-cash-details" class="hidden">
                <div class="flex justify-between mt-1">
                    <span>Valor Recebido:</span>
                    <span id="receipt-cash-received"></span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>Troco:</span>
                    <span id="receipt-change"></span>
                </div>
            </div>
        </div>
        <div class="text-center mt-4 text-sm">
            Obrigado pela preferência!<br>
            Volte sempre!
        </div>
    </div>
    <!-- ... (outro HTML permanece inalterado) -->

    <script>
        
function formatCurrency(value) {
    return `R$ ${parseFloat(value).toFixed(2).replace('.', ',')}`;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function showLoading(button) {
    button.classList.add('loading');
    button.disabled = true;
}

function hideLoading(button) {
    button.classList.remove('loading');
    button.disabled = false;
}

function updateOrderSummary() {
    fetch('functions.php?action=update_order_summary')
        .then(response => response.json())
        .then(data => {
            document.getElementById('subtotal').textContent = formatCurrency(data.subtotal);
            document.getElementById('service-tax').textContent = formatCurrency(data.serviceTax);
            document.getElementById('total').textContent = formatCurrency(data.total);
            document.getElementById('finalize-total').textContent = formatCurrency(data.total);
            document.getElementById('change').textContent = formatCurrency(data.change);
            document.getElementById('finalize-change').textContent = formatCurrency(data.change);
            document.getElementById('receipt-subtotal').textContent = formatCurrency(data.subtotal);
            document.getElementById('receipt-service-tax').textContent = formatCurrency(data.serviceTax);
            document.getElementById('receipt-total').textContent = formatCurrency(data.total);
            document.getElementById('receipt-cash-received').textContent = formatCurrency(data.cash_received);
            document.getElementById('receipt-change').textContent = formatCurrency(data.change);
        });
}

document.addEventListener('DOMContentLoaded', () => {
    const tabs = {
        'pos-tab': 'pos-content',
        'products-tab': 'products-content',
        'customers-tab': 'customers-content',
        'sales-tab': 'sales-content',
        'settings-tab': 'settings-content'
    };

    Object.keys(tabs).forEach(tabId => {
        document.getElementById(tabId).addEventListener('click', () => {
            Object.values(tabs).forEach(contentId => {
                document.getElementById(contentId).classList.add('hidden');
            });
            document.getElementById(tabs[tabId]).classList.remove('hidden');
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('tab-active'));
            document.getElementById(tabId).classList.add('tab-active');
        });
    });

    function updateOrderItems() {
        const orderItemsDiv = document.getElementById('order-items');
        fetch('functions.php?action=get_current_order')
            .then(response => response.json())
            .then(data => {
                orderItemsDiv.innerHTML = '';
                if (data.items.length === 0) {
                    orderItemsDiv.innerHTML = `
                        <div class="text-center text-white py-10">
                            <i class="fas fa-utensils text-3xl mb-2"></i>
                            <p>Adicione itens ao pedido</p>
                        </div>`;
                } else {
                    data.items.forEach(item => {
                        const itemDiv = document.createElement('div');
                        itemDiv.className = 'order-item flex justify-between items-center text-white mb-2 p-2 rounded';
                        itemDiv.dataset.id = item.id;
                        itemDiv.innerHTML = `
                            <div>
                                <div class="line-clamp-1">${escapeHtml(item.name)}</div>
                                <div class="text-sm opacity-70">R$ ${parseFloat(item.price).toFixed(2).replace('.', ',')}</div>
                            </div>
                            <div class="flex items-center">
                                <button class="decrease-qty bg-red-500 hover:bg-red-600 text-white w-6 h-6 rounded flex items-center justify-center" aria-label="Diminuir quantidade">-</button>
                                <input type="number" class="qty-input w-12 mx-2 text-center bg-transparent border border-white border-opacity-20 rounded" value="${item.quantity}" min="1" aria-label="Quantidade">
                                <button class="increase-qty bg-green-500 hover:bg-green-600 text-white w-6 h-6 rounded flex items-center justify-center" aria-label="Aumentar quantidade">+</button>
                                <button class="remove-item bg-gray-500 hover:bg-gray-600 text-white w-6 h-6 rounded flex items-center justify-center ml-2" aria-label="Remover item"><i class="fas fa-trash"></i></button>
                            </div>`;
                        orderItemsDiv.appendChild(itemDiv);
                    });
                    attachOrderItemListeners();
                }
                updateReceiptItems(data.items);
            });
    }

    function updateReceiptItems(items) {
        const receiptItemsDiv = document.getElementById('receipt-items');
        receiptItemsDiv.innerHTML = '';
        items.forEach(item => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'flex justify-between';
            itemDiv.innerHTML = `
                <span>${item.quantity}x ${escapeHtml(item.name)}</span>
                <span>R$ ${(item.price * item.quantity).toFixed(2).replace('.', ',')}</span>`;
            receiptItemsDiv.appendChild(itemDiv);
        });
    }

    function attachOrderItemListeners() {
        document.querySelectorAll('.decrease-qty').forEach(btn => {
            btn.addEventListener('click', () => {
                const itemId = btn.parentElement.parentElement.dataset.id;
                const qtyInput = btn.parentElement.querySelector('.qty-input');
                const newQty = parseInt(qtyInput.value) - 1;
                if (newQty >= 1) {
                    qtyInput.value = newQty;
                    updateItemQuantity(itemId, newQty);
                } else {
                    removeItem(itemId);
                }
            });
        });

        document.querySelectorAll('.increase-qty').forEach(btn => {
            btn.addEventListener('click', () => {
                const itemId = btn.parentElement.parentElement.dataset.id;
                const qtyInput = btn.parentElement.querySelector('.qty-input');
                const newQty = parseInt(qtyInput.value) + 1;
                qtyInput.value = newQty;
                updateItemQuantity(itemId, newQty);
            });
        });

        document.querySelectorAll('.qty-input').forEach(input => {
            input.addEventListener('change', () => {
                const itemId = input.parentElement.parentElement.dataset.id;
                const newQty = parseInt(input.value);
                if (newQty >= 1) {
                    updateItemQuantity(itemId, newQty);
                } else {
                    removeItem(itemId);
                }
            });
        });

        document.querySelectorAll('.remove-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const itemId = btn.parentElement.parentElement.dataset.id;
                removeItem(itemId);
            });
        });
    }

    function updateItemQuantity(itemId, quantity) {
        fetch(`functions.php?action=update_quantity&item_id=${itemId}&quantity=${quantity}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    updateOrderSummary();
                } else {
                    alert(data.message || 'Erro ao atualizar quantidade');
                }
            });
    }

    function removeItem(itemId) {
        fetch(`functions.php?action=remove_item&item_id=${itemId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    updateOrderItems();
                    updateOrderSummary();
                } else {
                    alert(data.message || 'Erro ao remover item');
                }
            });
    }

    document.querySelectorAll('.menu-item').forEach(item => {
        item.addEventListener('click', () => {
            const itemId = item.dataset.id;
            fetch('functions.php?action=add_item_to_order&item_id=' + itemId)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        updateOrderItems();
                        updateOrderSummary();
                    } else {
                        alert(data.message || 'Erro ao adicionar item');
                    }
                });
        });
    });

    document.getElementById('new-order-btn').addEventListener('click', () => {
        fetch('functions.php?action=new_order')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('order-items').innerHTML = `
                        <div class="text-center text-white py-10">
                            <i class="fas fa-utensils text-3xl mb-2"></i>
                            <p>Adicione itens ao pedido</p>
                        </div>`;
                    document.querySelector('h2.text-xl.font-bold.text-white').textContent = `Pedido #${String(data.order_id).padStart(4, '0')}`;
                    updateOrderSummary();
                } else {
                    alert(data.message || 'Erro ao iniciar nova venda');
                }
            });
    });

    document.getElementById('cancel-order-btn').addEventListener('click', () => {
        if (confirm('Deseja cancelar o pedido atual?')) {
            fetch('functions.php?action=cancel_order')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('order-items').innerHTML = `
                            <div class="text-center text-white py-10">
                                <i class="fas fa-utensils text-3xl mb-2"></i>
                                <p>Adicione itens ao pedido</p>
                            </div>`;
                        document.querySelector('h2.text-xl.font-bold.text-white').textContent = `Pedido #${String(data.order_id).padStart(4, '0')}`;
                        updateOrderSummary();
                    } else {
                        alert(data.message || 'Erro ao cancelar o pedido');
                    }
                });
        }
    });

    document.getElementById('finish-order-btn').addEventListener('click', () => {
        const orderItems = document.querySelectorAll('.order-item');
        if (orderItems.length === 0) {
            alert('Adicione pelo menos um item ao pedido antes de finalizar.');
            return;
        }
        document.getElementById('finalize-order-modal').classList.remove('hidden');
        document.getElementById('finalize-payment-method').focus();
    });

    document.getElementById('close-finalize-modal-btn').addEventListener('click', () => {
        document.getElementById('finalize-order-modal').classList.add('hidden');
    });

    document.getElementById('cancel-finalize-btn').addEventListener('click', () => {
        document.getElementById('finalize-order-modal').classList.add('hidden');
    });

    document.getElementById('confirm-finalize-btn').addEventListener('click', async () => {
        const button = document.getElementById('confirm-finalize-btn');
        showLoading(button);
        try {
            const paymentMethod = document.getElementById('finalize-payment-method').value;
            const customerId = document.getElementById('customer-select').value || '';
            const cashReceived = parseFloat(document.getElementById('finalize-cash-received').value) || 0;
            const tableNumber = document.getElementById('table-number').value;
            const formData = new FormData();
            formData.append('action', 'finish_order');
            formData.append('payment_method', paymentMethod);
            formData.append('customer_id', customerId);
            formData.append('cash_received', cashReceived);
            formData.append('table_number', tableNumber);

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000); // Timeout de 10s

            const response = await fetch('index.php', {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }

            const data = await response.json();

            hideLoading(button);

            if (data.status === 'success') {
                document.getElementById('finalize-order-modal').classList.add('hidden');
                document.getElementById('order-items').innerHTML = `
                    <div class="text-center text-white py-10">
                        <i class="fas fa-utensils text-3xl mb-2"></i>
                        <p>Adicione itens ao pedido</p>
                    </div>`;
                document.querySelector('h2.text-xl.font-bold.text-white').textContent = `Pedido #${String(data.order_id).padStart(4, '0')}`;
                updateOrderSummary();
                updateSalesTable();
                if (data.auto_print === 'enabled') {
                    printOrder(data.sale_id);
                }
                alert('Venda finalizada com sucesso!');
            } else {
                alert(data.message || 'Erro ao finalizar a venda. Verifique os dados e tente novamente.');
            }
        } catch (error) {
            hideLoading(button);
            if (error.name === 'AbortError') {
                alert('A requisição demorou muito para responder. Verifique sua conexão e tente novamente.');
            } else {
                alert(`Erro ao finalizar a venda: ${error.message}. Por favor, tente novamente.`);
            }
        }
    });

    document.getElementById('table-number').addEventListener('change', (e) => {
        const tableNumber = e.target.value;
        fetch(`functions.php?action=update_table&table=${tableNumber}`)
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') {
                    alert(data.message || 'Erro ao atualizar número da mesa');
                }
            });
    });

    document.getElementById('payment-method').addEventListener('change', (e) => {
        const cashPaymentDiv = document.getElementById('cash-payment');
        if (e.target.value === 'dinheiro') {
            cashPaymentDiv.classList.remove('hidden');
            document.getElementById('cash-received').focus();
        } else {
            cashPaymentDiv.classList.add('hidden');
        }
        fetch(`functions.php?action=update_payment_method&method=${e.target.value}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    updateOrderSummary();
                } else {
                    alert(data.message || 'Erro ao atualizar forma de pagamento');
                }
            });
    });

    document.getElementById('finalize-payment-method').addEventListener('change', (e) => {
        const cashPaymentDiv = document.getElementById('finalize-cash-payment');
        if (e.target.value === 'dinheiro') {
            cashPaymentDiv.classList.remove('hidden');
            document.getElementById('finalize-cash-received').focus();
        } else {
            cashPaymentDiv.classList.add('hidden');
        }
    });

    document.getElementById('cash-received').addEventListener('input', (e) => {
        const cashReceived = parseFloat(e.target.value) || 0;
        fetch(`functions.php?action=update_cash_received&cash=${cashReceived}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    updateOrderSummary();
                } else {
                    alert(data.message || 'Erro ao atualizar valor recebido');
                }
            });
    });

    document.getElementById('finalize-cash-received').addEventListener('input', (e) => {
        const cashReceived = parseFloat(e.target.value) || 0;
        const total = parseFloat(document.getElementById('finalize-total').textContent.replace('R$ ', '').replace(',', '.'));
        const change = cashReceived >= total ? cashReceived - total : 0;
        document.getElementById('finalize-change').textContent = formatCurrency(change);
    });

    document.getElementById('customer-select').addEventListener('change', (e) => {
        const customerId = e.target.value;
        fetch(`functions.php?action=update_customer&customer_id=${customerId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') {
                    alert(data.message || 'Erro ao atualizar cliente');
                }
            });
    });

    document.getElementById('search-btn').addEventListener('click', () => {
        const searchBar = document.getElementById('search-bar');
        searchBar.classList.toggle('hidden');
        if (!searchBar.classList.contains('hidden')) {
            document.getElementById('menu-search').focus();
        }
    });

    const debouncedSearch = debounce((value) => {
        const menuItemsDiv = document.getElementById('menu-items');
        fetch(`index.php?ajax=search_products&q=${encodeURIComponent(value)}&category=all&status=active`)
            .then(response => response.json())
            .then(items => {
                menuItemsDiv.innerHTML = '';
                items.forEach(item => {
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'menu-item bg-white bg-opacity-10 rounded-lg p-4 text-white flex flex-col items-center cursor-pointer transition-all hover:bg-opacity-20 btn-hover btn-active';
                    itemDiv.dataset.id = item.id;
                    itemDiv.setAttribute('role', 'button');
                    itemDiv.setAttribute('aria-label', `Adicionar ${escapeHtml(item.name)} ao pedido`);
                    itemDiv.innerHTML = `
                        <img src="${item.image ? 'Uploads/' + item.image : 'https://via.placeholder.com/100?text=Sem+Imagem'}" class="h-20 w-20 rounded object-cover mb-2" alt="${escapeHtml(item.name)}">
                        <span class="text-sm font-medium text-center line-clamp-2">${escapeHtml(item.name)}</span>
                        <span class="text-xs mt-1">R$ ${parseFloat(item.price).toFixed(2).replace('.', ',')}</span>`;
                    menuItemsDiv.appendChild(itemDiv);
                });
                document.querySelectorAll('.menu-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const itemId = item.dataset.id;
                        fetch('functions.php?action=add_item_to_order&item_id=' + itemId)
                            .then(response => response.json())
                            .then(data => {
                                if (data.status === 'success') {
                                    updateOrderItems();
                                    updateOrderSummary();
                                } else {
                                    alert(data.message || 'Erro ao adicionar item');
                                }
                            });
                    });
                });
            });
    }, 300);

    document.getElementById('menu-search').addEventListener('input', (e) => {
        debouncedSearch(e.target.value);
    });

    document.getElementById('add-product-btn').addEventListener('click', () => {
        document.getElementById('product-form-title').textContent = 'Adicionar Produto';
        document.getElementById('product-form').reset();
        document.getElementById('product-id').value = '';
        document.getElementById('product-image-preview').classList.add('hidden');
        document.getElementById('product-form-modal').classList.remove('hidden');
        document.getElementById('product-name').focus();
    });

    document.getElementById('close-product-form-btn').addEventListener('click', () => {
        document.getElementById('product-form-modal').classList.add('hidden');
    });

    document.getElementById('cancel-product-btn').addEventListener('click', () => {
        document.getElementById('product-form-modal').classList.add('hidden');
    });

    document.getElementById('upload-product-image-btn').addEventListener('click', () => {
        document.getElementById('product-image-upload').click();
    });

    document.getElementById('product-image-upload').addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) {
            if (!['image/png', 'image/jpeg'].includes(file.type)) {
                alert('Por favor, selecione uma imagem PNG ou JPEG.');
                e.target.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = (event) => {
                document.getElementById('product-image-preview').src = event.target.result;
                document.getElementById('product-image-preview').classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    });

    document.getElementById('product-form').addEventListener('submit', (e) => {
        e.preventDefault();
        showLoading(document.querySelector('#product-form button[type="submit"]'));
        const formData = new FormData(e.target);
        fetch('index.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                hideLoading(document.querySelector('#product-form button[type="submit"]'));
                if (data.status === 'success') {
                    document.getElementById('product-form-modal').classList.add('hidden');
                    updateProductsTable();
                } else {
                    alert(data.message || 'Erro ao salvar o produto');
                }
            });
    });

    function updateProductsTable() {
        const category = document.getElementById('product-category-filter').value;
        const status = document.getElementById('product-status-filter').value;
        const search = document.getElementById('product-search').value;
        fetch(`index.php?ajax=search_products&q=${encodeURIComponent(search)}&category=${category}&status=${status}`)
            .then(response => response.json())
            .then(items => {
                const tableBody = document.getElementById('products-table-body');
                tableBody.innerHTML = '';
                items.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="py-2">
                            <img src="${item.image ? 'Uploads/' + item.image : 'https://via.placeholder.com/50?text=Sem+Imagem'}" class="h-10 w-10 rounded object-cover" alt="${escapeHtml(item.name)}">
                        </td>
                        <td class="py-2">${escapeHtml(item.name)}</td>
                        <td class="py-2">${escapeHtml(item.category)}</td>
                        <td class="py-2">R$ ${parseFloat(item.price).toFixed(2).replace('.', ',')}</td>
                        <td class="py-2">${item.status === 'active' ? 'Ativo' : 'Inativo'}</td>
                        <td class="py-2 text-right">
                            <button class="edit-product-btn bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded mr-1" data-id="${item.id}" aria-label="Editar produto"><i class="fas fa-edit"></i></button>
                            <button class="delete-product-btn bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded" data-id="${item.id}" aria-label="Excluir produto"><i class="fas fa-trash"></i></button>
                        </td>`;
                    tableBody.appendChild(row);
                });
                attachProductTableListeners();
            });
    }

    function attachProductTableListeners() {
        document.querySelectorAll('.edit-product-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const itemId = btn.dataset.id;
                fetch(`functions.php?action=get_product&id=${itemId}`)
                    .then(response => response.json())
                    .then(item => {
                        document.getElementById('product-form-title').textContent = 'Editar Produto';
                        document.getElementById('product-id').value = item.id;
                        document.getElementById('product-name').value = item.name;
                        document.getElementById('product-category').value = item.category;
                        document.getElementById('product-price').value = item.price;
                        document.getElementById('product-status').value = item.status;
                        document.getElementById('product-description').value = item.description || '';
                        if (item.image) {
                            document.getElementById('product-image-preview').src = 'Uploads/' + item.image;
                            document.getElementById('product-image-preview').classList.remove('hidden');
                        } else {
                            document.getElementById('product-image-preview').classList.add('hidden');
                        }
                        document.getElementById('product-form-modal').classList.remove('hidden');
                        document.getElementById('product-name').focus();
                    });
            });
        });

        document.querySelectorAll('.delete-product-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Deseja excluir este produto?')) {
                    showLoading(btn);
                    const itemId = btn.dataset.id;
                    const formData = new FormData();
                    formData.append('action', 'delete_product');
                    formData.append('item_id', itemId);
                    fetch('index.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            hideLoading(btn);
                            if (data.status === 'success') {
                                updateProductsTable();
                            } else {
                                alert(data.message || 'Erro ao excluir o produto');
                            }
                        });
                }
            });
        });
    }

    const debouncedProductSearch = debounce(() => {
        updateProductsTable();
    }, 300);

    document.getElementById('product-search').addEventListener('input', debouncedProductSearch);
    document.getElementById('product-category-filter').addEventListener('change', updateProductsTable);
    document.getElementById('product-status-filter').addEventListener('change', updateProductsTable);

    document.getElementById('add-customer-btn').addEventListener('click', () => {
        document.getElementById('customer-form-title').textContent = 'Adicionar Cliente';
        document.getElementById('customer-form').reset();
        document.getElementById('customer-id').value = '';
        document.getElementById('customer-form-modal').classList.remove('hidden');
        document.getElementById('customer-name').focus();
    });

    document.getElementById('close-customer-form-btn').addEventListener('click', () => {
        document.getElementById('customer-form-modal').classList.add('hidden');
    });

    document.getElementById('cancel-customer-btn').addEventListener('click', () => {
        document.getElementById('customer-form-modal').classList.add('hidden');
    });

    document.getElementById('customer-form').addEventListener('submit', (e) => {
        e.preventDefault();
        showLoading(document.querySelector('#customer-form button[type="submit"]'));
        const formData = new FormData(e.target);
        fetch('index.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                hideLoading(document.querySelector('#customer-form button[type="submit"]'));
                if (data.status === 'success') {
                    document.getElementById('customer-form-modal').classList.add('hidden');
                    updateCustomersTable();
                    updateCustomerSelect();
                } else {
                    alert(data.message || 'Erro ao salvar o cliente');
                }
            });
    });

    function applyPhoneMask(input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 11) value = value.slice(0, 11);
        if (value.length <= 11) {
            value = value.replace(/(\d{2})(\d)/, '($1) $2');
            value = value.replace(/(\d{5})(\d)/, '$1-$2');
        }
        input.value = value;
    }

    function applyCpfMask(input) {
        let value = input.value.replace(/\D/g, '');
        if (value.length > 11) value = value.slice(0, 11);
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d)/, '$1.$2');
        value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        input.value = value;
    }

    document.getElementById('customer-phone').addEventListener('input', (e) => {
        applyPhoneMask(e.target);
    });

    document.getElementById('customer-cpf').addEventListener('input', (e) => {
        applyCpfMask(e.target);
    });

    function updateCustomersTable() {
        const search = document.getElementById('customer-search').value;
        const status = document.getElementById('customer-status-filter').value;
        fetch(`index.php?ajax=search_customers&q=${encodeURIComponent(search)}&status=${status}`)
            .then(response => response.json())
            .then(customers => {
                const tableBody = document.getElementById('customers-table-body');
                tableBody.innerHTML = '';
                customers.forEach(customer => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="py-2">${escapeHtml(customer.name)}</td>
                        <td class="py-2">${escapeHtml(customer.phone)}</td>
                        <td class="py-2">${customer.email ? escapeHtml(customer.email) : '-'}</td>
                        <td class="py-2">${customer.address ? escapeHtml(customer.address.substring(0, 30) + (customer.address.length > 30 ? '...' : '')) : '-'}</td>
                        <td class="py-2">${customer.status === 'active' ? 'Ativo' : 'Inativo'}</td>
                        <td class="py-2 text-right">
                            <button class="edit-customer-btn bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded mr-1" data-id="${customer.id}" aria-label="Editar cliente"><i class="fas fa-edit"></i></button>
                            <button class="delete-customer-btn bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded" data-id="${customer.id}" aria-label="Excluir cliente"><i class="fas fa-trash"></i></button>
                        </td>`;
                    tableBody.appendChild(row);
                });
                attachCustomerTableListeners();
            });
    }

    function updateCustomerSelect() {
        fetch('functions.php?action=get_customers')
            .then(response => response.json())
            .then(customers => {
                const customerSelect = document.getElementById('customer-select');
                customerSelect.innerHTML = '<option value="">Venda Direta (Sem Cliente)</option>';
                customers.forEach(customer => {
                    const option = document.createElement('option');
                    option.value = customer.id;
                    option.textContent = customer.name;
                    customerSelect.appendChild(option);
                });
            });
    }

    function attachCustomerTableListeners() {
        document.querySelectorAll('.edit-customer-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const customerId = btn.dataset.id;
                fetch(`functions.php?action=get_customer&id=${customerId}`)
                    .then(response => response.json())
                    .then(customer => {
                        document.getElementById('customer-form-title').textContent = 'Editar Cliente';
                        document.getElementById('customer-id').value = customer.id;
                        document.getElementById('customer-name').value = customer.name;
                        document.getElementById('customer-phone').value = customer.phone;
                        document.getElementById('customer-email').value = customer.email || '';
                        document.getElementById('customer-cpf').value = customer.cpf || '';
                        document.getElementById('customer-birthdate').value = customer.birthdate || '';
                        document.getElementById('customer-address').value = customer.address || '';
                        document.getElementById('customer-status').value = customer.status;
                        document.getElementById('customer-form-modal').classList.remove('hidden');
                        document.getElementById('customer-name').focus();
                    });
            });
        });

        document.querySelectorAll('.delete-customer-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Deseja excluir este cliente?')) {
                    showLoading(btn);
                    const customerId = btn.dataset.id;
                    const formData = new FormData();
                    formData.append('action', 'delete_customer');
                    formData.append('customer_id', customerId);
                    fetch('index.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            hideLoading(btn);
                            if (data.status === 'success') {
                                updateCustomersTable();
                                updateCustomerSelect();
                            } else {
                                alert(data.message || 'Erro ao excluir o cliente');
                            }
                        });
                }
            });
        });
    }

    const debouncedCustomerSearch = debounce(() => {
        updateCustomersTable();
    }, 300);

    document.getElementById('customer-search').addEventListener('input', debouncedCustomerSearch);
    document.getElementById('customer-status-filter').addEventListener('change', updateCustomersTable);

    function updateSalesTable() {
        const period = document.getElementById('sales-period-filter').value;
        const payment = document.getElementById('sales-payment-filter').value;
        const startDate = document.getElementById('sales-start-date').value;
        const endDate = document.getElementById('sales-end-date').value;
        fetch(`index.php?ajax=filter_sales&period=${period}&payment=${payment}&start_date=${startDate}&end_date=${endDate}`)
            .then(response => response.json())
            .then(data => {
                const tableBody = document.getElementById('sales-table-body');
                tableBody.innerHTML = '';
                data.sales.forEach(sale => {
                    const itemsCount = sale.items ? sale.items.reduce((sum, item) => sum + parseInt(item.quantity), 0) : 0;
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="py-2">${new Date(sale.date).toLocaleDateString('pt-BR')}</td>
                        <td class="py-2">${String(sale.id).padStart(4, '0')}</td>
                        <td class="py-2">${sale.table}</td>
                        <td class="py-2">${itemsCount} itens</td>
                        <td class="py-2">${sale.payment_method.replace('-', ' ').replace(/\b\w/g, c => c.toUpperCase())}</td>
                        <td class="py-2 text-right">R$ ${parseFloat(sale.total).toFixed(2).replace('.', ',')}</td>
                        <td class="py-2 text-right">
                            <button class="view-sale-btn bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded mr-1" data-id="${sale.id}" aria-label="Ver detalhes da venda"><i class="fas fa-eye"></i></button>
                            <button class="cancel-sale-btn bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded" data-id="${sale.id}" ${sale.status === 'canceled' ? 'disabled' : ''} aria-label="Cancelar venda"><i class="fas fa-times"></i></button>
                        </td>`;
                    tableBody.appendChild(row);
                });
                document.getElementById('total-sales-count').textContent = data.totalSalesCount;
                document.getElementById('total-sales-amount').textContent = data.totalSalesAmount;
                document.getElementById('average-sale').textContent = data.averageSale;
                attachSalesTableListeners();
            });
    }

    function attachSalesTableListeners() {
        document.querySelectorAll('.view-sale-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const saleId = btn.dataset.id;
                fetch(`functions.php?action=get_sale&id=${saleId}`)
                    .then(response => response.json())
                    .then(sale => {
                        document.getElementById('modal-order-number').textContent = String(sale.id).padStart(4, '0');
                        document.getElementById('modal-order-items').innerHTML = sale.items.map(item => `
                            <div class="flex justify-between text-white mb-1">
                                <span>${item.quantity}x ${escapeHtml(item.name)}</span>
                                <span>R$ ${(item.price * item.quantity).toFixed(2).replace('.', ',')}</span>
                            </div>`).join('');
                        document.getElementById('modal-subtotal').textContent = formatCurrency(sale.subtotal);
                        document.getElementById('modal-service-tax').textContent = formatCurrency(sale.service_tax);
                        document.getElementById('modal-total').textContent = formatCurrency(sale.total);
                        document.getElementById('cancel-sale-btn').dataset.id = sale.id;
                        document.getElementById('cancel-sale-btn').disabled = sale.status === 'canceled';
                        document.getElementById('order-details-modal').classList.remove('hidden');
                    });
            });
        });

        document.querySelectorAll('.cancel-sale-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Deseja cancelar esta venda?')) {
                    showLoading(btn);
                    const saleId = btn.dataset.id;
                    const formData = new FormData();
                    formData.append('action', 'cancel_sale');
                    formData.append('sale_id', saleId);
                    fetch('index.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            hideLoading(btn);
                            if (data.status === 'success') {
                                updateSalesTable();
                                document.getElementById('order-details-modal').classList.add('hidden');
                            } else {
                                alert(data.message || 'Erro ao cancelar a venda');
                            }
                        });
                }
            });
        });
    }

    document.getElementById('close-modal-btn').addEventListener('click', () => {
        document.getElementById('order-details-modal').classList.add('hidden');
    });

    document.getElementById('cancel-sale-btn').addEventListener('click', () => {
        if (confirm('Deseja cancelar esta venda?')) {
            showLoading(document.getElementById('cancel-sale-btn'));
            const saleId = document.getElementById('cancel-sale-btn').dataset.id;
            const formData = new FormData();
            formData.append('action', 'cancel_sale');
            formData.append('sale_id', saleId);
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    hideLoading(document.getElementById('cancel-sale-btn'));
                    if (data.status === 'success') {
                        updateSalesTable();
                        document.getElementById('order-details-modal').classList.add('hidden');
                    } else {
                        alert(data.message || 'Erro ao cancelar a venda');
                    }
                });
        }
    });

    document.getElementById('print-order-btn').addEventListener('click', () => {
        const saleId = document.getElementById('cancel-sale-btn').dataset.id;
        printOrder(saleId);
    });

    function printOrder(saleId) {
        fetch(`functions.php?action=get_sale&id=${saleId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erro HTTP: ${response.status}`);
                }
                return response.json();
            })
            .then(sale => {
                // Preencher todos os campos do comprovante
                document.getElementById('receipt-order-number').textContent = String(sale.id).padStart(4, '0');
                document.getElementById('receipt-order-date').textContent = new Date(sale.date).toLocaleString('pt-BR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                document.getElementById('receipt-table-number').textContent = sale.table;
                
                // Preencher itens
                const receiptItemsDiv = document.getElementById('receipt-items');
                receiptItemsDiv.innerHTML = '';
                if (sale.items && Array.isArray(sale.items)) {
                    sale.items.forEach(item => {
                        const itemDiv = document.createElement('div');
                        itemDiv.className = 'flex justify-between';
                            itemDiv.innerHTML = `
                                <span>${item.quantity}x ${escapeHtml(item.name)}</span>
                                <span>R$ ${(parseFloat(item.price) * parseInt(item.quantity)).toFixed(2).replace('.', ',')}</span>`;
                        receiptItemsDiv.appendChild(itemDiv);
                    });
                } else {
                    receiptItemsDiv.innerHTML = '<div>Nenhum item encontrado</div>';
                }

                // Preencher totais
                document.getElementById('receipt-subtotal').textContent = formatCurrency(sale.subtotal || 0);
                document.getElementById('receipt-service-tax').textContent = formatCurrency(sale.service_tax || 0);
                document.getElementById('receipt-total').textContent = formatCurrency(sale.total || 0);
                document.getElementById('receipt-payment-method').textContent = sale.payment_method ? sale.payment_method.replace('-', ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Não especificado';

                // Detalhes de pagamento em dinheiro
                const cashDetailsDiv = document.getElementById('receipt-cash-details');
                if (sale.payment_method === 'dinheiro') {
                    cashDetailsDiv.classList.remove('hidden');
                    document.getElementById('receipt-cash-received').textContent = formatCurrency(sale.cash_received || 0);
                    document.getElementById('receipt-change').textContent = formatCurrency(sale.change || 0);
                } else {
                    cashDetailsDiv.classList.add('hidden');
                }

                // Preencher informações do cliente
                if (sale.customer_id) {
                    return fetch(`functions.php?action=get_customer&id=${sale.customer_id}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`Erro HTTP: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(customer => {
                            document.getElementById('receipt-customer-info').innerHTML = `
                                Cliente: ${customer.name ? escapeHtml(customer.name) : 'Não especificado'}<br>
                                Endereço: ${customer.address ? escapeHtml(customer.address) : 'Não informado'}`;
                            // Atraso para garantir atualização do DOM
                            setTimeout(() => {
                                window.print();
                            }, 100);
                        });
                } else {
                    document.getElementById('receipt-customer-info').innerHTML = 'Cliente: Venda Direta';
                    // Atraso para garantir atualização do DOM
                    setTimeout(() => {
                        window.print();
                    }, 100);
                }
            })
            .catch(error => {
                alert(`Erro ao preparar impressão: ${error.message}. Verifique a conexão e tente novamente.`);
            });
    }

    document.getElementById('sales-period-filter').addEventListener('change', (e) => {
        const customStart = document.getElementById('custom-date-start');
        const customEnd = document.getElementById('custom-date-end');
        if (e.target.value === 'custom') {
            customStart.classList.remove('hidden');
            customEnd.classList.remove('hidden');
        } else {
            customStart.classList.add('hidden');
            customEnd.classList.add('hidden');
        }
        updateSalesTable();
    });

    document.getElementById('sales-payment-filter').addEventListener('change', updateSalesTable);
    document.getElementById('sales-start-date').addEventListener('change', updateSalesTable);
    document.getElementById('sales-end-date').addEventListener('change', updateSalesTable);

    document.getElementById('export-sales-btn').addEventListener('click', () => {
        const period = document.getElementById('sales-period-filter').value;
        const payment = document.getElementById('sales-payment-filter').value;
        const startDate = document.getElementById('sales-start-date').value;
        const endDate = document.getElementById('sales-end-date').value;
        window.location.href = `functions.php?action=export_sales&period=${period}&payment=${payment}&start_date=${startDate}&end_date=${end_date}`;
    });

    document.getElementById('upload-logo-btn').addEventListener('click', () => {
        document.getElementById('logo-upload').click();
    });

    document.getElementById('logo-upload').addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) {
            if (!['image/png', 'image/jpeg'].includes(file.type)) {
                alert('Por favor, selecione uma imagem PNG ou JPEG.');
                e.target.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = (event) => {
                document.getElementById('logo-preview').src = event.target.result;
                document.getElementById('logo-preview').classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    });

    document.getElementById('settings-form').addEventListener('submit', (e) => {
        e.preventDefault();
        showLoading(document.getElementById('save-settings-btn'));
        const formData = new FormData(e.target);
        fetch('index.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                hideLoading(document.getElementById('save-settings-btn'));
                if (data.status === 'success') {
                    document.getElementById('restaurant-name').textContent = formData.get('restaurant_name');
                    document.getElementById('receipt-restaurant-name').textContent = formData.get('restaurant_name');
                    document.getElementById('receipt-restaurant-address').textContent = formData.get('restaurant_address');
                    document.getElementById('receipt-restaurant-cnpj').textContent = 'CNPJ: ' + formData.get('restaurant_cnpj');
                    if (data.logo) {
                        document.getElementById('restaurant-logo').src = 'Uploads/' + data.logo;
                        document.getElementById('receipt-logo').src = 'Uploads/' + data.logo;
                        document.getElementById('restaurant-logo').classList.remove('hidden');
                        document.getElementById('receipt-logo').classList.remove('hidden');
                    }
                    alert('Configurações salvas com sucesso!');
                } else {
                    alert(data.message || 'Erro ao salvar as configurações');
                }
            });
    });

    document.getElementById('reset-order-number-btn').addEventListener('click', () => {
        if (confirm('Deseja reiniciar a numeração dos pedidos para #0001?')) {
            showLoading(document.getElementById('reset-order-number-btn'));
            const formData = new FormData();
            formData.append('action', 'reset_order_number');
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    hideLoading(document.getElementById('reset-order-number-btn'));
                    if (data.status === 'success') {
                        alert('Numeração de pedidos reiniciada com sucesso!');
                    } else {
                        alert(data.message || 'Erro ao reiniciar a numeração');
                    }
                });
        }
    });

    // Initialize tables
    updateOrderItems();
    updateOrderSummary();
    updateProductsTable();
    updateCustomersTable();
    updateSalesTable();
});
</script>
</body>
</html>