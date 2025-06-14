-- Migration: create sales table
CREATE TABLE IF NOT EXISTS `sales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `date` DATETIME NOT NULL,
    `table_number` INT NOT NULL,
    `items_json` JSON NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL,
    `subtotal` DECIMAL(10,2) NOT NULL,
    `service_tax` DECIMAL(10,2) NOT NULL,
    `total` DECIMAL(10,2) NOT NULL,
    `customer_id` INT NULL,
    `amount_received` DECIMAL(10,2) NOT NULL,
    `change_amount` DECIMAL(10,2) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

