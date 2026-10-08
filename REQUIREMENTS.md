# LPG POS — Requirements Specification (v1.1)

> Source of truth for what to build. `AGENT.md` explains *how* to build it.
> Items marked **[ASSUMPTION]** are decisions I made where the original brief was ambiguous — confirm or change them (see section 11).
> Items marked **[ADDED]** are not in the original brief but are required to make the system work.

---

## 1. Overview

A web-based Point-of-Sale and inventory system for an LPG (gas) retail shop. The shop owns gas cylinders in different sizes. Cylinders are **issued to customers filled with gas**; the customer is **charged for the gas only**; later the customer **returns the cylinder** (usually empty, sometimes with gas left, which is credited back). The shop can also **sell cylinders outright** (empty, or filled together with their gas), in which case they leave its stock permanently. It **purchases gas from suppliers** (refilling its cylinders) and **new cylinders**, receives money from customers, pays suppliers, tracks cash in the drawer, and records utility expenses.

**Primary goals**
1. Always know exactly which cylinders are in the shop, which are with which customer, and how much gas each holds.
2. Always know every customer's and supplier's balance (ledger).
3. Fast, simple, mobile-friendly POS screen usable by non-technical staff.

**Tech stack (fixed):** custom PHP (no framework), HTML, CSS, Bootstrap 5.3, jQuery (AJAX), MySQL.

---

## 2. Glossary

| Term | Meaning |
|---|---|
| Cylinder | One physical, uniquely coded gas cylinder owned by the shop. |
| Cylinder Group | A cylinder type/size, e.g. code `C`, capacity 15 kg. |
| Capacity | Max gas (kg) a cylinder of a group can hold. |
| Actual gas | Gas (kg) currently inside one specific cylinder. Decimals allowed (1, 1.23, 3.5). |
| Location | Where the cylinder is: `SHOP`, `CUSTOMER` (issued), or `SOLD` (sold outright, no longer our stock). |
| Fill state | Derived: `FILLED`, `PARTIAL`, `EMPTY` (see BR-1). |
| Issued | Cylinder is at a customer (location = CUSTOMER). Always linked to that customer. Remains our property and can come back. |
| Sold | Cylinder (empty, or with its gas) sold to a customer. Leaves shop stock permanently; never returns. |
| Party | A customer, supplier, or referee. |
| Counter | A cash register / drawer. There can be several. |
| OS balance | Outstanding balance of a party. |
| Posted document | A saved sale/receipt/purchase/payment. Immutable; corrected only by void (BR-14). |

---

## 3. Scope

**In scope (v1):** all screens in section 6, users & roles, reports in section 8, Excel import for opening stock, printing of POS receipts.

**Out of scope (v1):** multiple branches/warehouses, bulk tank/bowser inventory, payroll, payment gateway integration, SMS/WhatsApp, native mobile app, multi-language (UI is English only), inter-company accounting / full general ledger.

**Tax:** optional and configurable (on/off, percentage). Default **off**.

---

## 4. Users & roles [ADDED]

- Login with username + password. Roles: **Admin** (everything), **Cashier** (POS, receipts, own counter, view stock), **Manager** (all operational screens, void, reports, no user/role management). Permissions are per screen + action (view/create/edit/void/delete) so roles can be adjusted in Settings.
- Every create/edit/void/delete is written to an **audit log** (who, when, what, old/new values).

---

## 5. Core business rules

**BR-1 Fill state and status.**
- `gas_kg` is stored per cylinder. Fill state is derived, never typed by the user:
  - `FILLED` when gas_kg = capacity
  - `PARTIAL` when 0 < gas_kg < capacity
  - `EMPTY` when gas_kg = 0
- `gas_kg` can never exceed capacity or be negative.
- The **displayed status** is `ISSUED` when location = CUSTOMER, `SOLD` when location = SOLD, otherwise the fill state. For issued cylinders the detail view also shows the gas it holds.
- Store `gas_kg` and `location`; compute fill state in one shared function (and a SQL view/expression for reports). Do not keep a separate editable status column that can drift.

**BR-2 Issued ⇒ customer.** Every cylinder with location CUSTOMER must have `customer_id`. Enforced in the service layer and with a DB constraint.

**BR-3 Stock figures.**
- *Total gas available in shop* = Σ `gas_kg` of cylinders where location = SHOP, condition = GOOD, active.
- *Gas with customers* = Σ `gas_kg` of cylinders where location = CUSTOMER (reported separately, never counted as shop stock).
- Damaged cylinders are shown in inventory reports but are **not selectable in POS** and not counted as sellable stock. [ASSUMPTION]
- Sold cylinders (location = SOLD) are not company stock: they are excluded from shop stock, issued stock and gas totals, and appear only in the "Cylinders sold" report and in history.

**BR-4 Rate resolution.** Gas rate per kg and empty-cylinder price are effective-dated. For a transaction date D the rate used is the latest rate with `effective_date <= D`. It is pre-filled in the POS and **editable by the user** per transaction; the edited value is what gets stored on the line. If no rate exists, POS blocks the sale with a clear message.

**BR-5 Gas sale amount.** Line amount = `gas_kg × rate_per_kg` (rate stored on the line). The customer is charged for the gas inside the cylinders issued, not for the cylinder.

**BR-6 Return of issued cylinders.**
- Only cylinders currently issued to that customer can be returned in that customer's POS transaction.
- Returned gas defaults to **0** and can be edited (0 ≤ gas ≤ capacity).
- Credit to customer = `returned_gas_kg × rate`; rate defaults to the rate at which that cylinder was issued and is editable.
- After return: location = SHOP, gas_kg = returned gas, fill state recomputed.

**BR-7 Ledger sign convention.** One ledger per party. Balance = Σ debit − Σ credit.
- Customer: positive = customer owes the shop (receivable); negative = advance held by the shop.
- Supplier: shown inverted in UI (positive = shop owes supplier; negative = advance paid to supplier).
- Ledger rows are append-only. Corrections are reversal rows (BR-14).

**BR-8 Credit sale & limit.** Party flag `allow_credit` and `credit_limit` apply to customers. A sale that leaves `balance > credit_limit` (or any positive balance when credit is not allowed) is **blocked or warned** according to a setting `credit_limit_enforcement` = `block | warn` (default `block`; Admin/Manager can override when set to `warn`). Advance (negative balance) is applied automatically against new sales.

**BR-9 Receipts and payments.** Settled against the running balance (not invoice-by-invoice in v1). Paying more than the outstanding balance is allowed; the excess simply makes the balance negative (shown as **Advance**). Amounts paid inside the POS or Purchase screen automatically create a linked receipt / payment record that also appears in the Receipt / Payment history.

**BR-10 Payment methods.** `CASH` (default), `ONLINE`, `CHEQUE`.
- Cheque: has number, bank, cheque date (post-dated allowed), status `PENDING → CLEARED | BOUNCED`. [from earlier decisions]
- Ledger posting for cheques follows setting `cheque_ledger_posting` = `on_clearance` (default) or `on_receipt`. With `on_clearance`, the party balance changes only when the cheque is marked CLEARED; pending cheques are shown as a memo line ("Pending cheques: X"). BOUNCED cheques never post (or reverse if already posted).

**BR-11 Cash counter expected balance.** For a counter session: `expected = opening cash + Σ cash in − Σ cash out`. Only `CASH` method movements count. ONLINE and CHEQUE are excluded. Cash in = cash receipts + POS cash received + manual cash-in. Cash out = cash payments + cash expenses + manual cash-out.

**BR-12 Delete protection.** A master record (party, cylinder group, cylinder, expense category, counter) can be deleted **only if it has no child records** anywhere in the system. Otherwise the delete is refused with the reason ("Used in 12 sales") and the user is offered **Deactivate** instead. Inactive records stay in history but are hidden from new-transaction dropdowns.

**BR-13 Uniqueness.** Party code, cylinder group code, cylinder code are unique (case-insensitive). Document numbers are unique and sequential.

**BR-14 Immutability and void.** Posted sales/receipts/purchases/payments cannot be edited. A user with permission can **void** a document: the system writes reversal ledger rows, reverses cash entries, and restores cylinder location/gas — **only if no later movement on the affected cylinders conflicts** (e.g. a sale cannot be voided if one of its cylinders was already returned in a later transaction; void the later one first). The block message must name the conflicting document. To "edit", void and re-enter.

**BR-15 Concurrency.** Posting a POS sale locks the selected cylinder rows (`SELECT … FOR UPDATE`) inside a DB transaction and re-validates location/gas, so the same cylinder can never be issued twice.

**BR-16 Precision.** Money: `DECIMAL(14,2)`. Gas kg: `DECIMAL(10,3)`. Rate: `DECIMAL(10,2)`. Never use floats for money or gas. Display decimals follow settings.

**BR-17 Stock movement trail.** Every change to a cylinder's gas or location writes a `cylinder_movements` row (type, before/after gas, from/to location, customer, rate, source document). This is the cylinder's full history.

**BR-18 Cylinder sale (cylinder leaves our stock).** Two cases, both permanently remove the cylinder from shop stock (location = `SOLD`, `customer_id` NULL; the buyer is recorded on the movement and the sale line; the code stays reserved forever):
- *Empty cylinder sale* (POS type **Empty**): the cylinder has 0 gas. The customer is charged the group's **cylinder price** from Rate Configuration (effective-dated, editable in POS).
- *Filled cylinder sale*: any cylinder with gas > 0 can be sold **together with its gas** by ticking **Sell cylinder** on that cylinder (default **unticked**). Charge = `gas_kg × gas rate + cylinder price` (both editable). Instead of becoming ISSUED, the cylinder **and its gas** leave shop stock. [ASSUMPTION: gas + cylinder price — see section 11]
- A sold cylinder can never be issued, returned or purchased into again. It can only come back by voiding the sale (BR-14), which restores it to SHOP with its original gas.

**BR-19 Save confirmation for cylinder sales.** When a POS transaction contains any cylinder sale (Empty type, or any *Sell cylinder* tick), saving first shows a confirmation dialog, e.g. *"3 cylinders (45.0 kg gas) will be removed from shop stock permanently. 2 other cylinders will be issued."* with the list of cylinder codes. Nothing is saved until the user confirms; Cancel returns to the screen unchanged.

---

## 6. Functional requirements by screen

Common to all master/CRUD screens: searchable, sortable, server-side paginated list; Add/Edit in a modal or side form; inline validation; Active/Inactive filter; delete follows BR-12; responsive on mobile.

### 6.1 Configuration / Settings
Tabs (one tab per kind of setting):

| Tab | Settings |
|---|---|
| General | Business name, address, phone, logo, currency symbol, decimal places for money and gas, date format, timezone |
| Code Generation | See 6.1.1 |
| Sales & Credit | `credit_limit_enforcement` (block/warn), default payment method, allow rate edit in POS (yes/no), allow negative-balance advance (yes/no) |
| Tax | Tax enabled (default off), tax name, percentage, applies to gas sales |
| Cash Counters | Create/edit/deactivate counters; opening cash; session required (yes/no) |
| Cheques | `cheque_ledger_posting` (on_clearance/on_receipt) |
| Document Numbers | Prefix, width, next number for Sale, Receipt, Purchase, Payment, Expense |
| Printing | Receipt size (80 mm thermal / A4), header/footer text |
| Users & Roles | Users, roles, permission matrix [ADDED] |

**6.1.1 Code generation (configurable, one mode at a time)**
- `cylinder_code_mode` = `MANUAL` | `AUTO`.
  - `MANUAL`: user types the code; must be unique.
  - `AUTO`: system generates; code field is read-only/hidden on create.
- `cylinder_code_pattern` with tokens `{GROUP}` (group code), `{CAP}` (capacity, trailing zeros trimmed, `.` replaced by `_`), `{SEQ}` (sequence). Default `{GROUP}{CAP}-{SEQ}` → group `C`, 15 kg → `C15-000001`, `C15-000002`, …
- `cylinder_seq_width` (default 6). Sequence is **per prefix** (the pattern with `{SEQ}` removed), stored in `code_sequences`, incremented inside a transaction (no duplicates under concurrent use).
- Same switch for groups: `group_code_mode` = `MANUAL` | `AUTO` with `group_code_prefix` and width (default `G001`).
- Changing a mode affects **only new records**; existing codes are never rewritten. A warning explains this before saving.

### 6.2 Party Profile (customers, suppliers, referees)
Fields: **party type** (customer / supplier / referee; default customer), **code** (unique), **name**, phone, city, vehicle no., address, **allow credit sale** (yes/no), **credit limit** (visible and required only when allow credit = yes; applies to customers), **active** (yes/no), **opening balance** + debit/credit side [ADDED — needed to start with existing balances; entered manually, also importable].
Rules: delete per BR-12. Party type cannot be changed once the party has transactions. The list shows type, code, name, phone, current balance, active.
Referee: a party type only — **no commission and no financial behavior** (confirmed).

### 6.3 Cylinder Group
Fields: **code** (unique; manual/auto per 6.1.1), **name**, **capacity** (kg, decimal > 0), **active**. Delete per BR-12. Capacity cannot be changed once any cylinder exists in the group (would invalidate fill states) — show a clear message.

### 6.4 Cylinders (master/inventory list)
Fields: **cylinder group**, **code** (unique; auto-generated or manual per 6.1.1), **condition** (good / damaged), plus read-only: gas kg, **status** (filled / partially filled / empty / issued / sold per BR-1), current customer (if issued), last movement date.
- Add one cylinder here; bulk creation is done via Opening Stock (6.5) and by Purchase of new cylinders (6.10).
- Edit: condition, active, code only if the cylinder has no movements besides opening.
- Gas and location are **not directly editable** here; they change only through documents (opening stock, POS, purchase) or an admin **Stock Adjustment** action (with mandatory reason, writes a movement) [ADDED].
- Row click opens the cylinder's **history** (all movements).
- Filters: group, status (incl. Sold, hidden by default), condition, customer, search by code. Summary cards: cylinders in shop by state, issued, sold, damaged, total shop gas kg.

### 6.5 Opening Stock
Purpose: load the starting inventory; each row creates real cylinder records plus an `OPENING` movement.

Entry fields: **cylinder group**, **quantity**, **actual gas** (kg; decimal), **date**, **stock location** (`SHOP` / `ISSUED`), **customer** (required and shown only when location = ISSUED), cylinder **condition** (default good), and, in MANUAL code mode, the **cylinder codes**.
- Status is auto-derived (BR-1) and shown live next to actual gas (e.g. "Partially filled").
- `quantity = N` with AUTO mode creates N cylinders with consecutive codes, all with the same actual gas. In MANUAL mode the user enters/pastes N unique codes. If cylinders hold different gas amounts, the user adds separate rows (or uses import).
- Validation: gas ≤ capacity; codes unique; ISSUED ⇒ customer selected (BR-2).
- Opening ISSUED cylinders are linked to the customer and appear in "cylinders held". They do **not** create a sale ledger entry (the customer's money balance comes from the party opening balance). Their gas value is stored for later return credit using the current gas rate on the opening date. [ASSUMPTION]
- A summary shows **Total gas available (shop)** per BR-3.
- Opening Stock rows are saved as a batch (`stock_batch`) with a list/history screen; a batch can be voided if none of its cylinders have later movements.

**Excel import**
- **Download template** button (`.xlsx`) with columns: `group_code, group_name, capacity, cylinder_code (required in MANUAL mode, blank in AUTO), quantity (AUTO mode only; ignored in MANUAL), actual_gas, location (SHOP|ISSUED), customer_code (required if ISSUED), condition (GOOD|DAMAGED), date`. Include an instructions sheet and sample rows.
- Upload → **validation preview** (no data saved yet): per-row OK/Error with reasons; downloadable error report.
- Group auto-creation: if `group_code` does not exist it is created from `group_name` + `capacity` (if capacity conflicts with an existing group → error). Group code follows the group code mode.
- Customer must already exist (matched by `customer_code`); otherwise row error.
- Commit is **all-or-nothing** by default, with an option "import valid rows only". Everything is inside one DB transaction. Result summary: created groups, created cylinders, skipped rows. Import batches are logged (`import_batches`).
- Same import pattern is offered for **Parties** (with opening balances) [ADDED].

### 6.6 Rate Configuration
Fields: **date** (effective), **rate type** (dropdown: `GAS RATE (per KG)` / `CYLINDER RATE`), **rate** (decimal, e.g. 2, 2.34).
- `GAS RATE`: only the rate is entered.
- `CYLINDER RATE`: a **cylinder group** dropdown appears; the user sets the **cylinder price** per group (the price of an empty cylinder; also the cylinder component when a filled cylinder is sold with its gas, BR-18). Multiple groups can be set for the same date in one form (grid).
- List shows full **rate history** with current rate highlighted; rates are never edited after use in a posted document (add a new dated rate instead; unused rates may be deleted).
- Gas rate and cylinder price are pre-filled in POS; editable there (BR-4).

### 6.7 POS / Sales screen
Design goals: one screen, large touch-friendly controls, minimal typing, keyboard shortcuts for desktop.

**Header**
- **Customer** (search-as-you-type dropdown). On select, a one-row info bar shows: credit limit, current balance (or Advance), advance/deposit received, **cylinders currently held** (count and gas kg), pending cheques.
- **Transaction date** (default today), **counter** (default the user's counter; required if any cash received).

**Section A — Issue cylinders (gas sale)**
1. **Cylinder group** dropdown. Option text shows live availability, e.g. `C15 – 15 KG Cylinder – 98.5 KG gas available (7 filled, 3 partial)`.
2. **Cylinder type** selector: `Filled` / `Empty`.
   - `Filled`: popup lists SHOP cylinders in that group with gas > 0 (filled and partial), condition good.
   - `Empty`: popup lists SHOP cylinders with gas = 0, condition good. This is an **empty cylinder sale** (BR-18): no gas charge; price = group cylinder price from Rate Configuration (editable). If no cylinder price exists for the group, the sale is blocked with a clear message.
3. After choosing group + type, a **popup** shows every eligible cylinder as a **cylinder box** (card): big code, group, gas kg / capacity, fill-state badge, condition. Boxes are multi-select; search-by-code and "select N" shortcuts included. In the `Filled` popup **each box has a "Sell cylinder" checkbox (default unticked)**: ticked = the cylinder and its gas leave the shop (BR-18); unticked = normal issue (cylinder stays ours, becomes ISSUED). The footer shows live **selected quantity**, **total gas kg**, how many are marked *sell*, the **gas rate/kg** (pre-filled from BR-4, editable) and, when any cylinder is sold, the **cylinder price** (editable).
4. On confirm, selected cylinders are added to the **sales grid**: code, group, gas kg, **mode** (`Issue` / `Sell` — the same checkbox, can still be toggled here), gas rate, cylinder price (for Sell/Empty lines), amount, remove. Amount = `gas × gas rate` for Issue; `gas × gas rate + cylinder price` for a filled Sell; `cylinder price` for an Empty sale. Rates are editable per line.

**Section B — Return issued cylinders** (the "already issued to this customer" function)
1. Button **"Receive back cylinders"** opens a popup listing that customer's currently issued cylinders (cylinder boxes with code, group, gas at issue, issue date, issue rate), multi-select.
2. For each selected cylinder the grid shows **returned gas kg** (default 0; editable, ≤ capacity), **rate** (default = issue rate; editable), **credit amount** (= returned gas × rate).
3. Posting returns the cylinders to the shop with the returned gas, status recomputed (0 gas → EMPTY, else PARTIAL/FILLED).
4. Sections A and B can be in the **same transaction** (exchange: take back empties, issue filled). Net = issue amount − return credit (+ tax if enabled).

**Footer / totals**
Gas issue total, cylinder sale total, return credit, tax, **net amount**, previous balance, **received now** (amount + method + cheque details if cheque), **new balance**. If received > net + previous balance, show "excess becomes advance". Received amount creates a linked receipt (BR-9).

**Actions:** Save & Print, Save, Clear, Hold/Recall a draft [optional, phase 7].
**Validation:** customer required; at least one line; rate > 0; cylinders still available at save time (BR-15); credit limit (BR-8); counter required for cash.
**Save confirmation (BR-19):** if any cylinder is being sold (Empty type or *Sell cylinder* ticked), show the confirmation dialog before posting.
**Print:** thermal 80 mm or A4 receipt via print CSS: document no., customer, lines, totals, balance.

**Use cases the screen must support**
1. Sell gas only: issue filled/partial cylinders; they become ISSUED, disappear from shop stock, show as issued in reports; customer charged for gas only.
2. Customer returns cylinders later empty: gas 0, no credit; cylinder becomes EMPTY in shop.
3. Customer returns with gas left: credit = gas × rate; cylinder becomes PARTIAL/FILLED in shop.
4. Return at a different rate than issue: user edits rate; ledger reflects the edited rate.
5. Exchange: return + issue in one transaction.
6. Pay at POS: partial, full, or extra (advance); cash/online/cheque.
7. Sell an empty cylinder: charged the cylinder price; it leaves stock permanently.
8. Sell a filled cylinder with its gas: tick *Sell cylinder*; charged gas + cylinder price; after the confirmation prompt, the cylinder and its gas leave shop stock instead of being issued.
9. Mix issue, sell and return lines in one transaction.

### 6.8 Sales History
Detailed report of every POS transaction. Filters: date range, customer, cylinder group, cylinder code, line type (issue/return/cylinder sale), payment method, user, status (posted/void). Columns: doc no, date, customer, lines count, issued gas kg, returned gas kg, cylinders sold, amounts, received, balance after, user. Click-through shows full detail and linked receipt. Totals row; export to Excel/CSV and print. Void action (BR-14) with reason.

### 6.9 Receipts (money from customers)
Fields: date, **customer**, shows **OS balance** (or advance), **amount**, **method** (Cash default / Online / Cheque), counter (cash only), reference/narration; cheque fields when cheque (number, bank, cheque date).
- Extra amount over OS balance is accepted → advance (BR-9).
- Tabs: **New Receipt** and **Receipt History** (includes receipts created from POS, labelled `Source: POS` with a link to the sale). History filters: date, customer, method, status; void per BR-14.
- Print receipt voucher.

### 6.10 Purchase (from suppliers)
Fields: date, **supplier**, supplier invoice no., notes. A purchase can contain two kinds of lines, added with two buttons (both options are always available):

**A. Add gas to existing cylinders (refill)**
- Choose cylinders currently in the shop (box popup, filter by group/state; condition good, location SHOP).
- Per cylinder: **gas added kg** (≤ capacity − current gas; button "fill to capacity"), **rate per kg** (default last purchase rate for this supplier, editable), amount = kg × rate.
- Effect: cylinder `gas_kg` increases, movement `PURCHASE_FILL`.

**B. Add new cylinders**
- Fields: **cylinder group**, **quantity**, **condition** (default good), **initial gas kg** per cylinder (default 0 = empty; "filled" shortcut sets it to capacity; ≤ capacity), **cylinder cost** per cylinder, **gas rate per kg** (for the initial gas; default last purchase rate).
- Codes follow section 6.1.1: AUTO generates consecutive codes; MANUAL requires N unique codes (paste/scan a list).
- Amount = `quantity × cylinder cost + quantity × initial gas × gas rate`.
- Effect: N new cylinders created in the shop (`source = PURCHASE`, location SHOP, fill state derived), movement `PURCHASE_NEW`. This grows our cylinder fleet; shop stock and gas totals update.

Totals; **paid now** (amount + method) creates a linked payment (BR-9). Supplier ledger is credited (payable) for the total.
Void (BR-14): refill lines restore the previous gas; new-cylinder lines are voided only if none of those cylinders has a later movement (then they are deactivated and hidden, and their codes stay reserved).

### 6.11 Payments (money to suppliers)
Mirror of Receipts: supplier, OS balance, amount (extra → advance to supplier), method Cash/Online/Cheque, counter for cash, history tab including payments created from Purchase screen.

### 6.12 Cash Counter / Register
- Multiple counters (Settings). Each has sessions: **Open** (opening cash) → transactions → **Close** (counted cash, variance = counted − expected, notes). One open session per counter at a time (if "session required" is on).
- Screen shows, for the selected counter and session/date: opening cash, **cash in** (receipts, POS cash), **cash out** (payments, expenses), manual cash-in/out entries (with reason) [ADDED], transfers between counters [ADDED], and **expected balance in drawer** (BR-11). Online and cheque are excluded.
- Every cash entry links to its source document. Daily/shift report printable.

### 6.13 Cheque Register [ADDED]
List of all cheques received/issued with status PENDING / CLEARED / BOUNCED, post-dated indicator, due-soon highlight. Actions: mark cleared (date) or bounced (reason). Posting follows BR-10.

### 6.14 Expenses
Record shop utility expenses. Fields: date, **expense category** (master list: electricity, gas bill, water, internet, rent, misc — editable), amount, method (cash → counter required), reference no., payee/notes, attachment (optional image/PDF). List with filters, totals by category, void per BR-14.

---

## 7. Data model (logical)

All tables InnoDB, `utf8mb4`, `id BIGINT UNSIGNED` PK, `created_at/created_by/updated_at/updated_by`, FKs `ON DELETE RESTRICT`. Indexes on all FKs and on (`status`), (`entry_date`), (`code`).

| Table | Key columns |
|---|---|
| `users`, `roles`, `role_permissions` | username (unique), password_hash, role_id, default_counter_id, active |
| `settings` | `group`, `key` (unique), `value` |
| `code_sequences` | `prefix` (unique), `last_value` |
| `doc_sequences` | `doc_type`, `prefix`, `last_value` |
| `parties` | type, code (unique), name, phone, city, vehicle_no, address, allow_credit, credit_limit, opening_balance (signed), active |
| `cylinder_groups` | code (unique), name, capacity DECIMAL(10,3), active |
| `cylinders` | code (unique), group_id, condition, gas_kg, location (`SHOP`,`CUSTOMER`,`SOLD`), customer_id NULL (set only when `CUSTOMER`), source (`OPENING`,`MANUAL`,`PURCHASE`), active — CHECK: location=`CUSTOMER` ⇒ customer_id NOT NULL |
| `cylinder_movements` | cylinder_id, moved_at, type (`OPENING`,`ISSUE`,`RETURN`,`PURCHASE_FILL`,`PURCHASE_NEW`,`SALE_OUT`,`ADJUSTMENT`,`VOID_REVERSAL`), from_location, to_location, customer_id, gas_before, gas_after, rate, doc_type, doc_id, notes |
| `stock_batches` | batch_date, source (`MANUAL`,`IMPORT`), status, notes; children link via cylinder_movements.doc_id |
| `import_batches` | type, filename, rows_total, rows_ok, rows_error, status, error_report_path |
| `rates` | effective_date, rate_type (`GAS_PER_KG`,`CYLINDER`), group_id NULL, rate DECIMAL(10,2); UNIQUE(effective_date, rate_type, group_id) |
| `sales` | doc_no (unique), txn_date, customer_id, counter_id, issue_total, cylinder_sale_total, return_total, tax_amount, net_amount, received_amount, balance_after, status (`POSTED`,`VOID`), void_reason, notes |
| `sale_lines` | sale_id, line_type (`ISSUE`,`RETURN`,`SELL_FILLED`,`SELL_EMPTY`), cylinder_id, gas_kg, rate (gas rate), cylinder_price, amount, issue_line_id NULL (for returns → the issue line) |
| `receipts` | doc_no, receipt_date, party_id, amount, method, counter_id, cheque_id NULL, source (`MANUAL`,`POS`), sale_id NULL, status, narration |
| `purchases` / `purchase_lines` | supplier_id, supplier_invoice_no, date, total, paid_amount, status / line_type (`GAS_FILL`,`NEW_CYLINDERS`), cylinder_id NULL (GAS_FILL), group_id, quantity, gas_kg, cylinder_cost, gas_rate, amount (created cylinders are linked through `cylinder_movements`) |
| `payments` | mirror of receipts for suppliers (`source` = `MANUAL`/`PURCHASE`, `purchase_id`) |
| `cheques` | direction (`IN`,`OUT`), party_id, cheque_no, bank, cheque_date, amount, status (`PENDING`,`CLEARED`,`BOUNCED`), cleared_date, linked receipt/payment id |
| `ledger_entries` | party_id, entry_date, doc_type, doc_id, debit, credit, narration, reversal_of NULL — append-only |
| `counters` | name, opening_cash, active |
| `counter_sessions` | counter_id, opened_at, opened_by, opening_cash, closed_at, closed_by, counted_cash, expected_cash, variance |
| `cash_entries` | counter_id, session_id, entry_date, direction (`IN`,`OUT`), amount, doc_type, doc_id, reason |
| `expense_categories`, `expenses` | name / date, category_id, amount, method, counter_id, reference, notes, attachment, status |
| `audit_log` | user_id, action, entity, entity_id, old_json, new_json, ip, created_at |

---

## 8. Reports

**MVP:** Stock summary (by group: shop filled/partial/empty counts and gas kg; issued count and gas kg; damaged; sold count), Cylinder history by code, **Cylinders sold** (by date/customer/group), Customer ledger (date range, running balance), Supplier ledger, Customers' outstanding balances, **Cylinders held by customer** (who has which cylinder, since when, gas at issue), Daily cash register report, Sales history (6.8), Receipt/Payment history, Expense report by category/date.
**Later:** profit estimate (gas sale vs purchase cost), aging, dashboard widgets (today's sales, cash, stock, top customers).
All reports: date filters, print-friendly, export to Excel/CSV.

---

## 9. Non-functional requirements
## 9.1 Application Bootstrap, Database Creation & Automatic Migrations [ADDED]

- When the application reaches the login-page load, it must first establish the configured MySQL connection.
- If DB_NAME does not exist, the application must create the configured database automatically using the configured DB host, port, username and password, then reconnect to it.
- After a successful database connection, the application must check the migrations table and execute every pending SQL migration in natural filename order before the login page is rendered.
- Already-applied migrations must be skipped and never executed again.
- Migration execution must be serialized so simultaneous login-page requests cannot apply the same migration concurrently.
- Each migration must be recorded only after its SQL completes successfully. A failed migration must stop the bootstrap and must not be marked as applied.
- Database creation requires the configured DB user to have the MySQL CREATE DATABASE privilege. If automatic creation or migration fails, the login page must not continue as if the application were ready; the error must be logged and surfaced according to the application's environment/error-display policy.
- The manual bin/migrate.php command remains available for administrators/deployment automation and must use the same migration engine as the login bootstrap.
- No application code may hard-code a deployment URL; database configuration remains environment-driven through .env.


- **Security:** password hashing (`password_hash`), session hardening, CSRF token on every state-changing request, output escaping, prepared statements only, per-action permission checks on the server (not only hidden buttons), login throttling, audit log.
- **Integrity:** all stock/ledger/cash postings in single DB transactions; no partial saves.
- **Usability:** English UI; Bootstrap 5.3 responsive layout; works on phones and tablets (POS cylinder boxes reflow into a grid); large tap targets; clear error messages; confirm on destructive actions.
- **Performance:** server-side pagination for lists; popups load cylinders via AJAX and handle ≥ 2,000 cylinders smoothly; indexed queries; typical screen < 1 s on a basic shared host.
- **Deployment:** runs on a standard LAMP/XAMPP stack (PHP 8.1+, MySQL 8 / MariaDB 10.6+); assets served locally (no CDN dependency) so the shop works with unreliable internet.
- **Backup:** documented `mysqldump` backup/restore procedure; optional backup button for Admin.
- **Browser support:** current Chrome, Edge, Firefox, Safari (mobile too).

---

## 10. Acceptance scenarios (use as test cases)

| # | Scenario | Expected result |
|---|---|---|
| AT-1 | AUTO mode, group `C` capacity 15, opening stock qty 3, gas 15, SHOP | 3 cylinders `C15-000001..3`, status FILLED, shop gas +45 kg |
| AT-2 | Opening stock gas 7.5 on a 15 kg group | status PARTIAL; gas 20 on 15 kg group is rejected |
| AT-3 | Opening stock location ISSUED without customer | Save blocked; with customer → cylinder shows ISSUED, linked to customer, not in shop gas |
| AT-4 | Switch to MANUAL mode, add duplicate code | Rejected as duplicate; existing codes untouched |
| AT-5 | Excel import with unknown group `D`, capacity 30 | Group `D` auto-created, rows validated; a row with a missing customer for ISSUED is flagged and nothing is committed (all-or-nothing) |
| AT-6 | Rate: gas rate 240 on 1 Oct, 250 on 5 Oct; sale dated 3 Oct | Rate pre-fills 240; sale dated 6 Oct pre-fills 250 |
| AT-7 | POS: issue 2 cylinders with 15 and 10.5 kg at rate 250 | Amount = 3750 + 2625 = 6375; both ISSUED; shop gas −25.5 kg; customer balance +6375 |
| AT-8 | Same customer returns the 10.5 kg cylinder with 2 kg gas, rate left at 250 | Credit 500; cylinder in SHOP, gas 2, PARTIAL; customer balance −500 |
| AT-9 | Return with gas 0 | Credit 0; cylinder EMPTY in shop |
| AT-10 | Return rate edited from 250 to 230 with 2 kg gas | Credit 460; ledger reflects 460 |
| AT-11 | Two cashiers try to issue the same cylinder simultaneously | One succeeds; the other gets "cylinder no longer available" and no partial data |
| AT-12 | Credit limit 5,000 (enforcement = block), sale pushes balance to 6,375 | Sale blocked with clear message |
| AT-13 | Receipt of 10,000 when OS balance is 6,375 | Balance becomes −3,625 shown as Advance 3,625 |
| AT-14 | Cheque receipt (on_clearance) | Balance unchanged, pending-cheque memo shown; mark CLEARED → balance reduces; BOUNCED → never posts |
| AT-15 | Delete a party that has a sale | Refused with reason; Deactivate offered; party with no records deletes fine |
| AT-16 | Void a sale whose cylinder was returned in a later sale | Blocked, message names the later sale; after voiding the later one, void succeeds and stock/ledger are restored |
| AT-17 | Purchase: top-up cylinder from 2 kg to 15 kg at rate 200 | Gas +13, status FILLED, supplier payable +2600, shop gas +13 |
| AT-18 | Cash counter: opening 5,000; cash receipt 2,000; online receipt 3,000; cash expense 500 | Expected = 6,500 (online excluded) |
| AT-19 | Receipt created inside POS | Appears in Receipt History as `Source: POS` linked to the sale |
| AT-20 | Cashier role opens Settings → Users | Access denied server-side (HTTP 403), not just hidden |
| AT-21 | Filled 15 kg cylinder, gas rate 250, cylinder price 4,500, *Sell cylinder* ticked | Confirmation prompt appears; after confirm amount = 3,750 + 4,500 = 8,250; cylinder SOLD (not issued, not in shop stock); shop gas −15 kg; customer balance +8,250. Cancel at the prompt saves nothing |
| AT-22 | Empty cylinder sale, cylinder price 4,500 | Amount 4,500; cylinder SOLD; shop empty count −1; no gas change. If no cylinder price exists for the group the sale is blocked |
| AT-23 | One transaction: 2 filled cylinders issued (15 kg each) + 1 filled cylinder sold, gas rate 250, cylinder price 4,500 | Prompt says 1 cylinder (15 kg) leaves stock, 2 are issued; amount = 3,750 + 3,750 + 8,250 = 15,750 |
| AT-24 | Purchase new cylinders: group C (15 kg), qty 5, AUTO codes, initial gas 15, cylinder cost 4,000, gas rate 200 | 5 new FILLED cylinders in shop (`source = PURCHASE`); shop gas +75 kg; amount = 20,000 + 15,000 = 35,000 added to supplier payable. MANUAL mode requires 5 unique codes |
| AT-25 | Void a sale containing a sold cylinder | Cylinder returns to SHOP with its original gas; ledger reversed |
| AT-26 | Void a new-cylinder purchase after one of its cylinders was issued | Blocked with message naming the sale; allowed once the sale is voided |

---

## 11. Decisions and remaining assumptions

**Confirmed by the product owner**
1. **Empty cylinder** in POS = outright sale of an empty cylinder; price comes from Rate Configuration; it reduces our stock (BR-18).
2. **Filled cylinder** can also be sold with its gas via a per-cylinder *Sell cylinder* checkbox (default unticked). A confirmation prompt at save states how many cylinders and how much gas leave shop stock, instead of the cylinders becoming ISSUED (BR-18, BR-19).
3. **Referee** is only a party type; no commission.
4. **Purchase** adds gas to existing shop cylinders **and** can add new cylinders from the supplier; both options are always available (6.10).

**Remaining assumptions (defaults implemented unless told otherwise)**
1. Price of a filled cylinder sold with gas = `gas × gas rate + cylinder price` (both editable). If the cylinder price should not be added in that case, tell me.
2. Opening **issued** cylinders create no ledger charge (money owed comes from the party opening balance).
3. "Deposit received" in the POS info bar means the customer's **advance balance**, not a refundable cylinder deposit.
4. Damaged cylinders are excluded from POS selection and sellable gas.
5. Credit limit default is **block** (not just warn).
6. Every sale needs a customer (issued cylinders must be linked). Create a generic "Walk-in" customer if needed (cash only, no credit).
7. Posted documents are voided, not edited.
8. "Daraz" in the original text is interpreted as **drawer** (cash drawer).
