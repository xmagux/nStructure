# nStructure

nStructure is a modern web application for documenting and visualizing physical fiber infrastructure, from locations and cable routes down to individual patch-panel ports.

## Stack

- PHP 8.3+ (`pdo_mysql`, `mbstring`, `json` extensions)
- Composer 2
- Slim 4
- Twig
- MySQL 8.4 LTS
- Native browser JavaScript and CSS (no Node.js or container runtime needed — built assets are already committed)

## Quick start — try it instantly (no database)

Demo mode ships sample data in memory, so you can explore the whole UI with zero setup.

```bash
git clone https://github.com/xmagux/nStructure.git
cd nStructure
cp .env.example .env
composer install
composer serve
```

Open **http://127.0.0.1:8080** — `APP_DEMO_MODE=true` is already set in `.env.example`, so nothing else is required. Changes made in this mode are not saved between requests.

## Quick start — full install with your own data (MySQL)

```bash
git clone https://github.com/xmagux/nStructure.git
cd nStructure
cp .env.example .env
composer install

# Create the database and application user (adjust the password):
mysql -u root -p -e "
  CREATE DATABASE nstructure CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'nstructure'@'localhost' IDENTIFIED BY 'change_me';
  GRANT ALL PRIVILEGES ON nstructure.* TO 'nstructure'@'localhost';
  FLUSH PRIVILEGES;
"

# Edit .env: set APP_DEMO_MODE=false and match DB_DATABASE/DB_USERNAME/DB_PASSWORD to what you just created

php bin/migrate.php
php bin/create-user.php you@example.com "Your Name" "a-strong-password"
composer serve
```

Open **http://127.0.0.1:8080/login** and sign in with the account you just created.

- `php bin/migrate.php` applies every pending migration in `database/migrations/`, in order. It is safe to re-run — already-applied migrations are skipped. Pass a single filename (e.g. `php bin/migrate.php 004_port_remote_endpoint.sql`) to apply only that one.
- `php bin/create-user.php <email> "<name>" <password>` creates a login account. There is no self-service registration by design — every account is created this way, either by you or by an already-logged-in user from the **Account** page.
- `php bin/seed.php` optionally loads sample locations, racks, and cables into your MySQL database if you want to explore with realistic data instead of starting empty. Skip it for a real, empty installation.

## Production

Point the web server document root to the `public` directory. Never expose the project root. Enable PHP OPcache, disable `APP_DEBUG`, use HTTPS, bind MySQL to a private interface, and keep verified off-host backups of the database and uploaded files. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for a full bare-metal walkthrough (Nginx, PHP-FPM, systemd).

## Code language

Source code, identifiers, database objects, and comments are English-only. Human-facing translations are isolated in `resources/translations` (English and Polish are included).

## License

Released under the [MIT License](LICENSE).
