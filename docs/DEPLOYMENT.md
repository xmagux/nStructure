# Bare-metal deployment

nStructure runs as a conventional PHP application. Docker, Laravel, and a
production Node.js process are not required.

## Recommended server

- Debian 13 or Ubuntu Server 24.04 LTS
- Nginx
- PHP 8.4 FPM with `curl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, and
  `zip`
- MySQL 8.4 LTS
- Composer 2

For a small internal installation, 2 CPU cores, 4 GB RAM, and 20 GB SSD are a
practical starting point. Increase storage according to backup retention and
future attachment requirements.

## Application installation

```bash
sudo mkdir -p /var/www/nstructure
sudo chown "$USER":www-data /var/www/nstructure
cd /var/www/nstructure

# Copy or clone the repository here, then install production dependencies.
composer install --no-dev --classmap-authoritative
cp .env.example .env
```

Set a long random `APP_KEY`, production URL, and database credentials in
`.env`. Use a dedicated database account with access only to the nStructure
database.

```bash
php bin/migrate.php
php bin/seed.php
```

The seed step installs demonstration data and should be skipped for an empty
production database.

## Nginx and PHP-FPM

Copy `deploy/nginx.conf` to `/etc/nginx/sites-available/nstructure`, replace
the example hostname and adjust the PHP-FPM socket if needed.

```bash
sudo ln -s /etc/nginx/sites-available/nstructure /etc/nginx/sites-enabled/nstructure
sudo nginx -t
sudo systemctl reload nginx
```

The web-server document root must point to `/var/www/nstructure/public`; the
project root must never be exposed directly.

## File permissions

The application currently stores sessions using PHP's configured session
handler and does not require writable project directories. Keep source files
read-only for the web-server user. Ensure PHP's session directory is writable
by the PHP-FPM service account.

## TLS

Expose production installations only over HTTPS. A certificate can be managed
with the organization's reverse proxy or Certbot. After TLS is enabled, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nstructure.example.com
SESSION_SECURE=true
```

## Updating

Back up the MySQL database before every update. Then place the application in
the organization's maintenance window, deploy the new files, and run:

```bash
composer install --no-dev --classmap-authoritative
php bin/migrate.php
sudo systemctl reload php8.4-fpm
```

Database migrations are tracked and applied only once.

## Verification

```bash
curl --fail https://nstructure.example.com/api/v1/health
```

The endpoint should return a JSON response with `status` set to `ok`.
