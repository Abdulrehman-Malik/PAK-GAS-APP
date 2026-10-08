# AGENT.md — Instructions for the coding agent

You are building the **LPG POS** web application. Read this file fully, then read `REQUIREMENTS.md` (the source of truth for *what* to build). This file defines *how* to build it, the rules you must not break, and the work plan.

---

## 1. Project summary

POS + inventory + ledger system for an LPG gas shop. Cylinders are issued to customers filled with gas, customers are charged for the gas only, cylinders come back later (empty or with gas credited), gas is purchased from suppliers, cash is tracked per counter, and expenses are recorded.

## 2. Tech stack (fixed — do not substitute)

| Layer | Choice |
|---|---|
| Backend | **Custom PHP 8.1+ (no framework)** — `declare(strict_types=1)`, PDO |
| Database | **MySQL 8 / MariaDB 10.6+**, InnoDB, `utf8mb4` |
| Frontend | HTML5, CSS3, **Bootstrap 5.3**, **jQuery 3.7** (AJAX) |
| UI plugins (allowed) | DataTables (server-side), Select2, SweetAlert2 or Bootstrap modals/toasts, Bootstrap Icons |
| PHP libraries | None required at runtime. Keep the application Composer-free; use native PHP and the repository autoloader. Ask before adding third-party runtime libraries. |

Rules:
- **No PHP framework** (no Laravel/CodeIgniter/Symfony) and no JS framework (no React/Vue). Plain PHP + jQuery.
- Serve all CSS/JS/fonts **locally** from `public/assets/vendor/` (the shop may have poor internet). No CDN links in templates.
- Target a standard XAMPP/LAMP environment.

## 3. Project structure

```
lpg-pos/
├── AGENT.md
├── REQUIREMENTS.md
├── .env.example              # DB creds, APP_ENV, APP_URL, TIMEZONE (never commit .env)
├── bin/
│   ├── migrate.php           # runs database/migrations/*.sql in order, records in `migrations` table
│   └── seed.php              # admin user, default roles, settings, expense categories, demo data (dev only)
├── database/
│   ├── migrations/           # 001_core.sql, 002_masters.sql, ... (never edit an applied migration; add a new one)
│   └── seeds/
├── public/                   # web root (only this folder is exposed)
│   ├── index.php             # front controller
│   ├── .htaccess             # rewrite everything to index.php
│   └── assets/
│       ├── css/app.css
│       ├── js/               # app.js (shared), pos.js, cylinders.js, ...
│       └── vendor/           # bootstrap, jquery, datatables, select2, icons
├── app/
│   ├── Core/                 # Router, Request, Response, DB (PDO wrapper), View, Auth, Csrf, Validator, Session, Config, Logger
│   ├── Controllers/          # thin: validate → call service → return view/JSON
│   ├── Services/             # ALL business logic (see section 5)
│   ├── Repositories/         # SQL only; prepared statements
│   ├── Helpers/              # money/gas formatting, date helpers, number-to-words
│   ├── Views/
│   │   ├── layouts/          # main.php (sidebar + topbar), print.php
│   │   ├── partials/         # modals, cylinder-box, flash, pagination
│   │   └── <module>/         # parties/, cylinders/, pos/, ...
│   └── routes.php            # route table
├── storage/
│   ├── logs/  uploads/  imports/  backups/    # not web accessible
└── tests/                    # lightweight runtime/unit checks
```

Autoloading: Composer-free PSR-4-compatible loader in `bootstrap.php` (`App\` → `app/`).

## 4. Coding conventions

- PSR-12. One class per file. Type-hint everything; return types required.
- DB: `snake_case` tables/columns, plural table names. PHP: `camelCase` methods, `PascalCase` classes.
- Controllers contain **no SQL and no business rules**. Repositories contain **no business rules**. Business rules live in Services.
- Routing: `GET /cylinders` (page), `GET /cylinders/data` (DataTables JSON), `POST /cylinders` (create), `POST /cylinders/{id}` (update), `POST /cylinders/{id}/delete`. All AJAX endpoints return a consistent JSON shape:
  ```json
  { "ok": true, "data": {...}, "message": "Saved" }
  { "ok": false, "message": "Cylinder C15-000004 is no longer available", "errors": {"field": "reason"} }
  ```
- Validation: server-side always (Validator class); client-side is only convenience. Return field-level errors and show them inline.
- Money/gas: use `DECIMAL` in DB and **string-based decimal math in PHP** (`bcmath` or integer minor units). Never use `float` for money, gas kg, or rates. Format only at the view layer.
- Dates stored as `DATE`/`DATETIME` in app timezone from settings; display format from settings.
- Every list screen: server-side pagination, search, sort, filters. Every create/edit: modal or side form with inline validation.
- Comments explain *why*, not *what*. Keep functions short.

## 5. Architecture & critical invariants (do not break)

Services you must create and route **all** changes through:

| Service | Responsibility |
|---|---|
| `CodeGenerator` | Cylinder/group code generation per settings pattern, race-safe via `code_sequences` row lock |
| `CylinderStatus` | Single function: `(gas_kg, capacity, location) → FILLED/PARTIAL/EMPTY/ISSUED`. Used everywhere; no duplicated logic |
| `StockService` | The ONLY code allowed to change `cylinders.gas_kg` / `location` / `customer_id`. Always writes a `cylinder_movements` row |
| `RateService` | Effective-dated rate lookup (latest `effective_date <= txn date`) |
| `LedgerService` | The ONLY code allowed to insert `ledger_entries`; append-only; reversals via `reversal_of` |
| `CashService` | The ONLY code allowed to insert `cash_entries`; computes expected counter balance |
| `PosService` | Posts/voids sales (issue + return + payment) in one transaction |
| `PurchaseService`, `ReceiptService`, `PaymentService`, `ExpenseService`, `ChequeService` | Posting/void for each document |
| `ImportService` | Parse → validate (preview, no writes) → commit (single transaction) |
| `DocNumberService` | Sequential document numbers per type, race-safe |
| `AuditService` | Writes `audit_log` |

Invariants (each has a test):
1. A cylinder with `location = CUSTOMER` always has `customer_id` (DB CHECK + service check). `location = SOLD` cylinders have `customer_id` NULL and never move again except through a void.
2. `0 <= gas_kg <= capacity`. Status is **derived**, never stored as an editable field.
3. Posting a sale: open DB transaction → `SELECT … FOR UPDATE` on selected cylinders → re-validate location/gas → write sale, lines, movements, ledger, receipt, cash entry → commit. Any failure → rollback, nothing saved.
4. Posted documents are immutable. Corrections = **void** (reversal rows + stock restore), blocked when a later movement conflicts (see REQUIREMENTS BR-14). Never `UPDATE`/`DELETE` ledger, cash, or movement rows.
5. Master delete allowed only when no child records exist (use a shared `HasChildren` check); otherwise refuse and offer Deactivate.
6. Credit limit, cheque posting mode, tax, code generation mode, rate-edit permission all come from `settings` — no hard-coded behavior.
7. Authorization checked on the server for every route and AJAX endpoint (role permission), plus CSRF on every POST.

## 6. Security checklist (apply from day one)

- PDO prepared statements only; never concatenate user input into SQL.
- Escape all output with a helper `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
- CSRF token in every form and sent as header with every AJAX POST (set via `$.ajaxSetup`).
- `password_hash(PASSWORD_DEFAULT)`; regenerate session ID on login; `HttpOnly`, `SameSite=Lax`, `Secure` (when HTTPS) cookies; session timeout.
- Login throttling (e.g. 5 failures → 15 min lock).
- File uploads (Excel, expense attachments): whitelist extension + MIME, random filenames, stored outside web root, size limit.
- Only `public/` is web-exposed; `.env`, `storage/`, `app/` are not reachable. Errors are logged, not shown, when `APP_ENV=production`.

## 7. UI / UX guidelines

- Layout: left sidebar (collapsible; off-canvas on mobile) + top bar (user, counter, logout). Bootstrap 5.3 grid; **mobile-first**, must be usable on a phone/tablet.
- Consistent CRUD pattern: filter bar → DataTable → Add button → modal form. Active/Inactive badge. Confirm destructive actions.
- **POS screen** is the most important screen:
  - Large inputs/buttons (touch-friendly), minimal typing, customer search-as-you-type (Select2).
  - **Cylinder box** component (`partials/cylinder-box.php` + CSS): bordered card showing code (large), group, `gas / capacity kg`, status badge (Filled = green, Partial = amber, Empty = grey, Issued = blue, Sold = dark, Damaged = red), selected state with check mark. Grid reflows: 2 columns on phones, 4–6 on desktop.
  - In the Filled popup every box carries a **"Sell cylinder" checkbox (default unticked)**; ticked boxes show a clear "SELL" marker. Saving a transaction with any cylinder sale (including the Empty type) must show the confirmation dialog (REQUIREMENTS BR-19) before posting.
  - Live totals (qty, gas kg, amount) update on every change. Show customer info bar (credit limit, balance/advance, cylinders held).
  - Keyboard shortcuts on desktop (e.g. F2 new, F8 save, F9 save & print, Esc close popup).
  - Clear, human error messages ("Cylinder C15-000004 is no longer available").
- Number inputs: `inputmode="decimal"`, step per settings; gas up to 3 decimals, money 2.
- Print: dedicated `print.php` layout and print CSS for 80 mm thermal and A4.
- Use toasts for success, inline messages for validation, modal for blocking errors.

## 8. Database rules

- Migrations are plain SQL files, applied in order by `bin/migrate.php`; never edit an applied one.
- FKs on every relationship with `ON DELETE RESTRICT`; indexes on FKs and on common filters (`status`, `entry_date`, `code`, `location`).
- Use `CHECK` constraints (MySQL 8) where possible; if running MariaDB/older MySQL, enforce in services as well.
- Create SQL **views** for repeated reads (e.g. `v_cylinder_status`, `v_party_balance`, `v_shop_stock_summary`) so reports and screens agree.
- Seed: default admin (force password change on first login), roles/permissions, settings defaults, expense categories, one default counter.
- Table definitions: follow section 7 of `REQUIREMENTS.md`; add columns if needed and record them in the Decisions log below.

## 9. Testing & quality

- Runtime/unit checks cover core invariants. Keep business-flow regression checks runnable without Composer.
- Feature/DB tests for the acceptance scenarios **AT-1 … AT-20** in `REQUIREMENTS.md`. Name tests after the scenario ID.
- `php -l` on all files; no PHP notices/warnings in logs during manual testing.
- Manual QA script per phase (short checklist in the Progress Log).

## 10. Working agreement

1. **Work phase by phase** (section 11). Finish and verify one phase before starting the next. Keep changes small and commit often with clear messages (`feat(pos): issue cylinders popup`).
2. **Do not expand scope.** If something is missing or ambiguous, choose the simplest sensible default consistent with `REQUIREMENTS.md`, record it in the **Decisions log**, and continue. For anything under "Assumptions & open questions" (REQUIREMENTS §11) implement the stated default and keep it isolated/switchable.
3. Never bypass the services in section 5 (e.g. no direct `UPDATE cylinders SET gas_kg…` from a controller).
4. After each task: tick it in the **Progress** list, add a dated line to the **Progress log**, and note anything the next session needs to know. This file is how work resumes across sessions — keep it accurate.
4a. **Mandatory QA runtime rule:** whenever a fix or feature changes a screen, endpoint, or shared component, run the QA/runtime workflow for every impacted screen automatically. Do not wait for the user to request it. Fix every issue found and repeat the checks until the impacted flow is clean.
5. Definition of Done for a task: works end-to-end in the browser (desktop + mobile width), server-side validation and permission checks in place, audit log written, relevant tests pass, no PHP warnings, requirements/acceptance rows covered.
6. Don't commit secrets, `vendor/`, `.env`, uploaded files, or backups.

## 11. Phased work plan

### Phase 0 — Foundation
- [x] Repo skeleton, Composer-free autoload, `.env` loader, front controller, router
- [x] `DB` (PDO) wrapper with transactions; `bin/migrate.php`; `001_core.sql` (users, roles, permissions, settings, audit_log, sequences)
- [x] Auth (login/logout, session hardening, throttling, CSRF), role/permission middleware
- [x] Main layout (sidebar, topbar, flash, modal/toast helpers), shared JS (`$.ajaxSetup` CSRF, DataTables defaults, number helpers)
- [x] Settings screen shell with tabs; seed script

### Phase 1 — Masters
- [ ] Settings: General, Code Generation, Sales & Credit, Tax, Document Numbers, Printing
- [ ] Party Profile (CRUD, conditional credit limit, opening balance, delete protection, party import)
- [ ] Cylinder Group (CRUD, code mode, delete protection)
- [ ] `CodeGenerator` (configurable pattern still required) + `CylinderStatus` (+ unit tests)
- [ ] Cylinders list/add/edit/history, summary cards, delete protection
- [ ] Rate Configuration (gas rate per kg + per-group **cylinder price**, history, `RateService`)

### Phase 2 — Opening stock
- [ ] `StockService` + `cylinder_movements`
- [~] Opening Stock entry (AUTO/MANUAL codes, location SHOP/ISSUED + customer, live status, shop gas total) + batch list/void — entry/history implemented; batch void and Excel import remain
- [ ] Excel template download, upload, validation preview, commit, error report (`ImportService`)
- [ ] Tests AT-1 … AT-5

### Phase 3 — POS & receipts
- [ ] `LedgerService`, `DocNumberService`, `CashService` (needed by POS)
- [ ] POS screen: customer bar, group dropdown with live availability, cylinder-box popup, issue grid, rate edit, totals
- [ ] POS cylinder sales: **Empty** type (price from cylinder rate) and per-cylinder **Sell cylinder** checkbox on filled cylinders (location `SOLD`, gas leaves stock), save-confirmation dialog (BR-18, BR-19)
- [ ] POS return-issued-cylinders flow (gas default 0, rate default = issue rate), exchange in one transaction
- [ ] Payment inside POS (cash/online/cheque) → linked receipt; credit-limit enforcement; print layout
- [ ] Sales History (filters, detail, export, void with conflict rules)
- [ ] Receipts screen (new + history incl. POS-origin, advance handling, void)
- [ ] Tests AT-6 … AT-13, AT-16, AT-19, AT-21, AT-22, AT-23, AT-25

### Phase 4 — Purchase & payments
- [ ] Purchase screen with **both** line types: gas fill of existing cylinders, and **new cylinders** (group, qty, initial gas, cylinder cost, gas rate, AUTO/MANUAL codes via `CodeGenerator`); paid-now → linked payment; supplier ledger; void rules
- [ ] Payments screen (new + history, advance, void)
- [ ] Tests AT-17, AT-24, AT-26

### Phase 5 — Cash, cheques, expenses
- [ ] Counters (Settings), sessions open/close, manual cash in/out, counter report, expected balance
- [ ] Cheque register (pending/cleared/bounced, post-dated, posting mode)
- [ ] Expense categories + Expenses (cash → counter)
- [ ] Tests AT-14, AT-18

### Phase 6 — Reports & hardening
- [ ] Reports (REQUIREMENTS §8 MVP list) with filters, print, Excel/CSV export
- [ ] Users & roles screen, permission matrix, audit log viewer
- [ ] Security review (section 6), performance pass (indexes, 2,000+ cylinders), mobile QA, backup/restore doc, `README.md` install guide
- [ ] Tests AT-15, AT-20; full AT regression

### Phase 7 — Optional / pending confirmation
- [ ] POS hold/recall draft, dashboard widgets (only if requested)

## 12. Commands (adjust once the skeleton exists)

```
composer install
cp .env.example .env            # set DB credentials
php bin/migrate.php             # apply migrations
php bin/seed.php                # seed admin + defaults (dev demo data with --demo)
php -S localhost:8080 -t public # quick dev server (or use XAMPP vhost pointing at public/)
vendor/bin/phpunit              # run tests
```

## 13. Decisions log (append as you go)

| Date | Decision | Reason |
|---|---|---|
| 2026-10-08 | Empty cylinder in POS = outright sale at the group's cylinder price; cylinder becomes `SOLD` and leaves stock | Product owner confirmed |
| 2026-10-08 | Filled cylinders can be sold with gas via per-cylinder "Sell cylinder" checkbox (default unticked); save prompts with count and gas leaving stock | Product owner confirmed |
| 2026-10-08 | Referee is only a party type; no commission | Product owner confirmed |
| 2026-10-08 | Purchase supports both gas refill of existing cylinders and adding new cylinders | Product owner confirmed |
| 2026-10-08 | Filled-cylinder sale price = gas × gas rate + cylinder price (editable) | Assumption, pending confirmation |

## 14. Progress log (append as you go; newest at the bottom)

| Date | Phase/Task | Status | Notes for next session |
|---|---|---|---|
| 2026-10-08 | Phase 0 — Foundation | Completed | Added custom PHP foundation, PDO migration/seed tooling, auth/security shell, responsive Bootstrap/jQuery UI, QA checklist and CI. |
| 2026-10-08 | Phase 1 review / Phase 2 start | In progress | Masters screens are present, but configurable code-pattern, master delete protection, party import, and complete Phase 1 QA remain. Opening Stock transaction/history foundation and deployment README added. |

## 15. Open questions for the product owner

See `REQUIREMENTS.md` §11. Do not block on them: implement the stated default and keep it switchable.


### 2026-10-08 Business-flow implementation rule
- Transactional work must be verified end-to-end after each change. POS changes must exercise transaction-type selection, cylinder selection, pricing, stock movement, ledger posting, payment/cash integration, and failure rollback before being considered done.
