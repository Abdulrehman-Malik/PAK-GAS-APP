# Manual Installation Guide — Pak Gas POS

This installation is for servers where Composer is unavailable or intentionally not used.

## 1. Copy the application

### Windows / XAMPP

Copy the complete repository to:

```text
C:\xampp\htdocs\pak-gas-app\
```

Do not delete `app/`, `bin/`, or `database/`. The only web-exposed folder must be `public/`.

### Linux / LAMP

Copy the repository to a directory such as:

```text
/var/www/pak-gas-app
```

Keep `app/`, `bin/`, `database/`, and `storage/` outside the web root.

## 2. PHP requirements

Use PHP 8.1 or newer and enable:

- PDO
- PDO_MySQL
- BCMath
- mbstring
- fileinfo
- zip
- xml

Confirm:

```bash
php -v
php -m
```

On XAMPP, enable missing modules in `php.ini`, restart Apache, and run `php -m` again.

**Composer is not required.** Do not run `composer install`; no `vendor/` directory is needed by the application.

## 3. Configure .env

Copy:

```text
.env.example
```

to:

```text
.env
```

Example:

```dotenv
APP_ENV=production
APP_URL=http://localhost/pak-gas-app/public
APP_NAME="Pak Gas POS"
TIMEZONE=Asia/Karachi

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pak_gas
DB_USER=root
DB_PASS=

SESSION_TIMEOUT=1800
SESSION_SECURE_COOKIE=false

SEED_ADMIN_USERNAME=admin
SEED_ADMIN_PASSWORD=CHANGE_ME_NOW
```

For production, use a dedicated database user and HTTPS with:

```dotenv
SESSION_SECURE_COOKIE=true
```

The value of `APP_URL` is the only place that should change for a different deployment URL or subdirectory.

## 4. Create the MySQL database/user

A dedicated account is recommended.

```sql
CREATE DATABASE pak_gas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pak_gas_app'@'localhost' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON pak_gas.* TO 'pak_gas_app'@'localhost';
FLUSH PRIVILEGES;
```

Update `.env` accordingly.

The application also supports automatic creation of the configured database on the login-page bootstrap path. When using that option, the configured MySQL account must have `CREATE DATABASE` privilege.

## 5. Run migrations

From the repository root:

```bash
php bin/migrate.php
```

Expected output contains `APPLY` for new migration files and then:

```text
Migration complete.
```

Migrations are recorded in the `migrations` table. They must never be edited after application; use a new numbered migration for future schema changes.

## 6. Seed the administrator

Set a strong temporary password in `.env`, then run:

```bash
php bin/seed.php
```

The seed creates/updates the administrator, roles/permissions, default settings, and default counter.

The administrator is marked to change its password on first login. Change it immediately and rotate/remove the seed password afterward.

## 7. Apache configuration

### XAMPP VirtualHost

Use the repository `public/` directory as the document root:

```apache
<VirtualHost *:80>
    ServerName pak-gas.local
    DocumentRoot "C:/xampp/htdocs/pak-gas-app/public"

    <Directory "C:/xampp/htdocs/pak-gas-app/public">
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
</VirtualHost>
```

Add:

```text
127.0.0.1 pak-gas.local
```

to the Windows hosts file.

Open:

```text
http://pak-gas.local/
```

Do not point Apache at `C:/xampp/htdocs/pak-gas-app` because that would expose application and configuration files.

### Linux / LAMP

Point Apache to:

```text
/var/www/pak-gas-app/public
```

Enable rewrite:

```bash
sudo a2enmod rewrite
sudo systemctl reload apache2
```

Keep `.env` and `storage/` outside the web root and writable only where required.

## 8. First-run verification

Check the following in order:

1. Login page opens.
2. No PHP fatal error is shown.
3. Admin login works.
4. Password-change page appears on first login.
5. Dashboard opens.
6. Parties, Cylinder Groups, Cylinders, Rates, Opening Stock, Cash Counter, and POS open.
7. POS loads its transaction-type dropdown from the `sales/pos_transaction_types` setting.
8. Switching transaction type reloads the applicable cylinder list.
9. Empty Cylinder Sale displays only empty shop cylinders and disables gas entry.
10. Gas Sale restores gas entry and supports optional filled-cylinder sale.
11. POS cash posting rejects a closed/missing cash session with a clear message.
12. Protected routes return server-side 403 for unauthorized users.
13. Run the runtime checks:

```bash
php bin/test.php
```

Expected final line:

```text
All runtime checks passed.
```

## 9. Updating an existing installation

Copy/pull the new application files without deleting `storage/`:

```bash
php bin/migrate.php
php bin/test.php
```

There is no Composer update step.

If the deployment uses a subdirectory or a tunnel URL, change only:

```dotenv
APP_URL=...
```

in `.env`. Do not modify deployment URLs in PHP or JavaScript files.

## 10. Backup

Example backup:

```bash
mysqldump -u pak_gas_app -p --single-transaction --routines --triggers pak_gas > pak_gas_backup.sql
```

Restore into an empty/recovery database:

```bash
mysql -u pak_gas_app -p pak_gas < pak_gas_backup.sql
```

Test restores before relying on a backup for disaster recovery.
