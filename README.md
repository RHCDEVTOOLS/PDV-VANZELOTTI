# PDV Vanzelotti

This project is a simple PHP based Point of Sale (PDV) system for restaurants. It relies on MySQL for data storage and requires a web server with PHP support.

## Requirements

- PHP 7.4+ with PDO extension for MySQL
- MySQL database server
- Web server (Apache, Nginx or PHP built-in server)

## Setup

1. Clone the repository:
   ```bash
   git clone <repository-url>
   ```
2. Configure database access in `config.php` by setting `$host`, `$dbname`, `$username` and `$password`.
3. Create the MySQL database and tables. A basic structure is shown below:
   ```sql
   CREATE TABLE settings (
       id INT PRIMARY KEY,
       name VARCHAR(100),
       logo VARCHAR(255),
       cnpj VARCHAR(20),
       address VARCHAR(255),
       service_tax_percent INT,
       auto_print TINYINT(1),
       tables_count INT,
       payment_methods TEXT
   );

   CREATE TABLE menu_items (
       id INT AUTO_INCREMENT PRIMARY KEY,
       name VARCHAR(100),
       category VARCHAR(50),
       price DECIMAL(10,2),
       image VARCHAR(255),
       status VARCHAR(20),
       description TEXT
   );

   CREATE TABLE customers (
       id INT AUTO_INCREMENT PRIMARY KEY,
       name VARCHAR(100),
       phone VARCHAR(20),
       email VARCHAR(100),
       cpf VARCHAR(20),
       birthdate DATE,
       address VARCHAR(255),
       status VARCHAR(20)
   );

   CREATE TABLE sales (
       id INT AUTO_INCREMENT PRIMARY KEY,
       date DATETIME,
       table_number INT,
       items_json TEXT,
       payment_method VARCHAR(20),
       subtotal DECIMAL(10,2),
       service_tax DECIMAL(10,2),
       total DECIMAL(10,2),
       customer_id INT,
       amount_received DECIMAL(10,2),
       change_amount DECIMAL(10,2),
       status VARCHAR(20)
   );
   ```
4. Make sure the `Uploads/` directory is writable by the web server if you plan to upload images or a logo.

## Running

You can use the PHP built-in server during development:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000/index.php` in your browser.

## Logging

Errors are logged to `php_errors.log`. There is also a `test_log.php` script that writes to `error.log` for testing logging configuration.

## License

See [LICENSE](LICENSE) for license information.
