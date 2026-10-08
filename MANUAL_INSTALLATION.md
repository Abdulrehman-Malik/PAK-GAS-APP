# Windows / XAMPP Installation Guide — Pak Gas POS

The supported installation flow is now the browser-based Installation page.

You only need to configure .env, start Apache/MySQL, and open the application.

## 1. Install XAMPP

Install XAMPP with:
- Apache
- MySQL
- PHP 8.1+

The application requires these PHP extensions:

| Requirement | Purpose |
|---|---|
| PDO | Database access |
| PDO MySQL | MySQL/MariaDB database driver |
| BCMath | Decimal-safe application calculations |
| mbstring | Multibyte string handling |
| fileinfo | Upload/file validation |
| zip | XLSX import/export |
| xml | XLSX XML parsing |

The Installation page checks these automatically. If one is disabled, it shows the missing item and tells you to enable it.

After changing php.ini, restart Apache.

## 2. Copy the project

Copy the project to:

~~~text
C:\xampp\htdocs\pak-gas-app\
~~~

Do not make the repository root an Apache web root.

## 3. Create .env

~~~bat
cd C:\xampp\htdocs\pak-gas-app
copy .env.example .env
~~~

Set the database information and administrator credentials in .env:

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

The installer reads the database information directly from this file. It does not need DB values entered into a separate installation form.

Change SEED_ADMIN_PASSWORD to a secure password before installation.

## 4. Start XAMPP

Start Apache and MySQL from the XAMPP Control Panel.

## 5. Run the installer

Open:

~~~text
http://localhost/pak-gas-app/public/
~~~

The application automatically redirects to /install when the installation is incomplete.

The Installation page performs the whole setup:

1. Validates PHP and mandatory extensions.
2. Checks the database connection from .env.
3. Creates DB_NAME automatically when it does not exist.
4. Imports database/schema.sql.
5. Creates the migration tracking table.
6. Runs any queued migration files.
7. Seeds the Administrator from .env.
8. Redirects to the Login page.

There is no need to use phpMyAdmin, the MySQL client, or php bin\seed.php.

## 6. Apache configuration

Recommended DocumentRoot:

~~~text
C:/xampp/htdocs/pak-gas-app/public
~~~

Example:

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

Windows hosts entry:

~~~text
127.0.0.1 pak-gas.local
~~~

Open:

~~~text
http://pak-gas.local/
~~~

## 7. Application updates and migrations

Future DB changes are added as numbered files:

~~~text
database/migrations/001_add_example_column.sql
database/migrations/002_create_example_index.sql
~~~

Do not modify a migration after it has been applied.

When an unapplied migration file is deployed:

1. The application detects it automatically.
2. Normal requests are redirected to /install.
3. /install runs the pending migrations in filename order.
4. A checksum is stored in schema_migrations.
5. The application redirects to Login after successful completion.

If a migration fails, the application remains on the Installation page and shows the migration error instead of opening the application with an unknown database state.

## 8. Local PHP server option

~~~bat
cd C:\xampp\htdocs\pak-gas-app
php -S localhost:8080 -t public
~~~

Set:

~~~dotenv
APP_URL=http://localhost:8080
~~~

Then open http://localhost:8080/.

## 9. Backup / restore

Backup:

~~~bat
mkdir C:\backup
C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers pak_gas > C:\backup\pak_gas_backup.sql
~~~

Restore:

~~~bat
C:\xampp\mysql\bin\mysql.exe -u root -p pak_gas < C:\backup\pak_gas_backup.sql
~~~

## 10. Maintenance command

For troubleshooting/maintenance only:

~~~bat
php bin\seed.php
~~~

It applies pending migrations and refreshes the Administrator from .env.

## 11. Security

- Keep .env out of Git.
- Expose only public/ through Apache.
- Change the default administrator password immediately after first login.
- Change deployment URLs only in .env.
- Never edit an already-applied migration; add a new numbered migration.
