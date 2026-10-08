# Pak Gas POS

Custom PHP 8.1+ LPG POS, inventory and ledger application for a standard XAMPP/LAMP deployment.

## 1. Server requirements

- PHP 8.1 or newer
- PHP extensions: PDO, PDO_MySQL, BCMath, mbstring, fileinfo, zip, xml
- MySQL 8.x or MariaDB 10.6+
- Composer 2.x
- Apache with `mod_rewrite` enabled
- HTTPS recommended for production
- The Apache document root **must be the repository `public/` directory**. Do not expose the repository root.

Check PHP modules:

```bash
php -v
php -m
composer --version
```

## 2. Create the database

Create an empty database and a dedicated application user.

Example:

```sql
CREATE DATABASE pak_gas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pak_gas_app'@'localhost' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON pak_gas.* TO 'pak_gas_app'@'localhost';
FLUSH PRIVILEGES;
```

For a local XAMPP installation, using the existing MySQL root account is possible, but a dedicated user is recommended for production.

## 3. Configure the application

Copy the example environment file:

### Windows / XAMPP

```text
Copy .env.example to .env
```

### Linux

```bash
cp .env.example .env
```

Set at minimum:

```dotenv
APP_ENV=production
APP_URL=https://your-domain.example
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

If the application is installed in a subdirectory, `APP_URL` must include that path, for example:

```dotenv
APP_URL=http://localhost/pak-gas-app
```

Do not hard-code deployment URLs in PHP or JavaScript.

## 4. Install PHP dependencies

From the repository root:

```bash
composer install --no-dev --optimize-autoloader
```

For development/testing:

```bash
composer install
```

## 5. Run database migrations

After the database and `.env` are configured:

```bash
php bin/migrate.php
```

Migrations are applied in filename order and recorded in the `migrations` table.

**Never edit an already-applied migration.** Add a new numbered migration for schema changes.

## 6. Seed the administrator and defaults

Set a strong temporary administrator password in `.env`, then run:

```bash
php bin/seed.php
```

The seeded administrator is forced to change its password on first login.

After the first login, immediately change the password. Remove or replace the seed password from the deployment environment.

Do not run seed scripts containing demo data against production unless the data has been explicitly reviewed.

## 7. Apache / XAMPP deployment

### XAMPP on Windows

Recommended layout:

```text
C:\xampp\htdocs\pak-gas-app\
    app\
    bin\
    database\
    public\
    storage\
    .env
```

Configure an Apache VirtualHost so the document root is:

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

Add the local hostname to the Windows hosts file:

```text
127.0.0.1 pak-gas.local
```

Then use:

```text
http://pak-gas.local/
```

### Linux / LAMP

Set the Apache VirtualHost document root to:

```text
/var/www/pak-gas-app/public
```

Enable rewrite:

```bash
sudo a2enmod rewrite
sudo systemctl reload apache2
```

Ensure Apache can write only to required storage directories:

```bash
sudo chown -R www-data:www-data storage
sudo chmod -R u+rwX storage
```

Do not make the whole project writable by the web server.

## 8. Verify the deployment

Open the application URL and verify:

1. Login page loads.
2. Administrator can log in.
3. First login requires password change.
4. Dashboard loads.
5. Settings loads.
6. Parties loads.
7. Cylinder Groups loads.
8. Cylinders loads.
9. Rates loads.
10. Opening Stock loads.
11. Create a test cylinder group.
12. Create one test party.
13. Create an opening-stock batch in a non-production database.
14. Confirm the cylinder appears in Cylinders and stock status/gas are correct.
15. Confirm the batch appears in Opening Stock history.
16. Confirm the cylinder movement exists.
17. Confirm unauthorized users receive HTTP 403 for protected actions.

## 9. CLI / development server

For development only:

```bash
php -S localhost:8080 -t public
```

Then set:

```dotenv
APP_URL=http://localhost:8080
```

The PHP built-in server is **not recommended for production**.

## 10. Production checklist

Before go-live:

- [ ] `APP_ENV=production`
- [ ] Strong database password
- [ ] Strong administrator password
- [ ] HTTPS enabled
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] Apache document root is `public/`
- [ ] `.env` is not web-accessible
- [ ] `storage/` is not web-accessible
- [ ] Composer production dependencies installed
- [ ] Database migrations completed
- [ ] Administrator password changed
- [ ] Backups configured
- [ ] Restore procedure tested
- [ ] Error logs monitored
- [ ] No demo/test transactions remain
- [ ] Local/vendor assets are present; production does not depend on a CDN

## 11. Database backup and restore

Backup:

```bash
mysqldump -u pak_gas_app -p --single-transaction --routines --triggers pak_gas > pak_gas_backup.sql
```

Restore into an empty/recovery database:

```bash
mysql -u pak_gas_app -p pak_gas < pak_gas_backup.sql
```

Always test restores periodically; a backup that cannot be restored is not a reliable backup.

## 12. Deployment update procedure

For an existing installation:

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/migrate.php
```

Then restart/reload PHP/Apache if required by the hosting environment.

Do not delete the `storage/` directory during an application update.

Never manually modify production tables when a migration is required. Add and deploy a numbered migration.

## 13. Architecture

- Controllers orchestrate HTTP requests.
- Repositories contain SQL/data access.
- Services contain business rules and transactional posting.
- `StockService` is the only service allowed to change cylinder gas/location.
- `CylinderStatus` derives FILLED/PARTIAL/EMPTY/ISSUED/SOLD.
- `AuditService` records state-changing operations.
- Money/rates use DECIMAL and gas uses DECIMAL(10,3); BCMath is required.
- `public/` is the only web-exposed directory.

See `AGENT.md` for coding rules and `REQUIREMENTS.md` for functional acceptance criteria.
