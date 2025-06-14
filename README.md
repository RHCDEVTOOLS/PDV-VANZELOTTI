# PDV VANZELOTTI

This project is built with PHP and a MySQL database. You need PHP **7.4 or later** with the `pdo_mysql` extension enabled.

## Configuration

`config.php` looks for the following environment variables or values defined in a `config.ini` file in the project root:

```
DB_HOST     # Database host name
DB_NAME     # Database name
DB_USER     # Database user
DB_PASS     # Database password
```

Example `config.ini`:

```
DB_HOST=localhost
DB_NAME=exampledb
DB_USER=dbuser
DB_PASS=secret
```

Environment variables take precedence over values from `config.ini`. If neither is available, the application will stop with an error.

## Installing Dependencies

Make sure PHP and the PDO MySQL extension are installed. On Debian/Ubuntu you can run:

```
sudo apt-get install php php-mysql
```

## Running Locally

1. Configure your environment variables or create `config.ini` with the database credentials.
2. Start the built-in PHP server from the project root:

```
php -S localhost:8000
```

3. Open `http://localhost:8000/index.php` in your browser.


## Uploads Directory
The `Uploads/` folder must be writable by the web server.
