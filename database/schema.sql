-- Base schema for PDV Vanzelotti

CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `logo` VARCHAR(255) NULL,
    `cnpj` VARCHAR(18) NULL,
    `address` VARCHAR(255) NULL,
    `service_tax_percent` DECIMAL(5,2) DEFAULT 0,
    `auto_print` TINYINT(1) DEFAULT 0,
    `tables_count` INT DEFAULT 10,
    `payment_methods` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `menu_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `category` VARCHAR(100) NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `image` VARCHAR(255) NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `cpf` VARCHAR(14) NULL,
    `birthdate` DATE NULL,
    `address` VARCHAR(255) NULL,
    `status` ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

