# PDV Vanzelotti

This project is a simple point of sale (PDV) system built with PHP.

## Requirements

- **PHP 7.4+** with PDO extension enabled.
- **MySQL 5.7+** or MariaDB equivalent.
- Web server such as Apache or the built‑in PHP development server.

## Database Setup

A full schema is available in `database/schema.sql`. The project also includes a migration script for the new `sales` table in `database/migrations/001_create_sales_table.sql`.

To initialise the database:

```bash
mysql -u <user> -p <database> < database/schema.sql
mysql -u <user> -p <database> < database/migrations/001_create_sales_table.sql
```

## Environment Variables

Database credentials can be provided via environment variables. A sample file is available at `.env.example`.

- `DB_HOST` – MySQL host (default `localhost`)
- `DB_NAME` – database name
- `DB_USER` – database user
- `DB_PASS` – database password

If these variables are not set, values defined in `config.php` are used.

## Running the Application

1. Install PHP and MySQL.
2. Configure your database credentials as above.
3. Start the PHP development server from the project root:

```bash
php -S localhost:8000
```

4. Open `http://localhost:8000/index.php` in your browser.

