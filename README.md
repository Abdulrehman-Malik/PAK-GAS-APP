# Pak Gas POS

Pak Gas POS is a Composer-free PHP/MySQL LPG cylinder, gas, sales, purchase, ledger, cash and reporting application.

## Windows / XAMPP installation

The project uses one canonical database file: database/schema.sql
The schema file creates the pak_gas database, all tables, indexes, views and static seed data.
There is no migration directory and no migration command.

### 1. Install XAMPP

Install XAMPP with Apache, MySQL and PHP 8.1 or newer.
Enable these PHP extensions in XAMPP php.ini when needed:
- pdo_mysql
- bcmath
- mbstring
- fileinfo
- zip
- xml

Restart Apache after changing php.ini.

Verify from Command Prompt:

```bat
php -v
php -m
```

### 2. Copy the application

Copy the repository to:

```text
C:\xampp\htdocs\pak-gas-app\
```

Do not expose the repository root through Apache. Only the public directory should be web-accessible.

### 3. Create the environment file

From Command Prompt:

```bat
cd C:\xampp\htdocs\pak-gas-app
copy .env.example .env
```

Use this Windows/XAMPP baseline:

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

Change SEED_ADMIN_PASSWORD before creating the administrator.
For a different subdirectory or tunnel URL, change only APP_URL in .env. Never hard-code the deployment URL in PHP or JavaScript.

### 4. Import the complete database

Start MySQL from XAMPP Control Panel.

Open Command Prompt:

```bat
cd C:\xampp\htdocs\pak-gas-app
C:\xampp\mysql\bin\mysql.exe -u root -p < database\schema.sql
```

If the XAMPP root account has no password:

```bat
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
```

The schema itself creates the pak_gas database.

Verify the database:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p -e "USE pak_gas; SHOW TABLES;"
```

### 5. Create the administrator

From the repository root:

```bat
php bin\seed.php
```

Static seed data such as roles, permissions, settings, counters, document sequences and expense categories is already in database/schema.sql.
The seed command only creates or refreshes the environment-based administrator account and forces a password change at first login.

### 6. Configure Apache

Point Apache to:

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

Add to Windows hosts file:

```text
127.0.0.1 pak-gas.local
```

Hosts file location:

```text
C:\Windows\System32\drivers\etc\hosts
```

Restart Apache and open http://pak-gas.local/

Do not set Apache DocumentRoot to C:/xampp/htdocs/pak-gas-app because that would expose app, database and environment files.

### 7. Local development option

```bat
cd C:\xampp\htdocs\pak-gas-app
php -S localhost:8080 -t public
```

Then set APP_URL=http://localhost:8080 in .env.

Composer is not required.

### 8. First-run verification

1. Login page opens.
2. Admin login works.
3. First login forces password change.
4. Dashboard opens.
5. Parties, Cylinder Groups, Cylinders and Rates open.
6. Opening Stock can create cylinders and show history.
7. POS transaction type loads from settings and changes line behavior.
8. Sales History, Receipts, Purchases, Payments, Cheques, Expenses and Reports open.
9. Unauthorized screens return server-side HTTP 403.
10. Runtime checks pass:

```bat
php bin\test.php
```

Expected:

```text
All runtime checks passed.
```

## Database structure

All database creation, table creation, indexes, views and static seed data are maintained in:

```text
database\schema.sql
```

Do not add migration scripts to this project.

## Windows backup

Backup:

```bat
C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers pak_gas > C:\backup\pak_gas_backup.sql
```

Restore:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p pak_gas < C:\backup\pak_gas_backup.sql
```

Always test a restore on a recovery database.

## Important note

database/schema.sql is the baseline database definition for new installations. It is intentionally not a migration engine. Before rebuilding an existing production database, take a full backup and validate the target schema and data separately.