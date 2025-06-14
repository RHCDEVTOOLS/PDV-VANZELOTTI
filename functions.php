<?php
session_start();

// Habilitar depuração (remover em produção)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log'); // Ajustar caminho conforme necessário

require_once 'config.php'; // Incluir configuração do banco de dados

// Verificar conexão com o banco
if (!isset($conn)) {
    error_log('Erro: Variável $conn não definida em config.php');
    http_response_code(500);
    exit;
}

// Carregar configurações
function getSettings($conn) {
    try {
        $stmt = $conn->query("SELECT * FROM settings WHERE id = 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($settings) {
            $settings['payment_methods'] = json_decode($settings['payment_methods'], true) ?: ['dinheiro'];
            $settings['name'] = $settings['name'] ?: 'Restaurante';
            $settings['address'] = $settings['address'] ?: 'Endereço do Restaurante';
            $settings['cnpj'] = $settings['cnpj'] ?: '00.000.000/0000-00';
            $settings['logo'] = $settings['logo'] ?: null;
            $settings['auto_print'] = $settings['auto_print'] ? 'enabled' : 'disabled';
            $settings['tables_count'] = $settings['tables_count'] ?: 10;
            $settings['service_tax_percent'] = $settings['service_tax_percent'] ?: 10;
        } else {
            $settings = [
                'name' => 'Restaurante',
                'address' => 'Endereço do Restaurante',
                'cnpj' => '00.000.000/0000-00',
                'logo' => null,
                'auto_print' => 'disabled',
                'tables_count' => 10,
                'service_tax_percent' => 10,
                'payment_methods' => ['dinheiro']
            ];
        }
        return $settings;
    } catch (PDOException $e) {
        error_log('Erro ao carregar configurações: ' . $e->getMessage());
        return [
            'name' => 'Restaurante',
            'address' => 'Endereço do Restaurante',
            'cnpj' => '00.000.000/0000-00',
            'logo' => null,
            'auto_print' => 'disabled',
            'tables_count' => 10,
            'service_tax_percent' => 10,
            'payment_methods' => ['dinheiro']
        ];
    }
}

// Carregar itens do menu
function getMenuItems($conn) {
    try {
        $stmt = $conn->query("SELECT * FROM menu_items WHERE status = 'active'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erro ao carregar menu_items: ' . $e->getMessage());
        return [];
    }
}

// Carregar clientes
function getCustomers($conn) {
    try {
        $stmt = $conn->query("SELECT * FROM customers WHERE status = 'active'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erro ao carregar clientes: ' . $e->getMessage());
        return [];
    }
}

// Carregar vendas
function getSales($conn) {
    try {
        $stmt = $conn->query("SELECT s.*, c.name as customer_name, c.address as customer_address 
                              FROM sales s 
                              LEFT JOIN customers c ON s.customer_id = c.id");
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sales as &$sale) {
            $sale['items'] = json_decode($sale['items_json'], true) ?: [];
        }
        return $sales;
    } catch (PDOException $e) {
        error_log('Erro ao carregar vendas: ' . $e->getMessage());
        return [];
    }
}

// Gerar ID do pedido
function generateOrderId($conn) {
    try {
        $stmt = $conn->query("SELECT MAX(id) as max_id FROM sales");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result['max_id'] ? $result['max_id'] + 1 : 1001);
    } catch (PDOException $e) {
        error_log('Erro ao gerar order_id: ' . $e->getMessage());
        return isset($_SESSION['last_order_id']) ? $_SESSION['last_order_id'] + 1 : 1001;
    }
}

// Adicionar item ao pedido
function addItemToOrder($conn, $itemId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ? AND status = 'active'");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            $found = false;
            foreach ($_SESSION['current_order']['items'] as &$orderItem) {
                if ($orderItem['id'] == $itemId) {
                    $orderItem['quantity']++;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $_SESSION['current_order']['items'][] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'price' => floatval($item['price']),
                    'quantity' => 1
                ];
            }
            updateOrderSummary($conn);
            return true;
        }
        error_log('Produto não encontrado ou inativo: ID ' . $itemId);
        return false;
    } catch (PDOException $e) {
        error_log('Erro em addItemToOrder: ' . $e->getMessage());
        return false;
    }
}

// Atualizar quantidade do item
function updateItemQuantity($conn, $itemId, $quantity) {
    try {
        foreach ($_SESSION['current_order']['items'] as &$item) {
            if ($item['id'] == $itemId) {
                $item['quantity'] = max(1, (int)$quantity);
                updateOrderSummary($conn);
                return true;
            }
        }
        error_log('Item não encontrado para atualização: ID ' . $itemId);
        return false;
    } catch (Exception $e) {
        error_log('Erro em updateItemQuantity: ' . $e->getMessage());
        return false;
    }
}

// Remover item do pedido
function removeItemFromOrder($conn, $itemId) {
    try {
        $_SESSION['current_order']['items'] = array_filter($_SESSION['current_order']['items'], function($item) use ($itemId) {
            return $item['id'] != $itemId;
        });
        $_SESSION['current_order']['items'] = array_values($_SESSION['current_order']['items']);
        updateOrderSummary($conn);
        return true;
    } catch (Exception $e) {
        error_log('Erro em removeItemFromOrder: ' . $e->getMessage());
        return false;
    }
}

// Criar novo pedido
function createNewOrder($conn) {
    try {
        if (isset($_SESSION['current_order']['id'])) {
            $_SESSION['last_order_id'] = $_SESSION['current_order']['id'];
        }
        $_SESSION['current_order'] = [
            'id' => generateOrderId($conn),
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
        return true;
    } catch (Exception $e) {
        error_log('Erro em createNewOrder: ' . $e->getMessage());
        return false;
    }
}

// Salvar venda
function saveSale($conn) {
    try {
        $order = $_SESSION['current_order'];
        if (empty($order['items'])) {
            error_log('Erro em saveSale: Pedido vazio');
            return false;
        }
        $subtotal = array_sum(array_map(function($item) {
            return $item['price'] * $item['quantity'];
        }, $order['items']));
        $settings = getSettings($conn);
        $serviceTax = $subtotal * ($settings['service_tax_percent'] / 100);
        $total = $subtotal + $serviceTax;
        $change = $order['paymentMethod'] === 'dinheiro' && $order['cash_received'] > 0 ? $order['cash_received'] - $total : 0;

        $items_json = json_encode($order['items'], JSON_UNESCAPED_UNICODE);
        if (!$items_json) {
            error_log('Erro em saveSale: Falha ao codificar items_json');
            return false;
        }

        // Usar NULL para id para AUTO_INCREMENT
        $stmt = $conn->prepare("INSERT INTO sales (date, table_number, items_json, payment_method, subtotal, service_tax, total, customer_id, amount_received, change_amount, status) 
                               VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')");
        $stmt->execute([
            $order['table'],
            $items_json,
            $order['paymentMethod'],
            $subtotal,
            $serviceTax,
            $total,
            $order['customer_id'] ?: null,
            $order['cash_received'],
            $change
        ]);
        $sale_id = $conn->lastInsertId();
        error_log('Venda salva com sucesso: ID ' . $sale_id);
        return $sale_id;
    } catch (PDOException $e) {
        error_log('Erro em saveSale: ' . $e->getMessage());
        return false;
    }
}

// Salvar produto
function saveProduct($conn, $data, $file) {
    try {
        $image = isset($data['current_image']) ? $data['current_image'] : null;
        if ($file && $file['error'] == UPLOAD_ERR_OK) {
            $allowed_types = ['image/png', 'image/jpeg'];
            if (!in_array($file['type'], $allowed_types)) {
                error_log('Erro em saveProduct: Tipo de arquivo inválido - ' . $file['type']);
                return ['status' => 'error', 'message' => 'Apenas imagens PNG ou JPEG são permitidas'];
            }
            if ($file['size'] > 5 * 1024 * 1024) { // Limite de 5MB
                error_log('Erro em saveProduct: Arquivo muito grande - ' . $file['size'] . ' bytes');
                return ['status' => 'error', 'message' => 'Imagem excede o limite de 5MB'];
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $image = 'product_' . time() . '.' . $ext;
            $upload_path = 'Uploads/' . $image;
            if (!is_writable('Uploads/')) {
                error_log('Erro em saveProduct: Pasta Uploads/ não tem permissão de escrita');
                return ['status' => 'error', 'message' => 'Erro de permissão na pasta Uploads/'];
            }
            if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                error_log('Erro em saveProduct: Falha ao mover arquivo para ' . $upload_path . ' - Erro: ' . $file['error']);
                return ['status' => 'error', 'message' => 'Erro ao fazer upload da imagem'];
            }
        }
        if (!empty($data['product_id'])) {
            $stmt = $conn->prepare("UPDATE menu_items SET name = ?, category = ?, price = ?, image = ?, status = ?, description = ? WHERE id = ?");
            $stmt->execute([
                $data['product_name'],
                $data['product_category'],
                $data['product_price'],
                $image,
                $data['product_status'],
                $data['product_description'],
                $data['product_id']
            ]);
        } else {
            $stmt = $conn->prepare("INSERT INTO menu_items (name, category, price, image, status, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['product_name'],
                $data['product_category'],
                $data['product_price'],
                $image,
                $data['product_status'],
                $data['product_description']
            ]);
        }
        return ['status' => 'success', 'message' => 'Produto salvo com sucesso!'];
    } catch (PDOException $e) {
        error_log('Erro em saveProduct: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao salvar produto'];
    }
}

// Excluir produto
function deleteProduct($conn, $itemId) {
    try {
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
        $stmt->execute([$itemId]);
        return $stmt->rowCount() > 0 ? ['status' => 'success', 'message' => 'Produto excluído com sucesso!'] : ['status' => 'error', 'message' => 'Produto não encontrado'];
    } catch (PDOException $e) {
        error_log('Erro em deleteProduct: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao excluir produto'];
    }
}

// Salvar cliente
function saveCustomer($conn, $data) {
    try {
        if (!empty($data['customer_id'])) {
            $stmt = $conn->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, cpf = ?, birthdate = ?, address = ?, status = ? WHERE id = ?");
            $stmt->execute([
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_email'],
                $data['customer_cpf'],
                $data['customer_birthdate'] ?: null,
                $data['customer_address'],
                $data['customer_status'],
                $data['customer_id']
            ]);
        } else {
            $stmt = $conn->prepare("INSERT INTO customers (name, phone, email, cpf, birthdate, address, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_email'],
                $data['customer_cpf'],
                $data['customer_birthdate'] ?: null,
                $data['customer_address'],
                $data['customer_status']
            ]);
        }
        return ['status' => 'success', 'message' => 'Cliente salvo com sucesso!'];
    } catch (PDOException $e) {
        error_log('Erro em saveCustomer: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao salvar cliente'];
    }
}

// Excluir cliente
function deleteCustomer($conn, $customerId) {
    try {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
        return $stmt->rowCount() > 0 ? ['status' => 'success', 'message' => 'Cliente excluído com sucesso!'] : ['status' => 'error', 'message' => 'Cliente não encontrado'];
    } catch (PDOException $e) {
        error_log('Erro em deleteCustomer: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao excluir cliente'];
    }
}

// Salvar configurações
function saveSettings($conn, $data, $file) {
    try {
        $logo = isset($data['current_logo']) ? $data['current_logo'] : null;
        if ($file && $file['error'] == UPLOAD_ERR_OK) {
            $allowed_types = ['image/png', 'image/jpeg'];
            if (!in_array($file['type'], $allowed_types)) {
                error_log('Erro em saveSettings: Tipo de arquivo inválido - ' . $file['type']);
                return ['status' => 'error', 'message' => 'Apenas imagens PNG ou JPEG são permitidas'];
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                error_log('Erro em saveSettings: Arquivo muito grande - ' . $file['size'] . ' bytes');
                return ['status' => 'error', 'message' => 'Imagem excede o limite de 5MB'];
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $logo = 'logo_' . time() . '.' . $ext;
            $upload_path = 'Uploads/' . $logo;
            if (!is_writable('Uploads/')) {
                error_log('Erro em saveSettings: Pasta Uploads/ não tem permissão de escrita');
                return ['status' => 'error', 'message' => 'Erro de permissão na pasta Uploads/'];
            }
            if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                error_log('Erro em saveSettings: Falha ao mover arquivo para ' . $upload_path . ' - Erro: ' . $file['error']);
                return ['status' => 'error', 'message' => 'Erro ao fazer upload do logo'];
            }
        }
        $paymentMethods = json_encode($data['payment_methods'] ?? ['dinheiro'], JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("INSERT INTO settings (id, name, logo, cnpj, address, service_tax_percent, auto_print, tables_count, payment_methods) 
                               VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE name = ?, logo = ?, cnpj = ?, address = ?, service_tax_percent = ?, auto_print = ?, tables_count = ?, payment_methods = ?");
        $stmt->execute([
            $data['restaurant_name'],
            $logo,
            $data['restaurant_cnpj'],
            $data['restaurant_address'],
            $data['service_tax_percent'],
            $data['auto_print'] ? 1 : 0,
            $data['tables_count'],
            $paymentMethods,
            $data['restaurant_name'],
            $logo,
            $data['restaurant_cnpj'],
            $data['restaurant_address'],
            $data['service_tax_percent'],
            $data['auto_print'] ? 1 : 0,
            $data['tables_count'],
            $paymentMethods
        ]);
        return ['status' => 'success', 'message' => 'Configurações salvas com sucesso!', 'logo' => $logo];
    } catch (PDOException $e) {
        error_log('Erro em saveSettings: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao salvar configurações'];
    }
}

// Cancelar venda
function cancelSale($conn, $saleId) {
    try {
        $stmt = $conn->prepare("UPDATE sales SET status = 'canceled' WHERE id = ? AND status = 'completed'");
        $stmt->execute([$saleId]);
        return $stmt->rowCount() > 0 ? ['status' => 'success', 'message' => 'Venda cancelada com sucesso!'] : ['status' => 'error', 'message' => 'Venda já cancelada ou não encontrada'];
    } catch (PDOException $e) {
        error_log('Erro em cancelSale: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao cancelar venda'];
    }
}

// Reiniciar numeração de pedidos
function resetOrderNumber($conn) {
    try {
        $_SESSION['last_order_id'] = 1000;
        createNewOrder($conn);
        return ['status' => 'success', 'message' => 'Numeração reiniciada com sucesso!'];
    } catch (Exception $e) {
        error_log('Erro em resetOrderNumber: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao reiniciar numeração'];
    }
}

// Obter produto
function getProduct($conn, $id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'error', 'message' => 'Produto não encontrado'];
    } catch (PDOException $e) {
        error_log('Erro em getProduct: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao buscar produto'];
    }
}

// Obter cliente
function getCustomer($conn, $id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'error', 'message' => 'Cliente não encontrado'];
    } catch (PDOException $e) {
        error_log('Erro em getCustomer: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao buscar cliente'];
    }
}

// Obter venda
function getSale($conn, $saleId) {
    try {
        $stmt = $conn->prepare("SELECT s.*, c.name as customer_name, c.address as customer_address 
                               FROM sales s 
                               LEFT JOIN customers c ON s.customer_id = c.id 
                               WHERE s.id = ?");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sale) {
            $sale['items'] = json_decode($sale['items_json'], true) ?: [];
            $sale['settings'] = getSettings($conn); // Incluir configurações para impressão
            return $sale;
        }
        return ['status' => 'error', 'message' => 'Venda não encontrada'];
    } catch (PDOException $e) {
        error_log('Erro em getSale: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao buscar venda'];
    }
}

// Exportar vendas como CSV
function exportSalesCSV($conn, $period, $payment, $startDate, $endDate) {
    try {
        $query = "SELECT s.id, s.date, s.table_number, s.payment_method, s.subtotal, s.service_tax, s.total, c.name AS customer_name, s.status FROM sales s LEFT JOIN customers c ON s.customer_id = c.id";
        $conditions = [];
        $params = [];

        if ($period === 'today') {
            $conditions[] = "DATE(s.date) = CURDATE()";
        } elseif ($period === 'week') {
            $conditions[] = "s.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($period === 'month') {
            $conditions[] = "s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        } elseif ($period === 'custom' && $startDate && $endDate) {
            $conditions[] = "s.date BETWEEN ? AND ?";
            $params[] = $startDate . ' 00:00:00';
            $params[] = $endDate . ' 23:59:59';
        }

        if ($payment !== 'all') {
            $conditions[] = "s.payment_method = ?";
            $params[] = $payment;
        }

        if ($conditions) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $query .= ' ORDER BY s.date DESC';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=sales_export.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Data', 'Mesa', 'Pagamento', 'Subtotal', 'Taxa Servico', 'Total', 'Cliente', 'Status']);
        foreach ($sales as $sale) {
            fputcsv($output, [
                $sale['id'],
                $sale['date'],
                $sale['table_number'],
                $sale['payment_method'],
                number_format((float)$sale['subtotal'], 2, '.', ''),
                number_format((float)$sale['service_tax'], 2, '.', ''),
                number_format((float)$sale['total'], 2, '.', ''),
                $sale['customer_name'],
                $sale['status']
            ]);
        }
        fclose($output);
        exit;
    } catch (PDOException $e) {
        error_log('Erro em exportSalesCSV: ' . $e->getMessage());
        http_response_code(500);
        echo 'Erro ao exportar vendas';
        exit;
    }
}

// Atualizar resumo do pedido
function updateOrderSummary($conn) {
    try {
        $order = $_SESSION['current_order'];
        $subtotal = array_sum(array_map(function($item) {
            return $item['price'] * $item['quantity'];
        }, $order['items']));
        $settings = getSettings($conn);
        $serviceTax = $subtotal * ($settings['service_tax_percent'] / 100);
        $total = $subtotal + $serviceTax;
        $change = $order['paymentMethod'] === 'dinheiro' && $order['cash_received'] > 0 ? $order['cash_received'] - $total : 0;

        $_SESSION['current_order']['subtotal'] = $subtotal;
        $_SESSION['current_order']['serviceTax'] = $serviceTax;
        $_SESSION['current_order']['total'] = $total;
        $_SESSION['current_order']['change'] = $change;

        return [
            'status' => 'success',
            'subtotal' => $subtotal,
            'serviceTax' => $serviceTax,
            'total' => $total,
            'change' => $change,
            'cash_received' => $order['cash_received']
        ];
    } catch (Exception $e) {
        error_log('Erro em updateOrderSummary: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao atualizar resumo'];
    }
}

// Inicializar pedido se não existir
if (!isset($_SESSION['current_order'])) {
    createNewOrder($conn);
}

// Processar ações AJAX
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'];
    error_log("Ação recebida: " . $action);

    switch ($action) {
        case 'init':
            echo json_encode([
                'status' => 'success',
                'settings' => getSettings($conn),
                'current_order' => $_SESSION['current_order']
            ]);
            break;

        case 'update_order_summary':
            echo json_encode(updateOrderSummary($conn));
            break;

        case 'add_item_to_order':
            $success = addItemToOrder($conn, $_GET['item_id'] ?? 0);
            echo json_encode($success ? ['status' => 'success'] : ['status' => 'error', 'message' => 'Produto não encontrado ou inativo']);
            break;

        case 'update_quantity':
            $success = updateItemQuantity($conn, $_GET['item_id'] ?? 0, $_GET['quantity'] ?? 1);
            echo json_encode($success ? ['status' => 'success'] : ['status' => 'error', 'message' => 'Item não encontrado']);
            break;

        case 'remove_item':
            $success = removeItemFromOrder($conn, $_GET['item_id'] ?? 0);
            echo json_encode($success ? ['status' => 'success'] : ['status' => 'error', 'message' => 'Item não encontrado']);
            break;

        case 'new_order':
            $success = createNewOrder($conn);
            echo json_encode($success ? ['status' => 'success', 'order_id' => $_SESSION['current_order']['id']] : ['status' => 'error', 'message' => 'Erro ao criar novo pedido']);
            break;

        case 'cancel_order':
            $success = createNewOrder($conn);
            echo json_encode($success ? ['status' => 'success', 'order_id' => $_SESSION['current_order']['id']] : ['status' => 'error', 'message' => 'Erro ao cancelar pedido']);
            break;

        case 'finish_order':
            $saleId = saveSale($conn);
            if ($saleId) {
                $settings = getSettings($conn);
                createNewOrder($conn);
                echo json_encode([
                    'status' => 'success',
                    'sale_id' => $saleId,
                    'order_id' => $_SESSION['current_order']['id'],
                    'auto_print' => $settings['auto_print']
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'O pedido está vazio ou ocorreu um erro']);
            }
            break;

        case 'set_customer':
        case 'update_customer': // Compatibilidade com ação antiga
            $_SESSION['current_order']['customer_id'] = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? intval($_GET['customer_id']) : null;
            echo json_encode(['status' => 'success']);
            break;

        case 'update_payment_method':
            $_SESSION['current_order']['paymentMethod'] = $_GET['method'] ?? 'dinheiro';
            echo json_encode(updateOrderSummary($conn));
            break;

        case 'update_cash_received':
            $_SESSION['current_order']['cash_received'] = floatval($_GET['amount'] ?? 0);
            echo json_encode(updateOrderSummary($conn));
            break;

        case 'update_table_number':
            $_SESSION['current_order']['table'] = intval($_GET['table'] ?? 1);
            echo json_encode(['status' => 'success']);
            break;

        case 'get_current_order':
            echo json_encode($_SESSION['current_order']);
            break;

        case 'get_product':
            echo json_encode(getProduct($conn, $_GET['id'] ?? 0));
            break;

        case 'get_customer':
            echo json_encode(getCustomer($conn, $_GET['id'] ?? 0));
            break;

        case 'get_sale':
            echo json_encode(getSale($conn, $_GET['id'] ?? 0));
            break;

        case 'reset_order_number':
            echo json_encode(resetOrderNumber($conn));
            break;

        case 'save_product':
            echo json_encode(saveProduct($conn, $_POST, $_FILES['product_image'] ?? null));
            break;

        case 'delete_product':
            echo json_encode(deleteProduct($conn, $_POST['item_id'] ?? 0));
            break;

        case 'save_customer':
            echo json_encode(saveCustomer($conn, $_POST));
            break;

        case 'delete_customer':
            echo json_encode(deleteCustomer($conn, $_POST['customer_id'] ?? 0));
            break;

        case 'cancel_sale':
            echo json_encode(cancelSale($conn, $_POST['sale_id'] ?? 0));
            break;

        case 'save_settings':
            echo json_encode(saveSettings($conn, $_POST, $_FILES['restaurant_logo'] ?? null));
            break;

        case 'export_sales':
            // Sobrescrever cabeçalhos JSON para saída CSV
            header('Content-Type: text/csv; charset=utf-8');
            exportSalesCSV(
                $conn,
                $_GET['period'] ?? 'today',
                $_GET['payment'] ?? 'all',
                $_GET['start_date'] ?? '',
                $_GET['end_date'] ?? ''
            );
            break;

        default:
            error_log('Ação inválida recebida: ' . $action);
            echo json_encode(['status' => 'error', 'message' => 'Ação inválida: ' . $action]);
            break;
    }
    exit;
}

// Se não for uma requisição AJAX, apenas inicializar (sem saída)
?>