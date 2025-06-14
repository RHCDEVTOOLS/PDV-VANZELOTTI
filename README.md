# PDV-VANZELOTTI

This project requires database credentials provided via environment variables.

1. Copy `env.example` to `.env`.
2. Edit `.env` and set the values for:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Ensure these variables are available to PHP (e.g. by exporting them or using a tool that loads `.env` files).

`config.php` will read these variables using `$_ENV`/`getenv()` when establishing the database connection.
