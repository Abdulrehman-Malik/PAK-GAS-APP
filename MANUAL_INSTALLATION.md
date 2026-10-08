# Windows / XAMPP Installation Guide — Pak Gas POS

This is the supported installation procedure for Windows using XAMPP.
The database is created and initialized from one file: database/schema.sql.
There are no migration scripts.

## 1. Install XAMPP

Install XAMPP with Apache, MySQL and PHP 8.1 or newer.
Recommended: use the current XAMPP release that provides PHP 8.1+.

Enable these PHP extensions in XAMPP php.ini:
- PDO MySQL (pdo_mysql)
- BCMath (bcmath)
- mbstring
- fileinfo
- zip
- xml

Restart Apache after changing php.ini.

Verify in Command Prompt:

```bat
php -v
php -m
```

## 2. Copy the project

Copy the full repository to:

```text
C:\xampp\htdocs\pak-gas-app\
```

Do not delete the app, bin, database, public or storage folders.

## 3. Configure .env

Open Command Prompt:

```bat
cd C:\xampp\htdocs\pak-gas-app
copy .env.example .env
```

Set these values in .env:

```dotenv
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
```

DB_NAME must match the database created by database/schema.sql.
For a different deployment URL or tunnel, change only APP_URL in .env.

## 4. Start XAMPP MySQL

Open XAMPP Control Panel and start MySQL.
Apache can be started now as well, or after the database setup.

## 5. Create the database and all tables

Open Command Prompt as a user that can access the XAMPP MySQL installation:

```bat
cd C:\xampp\htdocs\pak-gas-app
C:\xampp\mysql\bin\mysql.exe -u root -p < database\schema.sql
```

When the XAMPP root account has no password:

```bat
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
```

database/schema.sql creates the pak_gas database and then creates all tables, indexes, views and static seed records.

## 6. Verify database setup

Run:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p -e "USE pak_gas; SHOW TABLES;"
```

Confirm that tables include at least:

- users
- roles
- permissions
- settings
- parties
- cylinder_groups
- cylinders
- cylinder_movements
- rates
- stock_batches
- sales
- sale_lines
- purchases
- purchase_lines
- payments
- receipts
- counter_sessions
- cash_entries
- cheques
- expense_categories
- expenses
- audit_log

Views should also exist:

- v_cylinder_status
- v_shop_stock_summary
- v_party_balance

## 7. Create the administrator

From the repository root:

```bat
php bin\seed.php
```

This uses SEED_ADMIN_USERNAME and SEED_ADMIN_PASSWORD from .env.
The administrator is forced to change its password after first login.

Roles, permissions, default settings, Main Counter, document sequences and expense categories are already seeded by database/schema.sql.

## 8. Configure Apache

Apache should expose only the public folder.

Recommended DocumentRoot:

```text
C:/xampp/htdocs/pak-gas-app/public
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
</VirtualHost>
```

Add to the Windows hosts file:

```text
127.0.0.1 pak-gas.local
```

Hosts file:

```text
C:\Windows\System32\drivers\etc\hosts
```

Restart Apache.

Open:

```text
http://pak-gas.local/
```

Do not use C:\xampp\htdocs\pak-gas-app as Apache DocumentRoot.

## 9. Local development without VirtualHost

```bat
cd C:\xampp\htdocs\pak-gas-app
php -S localhost:8080 -t public
```

Set:

```dotenv
APP_URL=http://localhost:8080
```

Composer is not required.

## 10. First login and application verification

Open the application and verify:

1. Login page loads.
2. Admin login works.
3. First login requires password change.
4. Dashboard opens.
5. Parties, Cylinder Groups, Cylinders and Rates load.
6. Opening Stock loads and can create/void stock.
7. POS transaction type loads from settings.
8. POS transaction type changes affect the screen.
9. Sales History and Receipts load.
10. Purchases and Payments load.
11. Cheques, Expenses, Cash Counter and Reports load.
12. Users/Roles and Audit Log are permission-protected.

Run the local runtime checks:

```bat
php bin\test.php
```

Expected:

```text
All runtime checks passed.
```

## 11. Backup on Windows

Create a backup folder first:

```bat
mkdir C:\backup
```

Backup:

```bat
C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers pak_gas > C:\backup\pak_gas_backup.sql
```

Restore:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p pak_gas < C:\backup\pak_gas_backup.sql
```

## 12. Updating the application

Pull/copy the new PHP application files.
Do not run a migration command because this project has no migration system.
For a new database, import database/schema.sql.
For an existing production database, take a full backup before replacing or rebuilding the schema and validate the target database separately.

After application updates, run:

```bat
php bin\test.php
```

## 13. Important rules

- Keep .env outside source control.
- Deployment URLs must be changed only through .env.
- Do not expose the repository root through Apache.
- Do not add migration scripts.
- database/schema.sql is the canonical database definition and contains static seed data.
- bin/seed.php is only for the environment-based administrator credential setup.