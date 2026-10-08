# Pak Gas POS

Custom PHP 8.1+ LPG POS, inventory and ledger application for a standard XAMPP/LAMP deployment.

This repository is **Composer-free at runtime**. It uses the small PSR-4-compatible loader in `bootstrap.php`, so a production server does not need Composer or a `vendor/` directory.

## 1. Server requirements

- PHP 8.1 or newer
- PHP extensions: PDO, PDO_MySQL, BCMath, mbstring, fileinfo, zip, xml
- MySQL 8.x or MariaDB 10.6+
- Apache with `mod_rewrite` enabled
- HTTPS recommended for production
- The Apache document root **must be the repository `public/` directory**. Do not expose the repository root.

Check PHP modules:

```bash
php -v
php -m
```

There is no Composer command in the installation procedure.

## 2. Manual installation without Composer

See **[MANUAL_INSTALLATION.md](MANUAL_INSTALLATION.md)** for the complete Windows/XAMPP and Linux/LAMP procedure.

The short version is:

1. Copy the application files to the server.
2. Make `public/` the Apache document root.
3. Copy `.env.example` to `.env` and configure the database and `APP_URL`.
4. Ensure PHP has PDO/MySQL and BCMath enabled.
5. Run `php bin/migrate.php` to create the schema and apply pending migrations.
6. Set `SEED_ADMIN_PASSWORD` in `.env` and run `php bin/seed.php`.
7. Open the configured application URL and change the seeded administrator password immediately.

On first login-page request, the application also creates the configured database when it is missing and applies pending migrations. The configured database user therefore needs permission to create the database when using this automatic setup path.

## 3. Database configuration

Example `.env`:

```dotenv
APP_ENV=production
APP_URL=http://localhost/pak-gas-app/public
APP_NAME="Pak Gas POS"
TIMEZONE=Asia/Karachi

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pak_gas
DB_USER=pak_gas_app
DB_PASS=CHANGE_THIS_STRONG_PASSWORD

SESSION_TIMEOUT=1800
SESSION_SECURE_COOKIE=true

SEED_ADMIN_USERNAME=admin
SEED_ADMIN_PASSWORD=CHANGE_THIS_BEFORE_SEED
```

Never commit `.env`.

If the application is installed below a subdirectory, put that path in `APP_URL`. For example:

```dotenv
APP_URL=http://localhost/pak-gas-app/public
```

Deployment URLs are read from `.env`; they must not be hard-coded into application PHP or JavaScript.

## 4. Apache / XAMPP deployment

Recommended XAMPP layout:

```text
C:\xampp\htdocs\pak-gas-app\
    app\
    bin\
    database\
    public\
    storage\
    .env
```

Configure Apache so the document root is:

```text
C:\xampp\htdocs\pak-gas-app\public
```

Example VirtualHost:

```apache
<VirtualHost *:80>
    ServerName pak-gas.local
    DocumentRoot "C:/xampp/htdocs/pak-gas-app/public"

    <Directory "C:/xampp/htdocs/pak-gas-app/public">
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog "logs/pak-gas-error.log"
    CustomLog "logs/pak-gas-access.log" combined
</VirtualHost>
```

Add this to the Windows hosts file:

```text
127.0.0.1 pak-gas.local
```

Then open:

```text
http://pak-gas.local/
```

Never use the repository root as the public document root.

## 5. Linux / LAMP

Set the Apache VirtualHost document root to:

```text
/var/www/pak-gas-app/public
```

Enable rewrite:

```bash
sudo a2enmod rewrite
sudo systemctl reload apache2
```

Ensure only required storage paths are writable by Apache:

```bash
sudo chown -R www-data:www-data storage
sudo chmod -R u+rwX storage
```

Do not make the entire repository writable by the web server.

## 6. Database migrations and seed

From the repository root:

```bash
php bin/migrate.php
php bin/seed.php
```

Migrations run in filename order and record applied filenames in the `migrations` table.

**Never edit an already-applied migration.** Add a new numbered migration for schema changes.

The seeded administrator is forced to change its password on first login. Use a strong temporary password in `.env`, log in, change it, and then remove or rotate the seed password.

## 7. Verify the deployment

Verify at least:

1. Login page loads without PHP/JavaScript errors.
2. Administrator can log in.
3. First login requires password change.
4. Dashboard and Settings load.
5. Parties, Cylinder Groups, Cylinders, Rates, Opening Stock, Cash Counter, and POS load.
6. POS transaction type is populated from the database setting.
7. Switching transaction type refreshes the applicable cylinder list and line-item behavior.
8. Empty Cylinder Sale shows only empty shop cylinders.
9. Gas Sale allows gas input and optional filled-cylinder sale.
10. Opening Stock creates the expected cylinders and movements.
11. Cash POS posting requires an open counter session.
12. Unauthorized routes return server-side HTTP 403.
13. `php bin/test.php` completes successfully.
14. Apache exposes only `public/`.

## 8. Development server

For development only:

```bash
php -S localhost:8080 -t public
```

Set:

```dotenv
APP_URL=http://localhost:8080
```

The PHP built-in server is not recommended for production.

## 9. Production checklist

- [ ] `APP_ENV=production`
- [ ] Strong database password
- [ ] Strong administrator password
- [ ] HTTPS enabled
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] Apache document root is `public/`
- [ ] `.env` is not web-accessible
- [ ] `storage/` is not web-accessible
- [ ] Database migrations completed
- [ ] Administrator password changed
- [ ] Backups configured and restore tested
- [ ] No demo/test transactions remain
- [ ] Local/vendor assets are present; the application does not depend on a CDN
- [ ] `php bin/test.php` passes

## 10. Database backup and restore

Backup:

```bash
mysqldump -u pak_gas_app -p --single-transaction --routines --triggers pak_gas > pak_gas_backup.sql
```

Restore:

```bash
mysql -u pak_gas_app -p pak_gas < pak_gas_backup.sql
```

Test restores periodically.

## 11. Deployment updates

Pull the latest application files and then run:

```bash
php bin/migrate.php
php bin/test.php
```

There is no `composer install` step.

Do not delete the `storage/` directory during an application update.

Never manually modify production tables when a migration is required. Add and deploy a numbered migration.

## 12. Architecture

- Controllers orchestrate HTTP requests.
- Repositories contain SQL/data access.
- Services contain business rules and transactional posting.
- `StockService` is the only service allowed to change cylinder gas/location.
- `CylinderStatus` derives FILLED/PARTIAL/EMPTY/ISSUED/SOLD.
- `AuditService` records state-changing operations.
- Money/rates use DECIMAL and gas uses DECIMAL(10,3); BCMath is required.
- `public/` is the only web-exposed directory.
- `bootstrap.php` provides the Composer-free application autoloader.

See [AGENT.md](AGENT.md) for coding rules and [REQUIREMENTS.md](REQUIREMENTS.md) for functional acceptance criteria.
