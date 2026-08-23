# nStructure

nStructure is a modern web application for documenting and visualizing physical fiber infrastructure, from locations and cable routes down to individual patch-panel ports.

## Stack

- PHP 8.3+
- Slim 4
- Twig
- MySQL 8.4 LTS
- Native browser JavaScript and CSS
- Nginx with PHP-FPM in production

No container runtime or Node.js production server is required.

## Local setup

1. Copy `.env.example` to `.env`.
2. Run `composer install`.
3. Keep `APP_DEMO_MODE=true` to explore the UI without a database.
4. Run `composer serve` and open `http://127.0.0.1:8080`.

## MySQL setup

1. Create a MySQL 8.4 database and application user.
2. Set `APP_DEMO_MODE=false` and update the `DB_*` values in `.env`.
3. Run `php bin/migrate.php`.
4. Run `php bin/seed.php` only when sample data is desired.

## Production

Point the web server document root to the `public` directory. Never expose the project root. Enable PHP OPcache, disable `APP_DEBUG`, use HTTPS, bind MySQL to a private interface, and keep verified off-host backups of the database and uploaded files.

## Code language

Source code, identifiers, database objects, and comments are English-only. Human-facing translations are isolated in `resources/translations`.

## License

Released under the [MIT License](LICENSE).
<img width="1910" height="899" alt="Zrzut ekranu 2026-08-23 172005" src="https://github.com/user-attachments/assets/9a2ec43e-392b-4699-a614-0eba55626497" />
<img width="1896" height="888" alt="Zrzut ekranu 2026-08-23 172153" src="https://github.com/user-attachments/assets/519525c8-3e40-4fb6-9474-4ab2ae531a3c" />
<img width="1810" height="898" alt="Zrzut ekranu 2026-08-23 172357" src="https://github.com/user-attachments/assets/60091daf-a329-4d09-bf74-c890ab8cf2e1" />
<img width="1770" height="872" alt="Zrzut ekranu 2026-08-23 172443" src="https://github.com/user-attachments/assets/d2e07dee-308b-4220-ab30-289ce81e3fd9" />
