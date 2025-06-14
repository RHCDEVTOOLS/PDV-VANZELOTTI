# PDV VANZELOTTI

This project requires database credentials for operation. `config.php` reads these values from environment variables. Define the following variables before running the application or provide them in a `config.ini` file located in the project root:

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

Environment variables take precedence over values from `config.ini`. If neither is available the application will stop with an error.

