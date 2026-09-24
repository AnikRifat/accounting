# Frish Accounts — delivery plan and decisions

Checkpoint for the `loop-delivery` run started 2026-09-24. Update statuses as increments land.

## Goal

A multi-company income and expense accounting system for one business owner with 3–4+ companies,
built as the first module of a future ERP. Finish line: verified, deploy-ready project with local
setup and handover. Deployment itself is excluded.

## Confirmed decisions (Anik, 2026-09-24)

| Topic | Decision |
| --- | --- |
| Scope | Income, expense, multi-company. Plus a basic employee list (company, code, name, designation, department, phone, monthly salary, joining date, active). No attendance, leave or payroll. No asset or inventory modules. |
| Ledger | Double-entry underneath, simple forms on top. Every entry posts balanced debit and credit lines against a per-company chart of accounts. |
| Access | Owner/administrator see all companies and consolidated reports. Other users are assigned to specific companies and see only those. Every entry records who created, updated or voided it. |
| Money | BDT only. No inter-company transfer feature; money moved between companies is recorded as expense in one and income in the other. |
| Hosting | cPanel shared hosting with MySQL/MariaDB. No Node, queue worker or long-running process at runtime; assets are built before upload. |
| UI language | English. Every user-facing string goes through `__()` with the English text as the key, so a Bangla `lang/bn.json` can be added later without code changes. |
| Super admin | Owner login email `superadmin@gmail.com` (used by the demo seeder; for a real install, create it with `php artisan app:create-admin`). No default password on real installs; the local-only demo seeder uses the fixed password `password` (Anik's decision). `DatabaseSeeder` calls `DemoSeeder` (Anik's edit), so the seeder itself must refuse outside local/testing and on non-empty books. |
| Entry form (revision, 2026-09-24) | Income/expense entries use **Category** (dynamic income/expense categories), **Party** (who paid, received or was spent on), **Payment method** (Cash, Bank, bKash, …) and support **partial payment**: total amount, amount paid now, and a due date for the remainder, settled later by any number of payments. The "Income account" and "Received into" fields are removed. |
| Parties | Per company. Every employee is automatically a party in their company; custom parties can be added. |
| Dues basis | Accrual: income and expenses count in full on the entry date. The unpaid part is a receivable or payable against the party until settled. |
| Due schedule | One due date per entry for the remaining balance, with any number of later payments or receipts (each with its own date, amount and method). No instalment plans. |
| Stack | Backend of the house starter (`~/Development/boilarplate/backend`): Laravel 13, Livewire 4, Tailwind 4, SQLite locally, PHPUnit. No Next.js frontend. No new dependencies. |

## Coordinator decisions (reversible, convention-based)

- Amounts are stored as integer **paisa** in `BIGINT` columns. Use `App\Support\Money` for parsing,
  input values and display (৳ with lakh/crore grouping). Never use floats for money.
- Company scoping is explicit: `Company::visibleTo($user)`, `$user->accessibleCompanyIds()` and
  `$user->canAccessCompany($id)`. The permission `companies.all` (owner/administrator) grants every
  company. All other roles see only companies in the `company_user` pivot. Every query and every
  write that touches company data must be scoped, and every write must also check the company
  server-side.
- Roles: `owner`, `administrator` (all), `accountant` (accounts, entries, reports, employees),
  `data-entry` (view, and create entries only), `member` (API/media only; public registration is
  now **off** by default). The starter's `employee` role is gone.
- User management without `companies.all` is limited to accounts whose companies the manager can
  all see and whose role ceiling is within the manager's own permissions (no takeover of a more
  powerful account). See `App\Livewire\Admin\Users\ManageableUsers`.
- Entries are never deleted. They can be edited (`entries.update`) or voided with a reason
  (`entries.void`). Voided entries stay visible and are excluded from balances and reports.
- Within-company transfers (e.g. withdrawing from the bank to cash) are included, because cash and
  bank balances would be wrong without them. Opening balances for cash and bank accounts are
  posted against a system account called *Opening Balance Equity*.
- Timezone `Asia/Dhaka`. Reports offer a Bangladesh fiscal-year preset (1 July – 30 June).
- Public API self-registration is off by default (`config/settings.php`). The API and media code
  from the starter are kept but unused.

## Data contracts

`companies`: id, name, code (≤10, unique, uppercase), address?, phone?, is_active. Pivot `company_user`.

`employees`: id, company_id FK, employee_code (unique per company), name, designation?, department?,
phone?, monthly_salary BIGINT paisa default 0, joined_on date?, is_active, timestamps.
There is no `user_id`: employees are staff records, not logins.

`accounts`: id, company_id FK, code (unique per company), name (unique per company),
type `asset|liability|equity|income|expense` (enum `App\Enums\AccountType`), is_cash (cash or bank
account that receives or pays money), is_system (not editable), is_active, timestamps.

`journal_entries`: id, company_id FK, number (unique per company, `<CODE>-000001`), entry_date,
type `income|expense|transfer|opening` (enum `App\Enums\EntryType`), amount BIGINT paisa,
description?, reference?, paid_by FK users (income/expense/receipt/payment: who paid or received; required,
defaults to the recorder, only the super admin picks another), created_by, updated_by?, voided_at?, voided_by?,
void_reason?, timestamps. Index on (company_id, entry_date).

`journal_lines`: id, journal_entry_id FK cascade, account_id FK, debit BIGINT, credit BIGINT
(exactly one side > 0). The sum of debits equals the sum of credits for every entry.

Posting rules:

| Type | Debit | Credit |
| --- | --- | --- |
| Income | cash/bank account (received into) | income account |
| Expense | expense account | cash/bank account (paid from) |
| Transfer | cash/bank (to) | cash/bank (from, different account) |
| Opening | cash/bank account | Opening Balance Equity |

Default chart created with every new company: 1000 Cash in Hand (asset, cash), 1010 Bank Account
(asset, cash), 3000 Opening Balance Equity (equity, system), 4000 Sales & Service Income,
4900 Other Income, 5000 Salaries & Wages, 5100 Office Rent, 5200 Utilities,
5300 Transport & Conveyance, 5400 Office Supplies, 5900 Other Expenses.

## Increment 5 contract: parties, categories, payment methods and dues

Migrations are edited in place: there is no production data, and local databases are rebuilt
with `migrate:fresh`. Final order: `000001` companies, `000002` employees, `000003` accounts,
`000004` parties, `000005` journal tables (renamed from `000004`).

**Accounts** (`000003`):
- Add `payment_type` (nullable string, enum `App\Enums\PaymentType`: Cash, Bank, MobileBanking,
  Card, Other). It is required when `is_cash` is true and null otherwise. A **payment method** is
  an `is_cash` account.
- Add an optional `details` column (string 255), e.g. an account or wallet number.
- A **category** is an active, non-system income or expense account.
- Default chart additions:
  - 1020 bKash (asset, cash, MobileBanking)
  - 1200 Accounts Receivable (asset, system)
  - 2000 Accounts Payable (liability, system)
- Existing defaults: 1000 Cash in Hand gets payment_type Cash, and 1010 Bank Account gets Bank.
- Categories and payment methods created from their own screens get an automatic code: the next
  free number in 4000–4999 (income), 5000–5999 (expense) or 1000–1199 (payment method). Users
  never type codes there.

**Parties** (`000004`, table `parties`):
- Columns: id, company_id FK, employee_id nullable unique FK (restrict), name (150), phone (40)?,
  address (255)?, notes (500)?, is_active, timestamps. Index company_id.
- `App\Models\Party` has `scopeVisibleTo(Builder, User)`.
- An employee party is created automatically when the employee is created. Name, phone and
  is_active are synced when the employee changes. Employee parties can't be edited directly on the
  party screen; changes go through the employee.
- Custom parties are managed on the Parties screen.
- Permissions: parties.view, parties.create, parties.update.

**Journal entries** (`000005`):
- Replace `employee_id` with `party_id` (nullable FK parties, restrict).
- Add `due_date` (nullable date, stored as plain `Y-m-d`).
- Add `bill_id` (nullable FK to `journal_entries`, restrict): the income or expense entry that a
  settlement pays.
- `amount` means:
  - the total amount, for income and expense;
  - the paid amount, for settlements, transfers and openings.
- `EntryType` gains `Receipt` (money received against an income due) and `Payment` (money paid
  against an expense due).
- Lines: 2 or 3 per entry, balanced, and each line one-sided.

| Type | Lines (T = total, P = paid now, 0 ≤ P ≤ T, D = T − P) |
| --- | --- |
| Income | Cr category T; Dr payment method P (if P > 0); Dr Accounts Receivable D (if D > 0) |
| Expense | Dr category T; Cr payment method P (if P > 0); Cr Accounts Payable D (if D > 0) |
| Receipt (bill = income entry) | Dr payment method X; Cr Accounts Receivable X |
| Payment (bill = expense entry) | Dr Accounts Payable X; Cr payment method X |
| Transfer, Opening | unchanged |

Rules:
- **When a due exists (D > 0):** party and due date are required; the due date must be on or
  after the entry date.
- **Payment method:** required when P > 0, and must be an active payment method of the same
  company. Category and party must also belong to the same company.
- **Party** is optional on fully paid entries.
- **Settling a due:** X must be > 0 and ≤ the outstanding amount. The date must be on or after the
  bill's date. The settlement takes the party from the bill.
- **Outstanding** of a bill = its receivable/payable line amount − the sum of its posted
  settlements. Always derived by aggregate query; never stored.
- **Voiding a bill** is refused while it has posted settlements. Voiding a settlement reopens that
  amount.
- **Editing a bill:**
  - its total can't go below the amount already settled after the entry;
  - its party can't change once settlements exist;
  - its category, payment method and paid-now amount can change;
  - company and type stay fixed, as before.
- **Settlements** can be voided, and edited with the same limits.

**UI:**
- **Entry form fields:** company, date, category, party (searchable, with quick "add party" when
  the user has parties.create), total amount, paid now (defaults to the total), payment method
  (defaults to Cash), due date (shown only when paid < total), reference, description.
- **Settling dues:** a bill with an outstanding balance offers "Receive payment" or "Make
  payment", which records a settlement.
- **Transactions list:**
  - shows party, paid and due, and a due status (Paid, Partly paid, Due, Overdue);
  - filters by party and due status;
  - the CSV export includes party, paid, due and due date.
- **Nav:** Categories and Payment methods (accounts.view; manage needs accounts.manage), and
  Parties (parties.view). Chart of accounts moves into the Reports hub as an advanced view.
- **Reports:**
  - a Dues report: receivables and payables per party with each open bill, due date and overdue
    flag, for one company or all visible companies;
  - a Party statement: every entry and settlement for a party, with a running due;
  - Employee cost is based on employee parties and also requires `employees.view`;
  - the dashboard adds total receivable, total payable, overdue count and the next 5 dues.

## Increment 8: employees become users (Anik, 2026-09-24; relayed and implemented by session frish-5f)

- Every employee is a user and must log in. The separate Employees module, model, migration,
  `employees.*` permissions and tests are removed.
- A user can belong to many companies, with no home company, and gets an automatic party in each
  assigned company (`parties.employee_id` becomes `user_id`).
- Exactly one super admin (owner) exists and bypasses all permission checks. Administrator becomes
  a normal, editable role, no longer fixed to `*`.
- Also reworked: the Employee cost report (by user parties), the Parties screens and the
  DemoSeeder.
- Until then, no new code may depend on `Employee`.

## Increment 7 contract: header company switcher (Anik, 2026-09-24)

Decisions:
- One company switcher in the header replaces every per-page company filter and form field.
- "All companies" shows data combined; one company shows only that company.
- In All mode, Categories and the Chart of accounts show one combined row per name, and adding a
  category adds it to every company. Each company keeps its own books underneath.
- Creating a record while on "All companies" first asks for a company, then switches the header to
  it.

Coordinator-built foundation (done, tested in `tests/Feature/CompanyContextTest.php`):
- **`App\Support\CompanyContext`** (resolve with `app(CompanyContext::class)`, never cache it):
  - `options()`: visible companies, including inactive ones;
  - `selectedId(): ?int`: null means all; a single-company user is pinned to it; a stale or
    invisible selection returns null;
  - `isAll()`, `company(): ?Company`;
  - `companyIds(): list<int>`: `[selected]` or every visible id;
  - `select(?int): bool`.
  - Session key `company_context`.
- **`App\Livewire\CompanySwitcher`** in the layout header. It uses the searchable `x-form.select`
  and reloads the current page on change.
- **Route middleware alias `company.selected`**: when the context is All, or the selected company
  is inactive, it redirects to `admin.choose-company?next=<uri>`. `App\Livewire\Admin\ChooseCompany`
  lists active visible companies, selects one, and follows only `/admin/…` paths.

Rules for every module:
1. **No company filters or pickers.** Remove every company select or filter property and control,
   and every use of the old `ledger.company_id` session key. The read scope is
   `CompanyContext::companyIds()`. It is always a subset of the visible companies.
2. **Company column.** In All mode, lists and reports show a Company column or grouping. In
   one-company mode, hide it.
3. **Create pages** (entries.create, parties.create, employees.create, payment-methods.create,
   accounts.create) get the `company.selected` middleware.
   - The form takes its company from `CompanyContext::company()`, never from a client property.
   - On save, the server re-checks it: the company is active and visible. If the context changed
     in another tab, the save fails with a clear error.
   - The company name is shown as read-only text in the page header.
   - The quick-add category/party drawers in the entry form validate against that same company.
4. **Categories create:** no middleware.
   - In All mode it creates the category in every active visible company that doesn't already
     have that name, each with `nextCode`, in one transaction. The flash message names any
     companies that were skipped.
   - In company mode it creates the category in that company only.
5. **Editing:** a record's company comes from the record, and there is no company field.
   - Records outside `accessibleCompanyIds()` → 404, as before.
   - Editing works in either mode.
6. **Combined views in All mode:**
   - **Categories:** rows grouped by (type, name), showing which companies have each (edit links
     per company, which switch context to that company).
   - **Chart of accounts:** grouped by (type, name) with the summed balance and a per-company
     breakdown.
   - **Payment methods:** grouped by company with a grand total; not merged, since they are real
     separate accounts.
   - **Trial balance:** consolidated by (type, name) and still balanced.
   - **Income statement:** per-company columns plus a Total.
   - **Dues, Employee cost and the Dashboard:** combined across companies.
   - **Account ledger and Party statement:** need one company. In All mode they show an empty state
     with a "choose a company" link (`admin.choose-company?next=<current>`).
7. **Tests:** set the context with `session([CompanyContext::SESSION_KEY => $id])`, or through the
   switcher. Isolation tests must prove that a crafted session value for an invisible company falls
   back to All-visible, and that crafted Livewire properties can't pick a company.

## Increments

| # | Increment | Owner | Status |
| --- | --- | --- | --- |
| 0 | Foundation: starter copy, companies schema, company access, Money, roles/permissions, settings | coordinator | done; 48 tests green |
| 1 | Organisation & people: companies CRUD, user↔company assignment, employees module rebuilt, starter coupling removed | agent `org` | done: 68 tests green; employee company fixed after creation; dashboard employee count must be scoped (increment 3) |
| 2 | Ledger: accounts, journal schema, `LedgerService`, default chart, accounts UI, income/expense/transfer forms, transactions list with filters, void, CSV export | agent `ledger` | done: 93 tests green combined, Pint clean, build OK; balance API `LedgerService::balance()/balances()` |
| 3 | Reports & dashboard: per-company and consolidated income statement, account ledger, trial balance, employee cost report, dashboard KPIs, Frish demo seeder | agent `reports` | in progress |
| 4a | Review of increments 0–2 | agent `review` | done: 12 findings; fixes dispatched (opening balance on default cash accounts, company-scoped user management, trim before unique, unique-rule oracle, number race, inactive companies closed for new entries, joined_on storage, i18n, 11-digit money cap, MySQL session tz +06:00) |
| 3 result | Reports, dashboard, DemoSeeder | agent `reports` | done: 121 tests green combined, Pint clean, build OK |
| 4b-mysql | MySQL 9.7 check on local `frish` (approved: migrate + demo seed only) | coordinator | done: 12 migrations ran; 467 entries, 0 unbalanced; all 4 trial balances balance (same figures as SQLite); session tz +06:00; every report, dashboard and index renders under ONLY_FULL_GROUP_BY + strict |
| 4b | Review of increment 3 + behavior verification of criteria 1–8 | agents `review-3`, `verify` | in progress |
| 5a | Ledger core for parties and dues: schema, LedgerService posting and settlements, Entries form and list, settle action, CSV | agent `ledger` | pending |
| 5b | Parties module and employee→party sync; Categories and Payment methods screens | agent `org` | done: 53 tests in its files green; party company fixed after creation; employee name ≤150 (coordinator) |
| 5c | Dues report, party statement, employee cost on parties, dashboard dues, DemoSeeder rework and guard, review-3 fixes | agent `reports` | after 5a |
| 4c | Behavior verification of criteria 1–7 (pre-rework code) | agent `verify` | PASS 1–7, integrity invariants hold, 70-request HTTP smoke clean; AC 8 not run (superseded). Re-run after increment 5. Verification note: `artisan serve` reloads `.env` (MySQL) even with a `DB_*` override, so use `php -S … server.php` for SQLite smoke runs. |
| 5a result | Ledger core for dues | agent `ledger` | done: 37 tests in its files; all 11 extra rules accepted (unpaid part ≥ settled, bill date ≤ first settlement, etc.) |
| 5c result | Dues report, party statement, employee cost on parties, dashboard dues, DemoSeeder guard + rework | agent `reports` | done: 159 tests green combined; seeder refuses outside local/testing and on non-empty DB, exits 1 |
| 7a | Header switcher foundation | coordinator | done |
| 7b | Entries (index, form, settle, export), Chart of accounts | agent `ledger` | done: 41 tests in its files; companyId `#[Locked]` from context; CSV keeps Company column in both modes |
| 7c | Parties, Employees, Categories, Payment methods | agent `org` | done: 177/177 combined, Pint clean, build OK; save re-checks the context company; All-mode category create skips companies that already have the name |
| 7d | Reports, Dashboard | agent `reports` | done: context-scoped; consolidated trial balance; ledger/party statement ask for a company in All mode |
| 7e | New company from the header (Anik): "+ New company" beside the switcher and "Add company" on the Companies list both open one off-canvas drawer (`App\Livewire\CreateCompanyDrawer`, event `open-create-company`); shared `Company::formRules()` / `Company::createBy()` | coordinator | done: 2 tests; switches the header to the new company |
| 6-mysql | Rebuild local MySQL `frish` with `migrate:fresh --seed` (Anik approved 2026-09-24) and re-check the dues queries on MySQL | coordinator | done: 589 entries balanced (2–3 lines each), all 4 trial balances balance, AR/AP equal dues totals per company, 13 pages render in All and single-company mode on MySQL 9.7 |
| 7-review | Read-only review of increments 5 and 7 (excluding increment 8 areas) | agent `review-5-7` + coordinator fixes | done: 8 findings. Fixed: (1, security) opening-balance edits need accounts.manage; (2) locking reads for bill/first-settlement dates; (3) deadlock retries (3 attempts) on ledger writes; (4) All-mode name merge case/space-insensitive in trial balance and income statement; (5) header switch keeps the page query string (same-site admin Referer only); (6) chooser lists inactive companies for report targets. Open: (7) duplicate-name races in category/payment-method/quick-add forms return 500 (low); (8) select/drawer a11y gaps relayed to frish-79. Suite 181/181. |
| 8 | Employees become users (staff fields on `users`, one party per assigned company via `User::syncParties()`, single super admin via `Gate::before`, editable system roles) | session frish-5f | done (commit 20607fc); 177/178 green; the 1 failure is DemoSeederTest asserting the owner password is not 'password', which conflicts with Anik's local edit setting the demo password to 'password' |
| 9 | Transactions list: "Paid / received by" (Anik) | coordinator (backend) + frish-79 (view, filter drawer) | backend done: `payer` #[Url] filter, `$payers` options (only users who handled money in scope), `payer` eager-load, CSV column and `?payer=`; receipts/payments now record `paid_by` (same payerId() rule); PayerFilterTest; 182/182. Filters move to an off-canvas drawer in frish-79's phase-2 table pattern. |
| 6 | Final review, behavior verification, handover | coordinator + agent `verify-final` | done: PASS on snapshot of 4d6396f + fixes. README fresh setup OK (after D1 fix: `composer setup` now creates the SQLite file); `composer check` 181/181; 10-step customer journey (2,755 assertions) incl. drawer companies, partial payment → overdue → settle → paid, void rules, reports vs hand-computed figures, isolation (13 pages, CSV, crafted ids/session/Livewire), roles; ledger invariants after journey and demo seed; HTTP smoke 110 requests in All and single-company mode, no errors. Not covered: browser/JS behaviour, concurrency on MySQL, file uploads, cPanel deploy. Later work by parallel sessions (UI phase 2) is outside this verification. |

## Acceptance criteria

1. An owner creates 4 companies; each gets the default chart of accounts.
2. An accountant assigned to company A cannot see, list, export, report on or post to company B,
   whether through the UI or a crafted Livewire request.
3. Recording income or expense posts a balanced double entry, and cash/bank balances update.
4. Entries can be filtered (company, date range, type, account, employee, search) and exported to CSV.
5. Editing keeps the entry balanced and records `updated_by`. Voiding requires a reason, keeps the
   row visible and excludes it from every total.
6. The income statement works for one company or all visible companies combined (per-company and
   combined totals) for any date range. The trial balance always balances.
7. Employees can be managed per company and linked to expense entries; the cost report shows spend
   per employee.
8. `composer check` (pint + tests) and `npm run build` pass. A fresh clone can be set up with
   the documented commands.
