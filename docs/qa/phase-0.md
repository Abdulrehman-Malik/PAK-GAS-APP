# Phase 0 QA Checklist

## Environment
- PHP 8.1+ installed.
- MySQL 8 / MariaDB 10.6+ available.
- .env created from .env.example.
- Composer dependencies installed.

## Database
- [ ] php bin/migrate.php completes with no SQL error.
- [ ] php bin/seed.php completes.
- [ ] Core security/auth tables and sequences exist.

## Browser
- [ ] /login renders without PHP warnings/errors.
- [ ] Invalid login is rejected without account disclosure.
- [ ] Five failed attempts from the same username/IP trigger throttling.
- [ ] Valid login redirects to /password/change when force_password_change is enabled; successful password change clears the flag.
- [ ] Dashboard renders at desktop width.
- [ ] Sidebar uses Bootstrap offcanvas behavior at mobile width.
- [ ] /settings is visible to Administrator.
- [ ] Logout is POST + CSRF protected.
- [ ] Direct access to /settings while logged out redirects to login.

## Code quality
- [ ] php -l passes for every PHP source file.
- [ ] PHPUnit unit tests pass.
- [ ] No CDN links exist in application templates.
- [ ] .env and runtime storage are not committed.
