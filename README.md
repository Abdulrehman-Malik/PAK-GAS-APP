# Pak Gas POS

Custom PHP 8.1+ LPG POS, inventory and ledger application.

## Requirements

- PHP 8.1+
- MySQL 8 or MariaDB 10.6+
- Composer
- Apache/XAMPP with public/ as the web root

## Local setup

1. Copy .env.example to .env and set the database credentials.
2. Run composer install.
3. Run php bin/migrate.php.
4. Run php bin/seed.php.
5. Point Apache/XAMPP to the repository public/ directory.

The seeded administrator password is controlled by SEED_ADMIN_PASSWORD in .env.

## Architecture

The application uses custom PHP with PDO. Controllers orchestrate requests, repositories contain SQL, and services contain business rules. State-changing routes use CSRF protection and server-side authorization.

Read AGENT.md for implementation rules and REQUIREMENTS.md for product behavior.
