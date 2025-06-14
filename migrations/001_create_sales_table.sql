CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATETIME NOT NULL,
    table_number INT NOT NULL,
    items_json TEXT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    service_tax DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    customer_id INT NULL,
    amount_received DECIMAL(10,2) DEFAULT 0,
    change_amount DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(20) NOT NULL
);
