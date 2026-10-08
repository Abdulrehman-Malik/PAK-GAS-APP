# Pak Gas POS

Pak Gas POS is a Composer-free PHP/MySQL LPG cylinder, gas, sales, purchase, ledger, cash and reporting application.

## Windows / XAMPP installation

Installation is now web-based. You do not need to import SQL files or run seed commands manually.

### 1. Install XAMPP

Install XAMPP with Apache, MySQL and PHP 8.1 or newer.

The installer checks these mandatory PHP requirements automatically:

- PDO
- PDO MySQL
- BCMath
- mbstring
- fileinfo
- zip
- xml

If any requirement is missing, the Installation page shows exactly what must be enabled. After changing XAMPP's php.ini, restart Apache and refresh the Installation page.

### 2. Copy the application

Copy the repository to:

~~~text
C:\xampp\htdocs\pak-gas-app\
~~~

Only the public directory should be exposed by Apache.

### 3. Create .env

From Command Prompt:

~~~bat
cd C:\xampp\htdocs\pak-gas-app
copy .env.example .env
~~~

Set your database and administrator values in .env:

~~~dotenv
APP_ENV=development
APP_URL=http://localhost/pak-gas-app/public
APP_NAME=Pak Gas POS
TIMEZONE=Asia/Karachi

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pak_gas
DB_USER=root
DB_PASS=

SESSION_TIMEOUT=1800
SESSION_SECURE_COOKIE=false

SEED_ADMIN_USERNAME=admin
SEED_ADMIN_PASSWORD=ChangeMeImmediately!
~~~

Change SEED_ADMIN_PASSWORD before installation.

Deployment URLs must be changed only through APP_URL in .env. Do not hard-code URLs in PHP or JavaScript.

### 4. Start Apache and MySQL

Open XAMPP Control Panel and start Apache and MySQL.

### 5. Open the application

Open:

~~~text
http://localhost/pak-gas-app/public/
~~~

The application automatically sends the first request to:

~~~text
/install
~~~

The Installation page will:

1. Check PHP version and mandatory extensions.
2. Read DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS from .env.
3. Create the configured database if it does not exist.
4. Import the complete database/schema.sql baseline.
5. Create/verify the migration tracking table.
6. Run any new numbered SQL migrations found in database/migrations/.
7. Create/refresh the Administrator using SEED_ADMIN_USERNAME and SEED_ADMIN_PASSWORD from .env.
8. Redirect to the Login page after success.

No MySQL command line, phpMyAdmin import, or bin\seed.php command is required for normal installation.

### 6. Configure Apache correctly

Point Apache to:

~~~text
C:/xampp/htdocs/pak-gas-app/public
~~~

Example VirtualHost:

~~~apache
<VirtualHost *:80>
    ServerName pak-gas.local
    DocumentRoot "C:/xampp/htdocs/pak-gas-app/public"

    <Directory "C:/xampp/htdocs/pak-gas-app/public">
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
</VirtualHost>
~~~

Add to:

~~~text
C:\Windows\System32\drivers\etc\hosts
~~~

~~~text
127.0.0.1 pak-gas.local
~~~

Then open:

~~~text
http://pak-gas.local/
~~~

Do not use C:\xampp\htdocs\pak-gas-app as the Apache DocumentRoot.

### 7. Updating the application

Copy/pull the new application version.

When a new migration exists in database/migrations/:

~~~text
001_add_example_column.sql
002_create_example_index.sql
~~~

the next normal application request is redirected automatically to /install.

The installation/update page runs all unapplied migrations in filename order. After successful updates, it redirects to Login.

Migration files are tracked in the schema_migrations table with a SHA-256 checksum. An already-applied migration must never be edited; create a new migration instead.

### 8. Quick local development option

~~~bat
cd C:\xampp\htdocs\pak-gas-app
php -S localhost:8080 -t public
~~~

Set:

~~~dotenv
APP_URL=http://localhost:8080
~~~

Then open:

~~~text
http://localhost:8080/
~~~

### 9. Backup and restore on Windows

Backup:

~~~bat
mkdir C:\backup
C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers pak_gas > C:\backup\pak_gas_backup.sql
~~~

Restore:

~~~bat
C:\xampp\mysql\bin\mysql.exe -u root -p pak_gas < C:\backup\pak_gas_backup.sql
~~~

Always test restore procedures on a recovery database.

## Database structure

database/schema.sql is the canonical clean-install baseline containing database creation compatibility, all table definitions, indexes, views and static seed data.

Future incremental changes belong only in numbered files under:

~~~text
database/migrations/
~~~

The runtime migration queue is automatic; there is no manual migration command.

## CLI compatibility

The following command remains available for maintenance/troubleshooting, but is not part of the normal installation process:

~~~bat
php bin\seed.php
~~~

It runs pending migrations and creates/refreshes the administrator from .env.

## Verification

The repository CI validates PHP syntax, runtime checks, schema import and the automatic migration runner.

For local runtime checks:

~~~bat
php bin\test.php
~~~

Expected:

~~~text
All runtime checks passed.
~~~
