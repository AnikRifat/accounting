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
- Entries can be edited (`entries.update`), voided with a reason (`entries.void`; stays visible,
  excluded from figures) or moved to Trash (`entries.delete`; hidden, restorable). Permanent delete
  needs `entries.purge`. See the increment 10 contract (superseded the original "never deleted" rule
  at Anik's request).
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

| Type | Lines (T = total, P = paid now, the sum of up to 10 payment-method shares, 0 ≤ P ≤ T, D = T − P) |
| --- | --- |
| Income | Cr category T; Dr each payment method its share of P; Dr Accounts Receivable D (if D > 0) |
| Expense | Dr category T; Cr each payment method its share of P; Cr Accounts Payable D (if D > 0) |
| Receipt (bill = income entry) | Dr payment method X; Cr Accounts Receivable X |
| Payment (bill = expense entry) | Dr Accounts Payable X; Cr payment method X |
| Transfer, Opening | unchanged |

Rules:
- **When a due exists (D > 0):** party and due date are required; the due date must be on or
  after the entry date.
- **Payment methods:** each share of P needs one, used once per entry, with an amount > 0; each
  must be an active payment method of the same company. Category and party must also belong to the same company.
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
  the user has parties.create), total amount, paid now as payment-method rows (one Cash row
  that follows the total until edited; "add another payment method" prefills what is still unpaid),
  due date (shown only when paid < total), reference, description.
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

## Increment 10 contract: deleting (Anik, 2026-09-24)

Decisions:
- **Transactions:** soft delete into a Trash page, with restore and permanent delete.
- **Categories, parties and payment methods already used in transactions:** a delete dialog offers
  to **transfer** their transactions to another record. If you don't transfer, a warning explains
  that **hard delete** removes everything related.
- **Unused records:** simply deleted.
- **Companies:** only hard delete of their whole books, confirmed by typing the company code
  (coordinator decision: moving entries between companies' books is not meaningful).

Permissions (already in `config/permissions.php`):
- New abilities: `entries.delete` (move to Trash), `entries.purge` (restore from Trash is
  `entries.delete`; permanent delete, hard delete of related entries, and company delete with books
  all need `entries.purge`), `accounts.delete` (categories, payment methods, chart accounts),
  `parties.delete`, `companies.delete`.
- Defaults: administrator has `*`; the super admin passes every check; accountant has `entries.delete`
  (not purge), `accounts.*` and `parties.*`; data-entry has none of these.

**A. Trash for transactions (agent `ledger`):**
- **Schema:** a NEW migration adds nullable `deleted_at` and `deleted_by` (FK users, nullOnDelete)
  to `journal_entries`. Migrations are committed now, so don't edit old ones.
- **Model:** JournalEntry uses `SoftDeletes`.
- **Excluded everywhere:** every figure excludes trashed entries. `posted()` must exclude them, and
  every raw or joined query that reads `journal_entries`/`journal_lines` (balances, periodActivity,
  dues, outstanding, settledAmount, reports, dashboard, export, the Account ledger's joins) must
  exclude `deleted_at IS NOT NULL`. Audit them all with grep; a test must prove each figure ignores
  a trashed entry.
- **`LedgerService::delete(JournalEntry, User)`** (`entries.delete` + company access): a bill with
  non-trashed settlements (posted or voided) is refused with "Delete the receipts or payments
  first". Trashing a settlement reopens its amount. Voided entries can be trashed too.
- **`LedgerService::restore(JournalEntry, User)`** (`entries.delete`):
  - a settlement can't be restored while its bill is trashed;
  - restoring must not over-settle a bill (outstanding re-check), otherwise it is refused;
  - an inactive company is refused.
- **`LedgerService::purge(JournalEntry, User)`** (`entries.purge`): permanently deletes the entry,
  its lines, and (for a bill) all its settlements (any state). It is the only hard-delete primitive;
  the other agent's master-data hard delete calls it per entry.
- **UI:**
  - a Trash page `admin.entries.trash` (`entries.delete`): scoped to CompanyContext, listing trashed
    entries with who deleted them and when, plus Restore and "Delete permanently" (`entries.purge`,
    confirm step) and "Empty trash" (`entries.purge`, confirm);
  - Transactions list: a `delete(int $id)` action (with confirmation) that the row button will call.
- Tests must cover all of this, including isolation and roles.

**B. Master-data deletion (agent `org`):**
- **`App\Services\RecordDeletion`**, with methods to:
  - count usage;
  - `transfer(Account|Party $from, Account|Party $to, User)`;
  - `hardDelete(Account|Party|Company, User)`;
  - `deleteUnused(...)`.
- **Transfer rules:**
  - same company, same kind, target active and different;
  - category → same type (income/expense), reassigning its `journal_lines.account_id`;
  - party → any active non-deleted party of the company, reassigning `journal_entries.party_id`;
  - payment method → another payment method, reassigning lines. Refused while transfers exist
    between the two methods (they would become self-transfers); the dialog explains this.
  - After a transfer the record is deleted. It all runs in one transaction and re-asserts that the
    affected entries balance.
- **Hard delete:** `LedgerService::purge()` on every entry that has a line on the account (or the
  party), then deletes the record. Needs `entries.purge` + `accounts.delete`/`parties.delete`.
- **Blocked:**
  - system accounts (Receivable, Payable, Opening Balance Equity);
  - employee parties (these go through the user);
  - an account that is a company's last payment method.
- **Company:** needs `companies.delete` (+ `entries.purge` if it has entries). It is confirmed by
  typing the code, and deletes entries (purge), parties, accounts, the pivot and the company. The
  header context falls back to All.
- **UI:** one self-contained `App\Livewire\DeleteRecordDrawer` placed in the layout once (like
  CreateCompanyDrawer), opened with the browser event `open-delete` carrying `{kind:
  'category'|'payment-method'|'account'|'party'|'company', id}`. It shows the usage count and, when
  used, a transfer target select (searchable) plus a hard-delete option with a red warning listing
  what will be erased (N transactions, amounts). The hard delete is typed-confirmation for companies.
  After success it reloads the current page with a flash.
- Tests cover all of this, including isolation (a crafted id or kind of another company returns 404)
  and roles.

**C. Row buttons (frish-79 cleared the coordinator to add them to its list views after A and B land, using its pattern: `<x-button variant="ghost" size="sm" icon="trash" class="text-danger" …>` inside `<div class="row-actions">`, and the Trash button `<x-button variant="secondary" icon="trash" :href="route('admin.entries.trash')">` in the Transactions page-header actions):**
- Add a Delete button per row: it dispatches `open-delete` for masters, and calls
  `$wire.delete(id)` on the Transactions list.
- Add a "Trash" link on the Transactions page.
- Everything is gated with `@can` on the matching ability.

## Increment 11: deleting employees (Anik, 2026-09-25)

- New ability `users.delete` (Employees group). Administrator has it through `*`; other roles don't
  by default.
- The Employees list gets a Delete row button that opens `DeleteRecordDrawer` with `kind: 'user'`.
  The employee is loaded through `ManageableUsers`, never the actor themself or the super admin.
- An employee can be deleted only while no transaction (trashed included) names them as
  `created_by`, `updated_by`, `voided_by` or `paid_by`, or through one of their employee parties.
  Otherwise the drawer explains that the employee should be deactivated instead, so the audit trail
  keeps their name. There is no transfer or hard delete for employees.
- Deleting removes the user's employee parties, company assignments, sessions, API tokens and
  attached files. `RecordDeletion::deleteUnused()` handles it.

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

## Increment 12 contract: CRM module (Anik, 2026-09-28)

Request: a module switcher at the top right of the header ("Accounting | CRM"), with a CRM built on the
same company concept. The screens Anik supplied were the reference: CRM dashboard, CR dashboard, leads,
call log, services, statuses, user management and reports.

Decisions (coordinator, reversible):
- **Modules.** `App\Support\Modules` + `RememberModule` middleware. The sidebar shows the current
  module's sections. The switcher shows only the modules a user can open: Accounting needs
  `dashboard.view`, CRM needs `crm.view`, and Organisation needs any of companies, employees, roles,
  media or settings.
- **Organisation module (Anik, 2026-09-29).** A third module holds Organisation (Companies,
  Employees) and Administration (Roles & permissions, Media library, Settings). Parties stay in
  Accounting, because transactions use them. Profile, the company chooser and print pages keep the
  last module.
- **Company rules are unchanged.** Header scope, `company.selected` on create routes, `#[Locked]` company
  ids re-checked on save, a Company column in All mode. Inactive companies accept no new leads or calls.
- **Schema.** `crm_services` (unique name per company), `crm_statuses` (type `lead|call`, tone,
  `is_closed`, position; the defaults come from `Crm::DEFAULT_STATUSES` for every company, including
  existing ones through the migration), `leads` (phone normalised, unique per company; next_call_on
  date), and `lead_calls` (type `call|visit`, called_at, call result, lead status after the call,
  next call). Services and statuses cascade with their company. Leads and calls are deleted explicitly
  by `RecordDeletion::deleteCompany`.
- **Follow-ups are derived** from `leads.next_call_on`: today, overdue or upcoming, and never for a closed
  status. `CallLogger` applies only the latest call to the lead. A back-dated call changes nothing.
- **Access.** Permissions `crm.view`, `crm.leads.all|create|update|delete|import`,
  `crm.calls.create|update|delete`, `crm.setup.manage`, `crm.reports.view`. New system roles:
  `sales` (own leads, log and edit own calls) and `sales-manager` (`crm.*` + `users.view`). Without
  `crm.leads.all`, a user sees and edits only the leads assigned to them and their own calls, and
  can't reassign leads.
- **Employees.** An employee who logged calls can only be deactivated, never deleted. Deleting
  unassigns their leads.
- **Reports.** The Leads and Call log lists are the lead and call reports (filters plus Excel export
  and print). Team performance shows, per person, leads by current status, overdue follow-ups, and
  calls and visits.
- **Import.** CSV/XLSX through OpenSpout (already a dependency), up to 5,000 rows. Only Phone is
  required. Duplicates and invalid phones are skipped and reported.

Follow-ups (Anik, 2026-09-29):
- **Party categories.** `party_categories` per company (`parties.party_category_id`, set to null when
  a category is deleted). Managed on Accounting → Party categories with `parties.update`. Every company
  has the built-in **Employee** category (`is_system`). `User::syncParties()` keeps it on employee
  parties. It is for tracking only: never edited, deleted or picked for a custom party.
- **CRM Reports section.** A Reports page with Lead pipeline (service × status), Lead sources
  (contacted, open, each closed status), Call outcomes (person × call result, visits, leads
  contacted) and Team performance, plus links to the Lead list and Call log. They share
  `WithCrmReportFilters` (date range, person). All need `crm.reports.view`.
- **Lead sources are a managed list.** `crm_sources` per company, with defaults Facebook, Website,
  Referral, Walk-in and Phone call. A used source can only be deactivated. The migration moved the
  typed `leads.source` text into the list (matching names, ignoring case) and dropped the column.
  Import matches source names and falls back to the chosen default.
- **Profile photos (optional)** for leads, parties and employees. `App\Concerns\HasPhoto` stores them
  as media in the `photo` collection. The `WithPhotoUpload` form trait uses a square crop, accepts
  JPG/PNG/WebP up to 4 MB, and can replace or remove the photo. `x-avatar` shows the photo or the
  initials. An employee's party shows the employee's photo. Employees can set their own on Profile.
  Deleting a lead, a party or a company removes the photo files after commit.

Not included: a lead→party conversion into accounting, SMS/WhatsApp, a lead card view, bulk
reassignment, and the global header search from the reference screens.

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
| 10 | Deleting (Anik): transaction Trash (soft delete, restore, purge) + delete dialog for categories, payment methods, chart accounts, parties, companies with transfer-or-hard-delete | agents `ledger` (10A), `org` (10B), coordinator (buttons, purge file cleanup after commit) | done: 211/211, Pint clean, build OK. Every figure proven to ignore trashed entries; nextNumber reads trashed rows (no number reuse on trash); purge deletes attachments only after the outermost commit. Known limits: a purged newest entry's number can be reissued; Empty trash purges entry by entry. Local DBs need `php artisan migrate` (new migration 2026_09_24_190000). |
| 12 | CRM module + header module switcher (Anik) | coordinator | done: 30 new tests (258 total green), Pint clean, build OK; HTTP smoke on a demo SQLite copy, 143 requests across owner, sales manager, sales rep and accountant, in All and each company mode, no errors. Local DBs need `php artisan migrate`. |
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

## Increment 13 plan: Sales module — invoicing, business documents, templates, reports (proposed 2026-09-29)

Status: **approved 2026-09-29** (D1–D4 as recommended: mPDF, VAT posted to VAT Payable, cron creating drafts, section builder). Requested by Anik: invoicing (create,
recurring, tax/VAT, discounts, partial/full payments, numbering, PDF, email/WhatsApp), business
documents (quotation, estimate, purchase order, delivery note, receipt, credit/debit note, proforma,
contract), document templates (branding, logo/colours, multiple templates, custom fields, builder)
and reports.

### Goal

A company can quote, invoice and get paid for what it sells, with every issued invoice in the books,
and send professional branded documents to customers and suppliers, without leaving Frish and
without breaking the ledger, company-isolation or money invariants.

### Architecture (coordinator recommendation)

- **New header module "Sales"** (`admin.sales.*`, `App\Support\Modules::SALES`). Same company rules
  as everything else: company from the header, `#[Locked]` company ids, save re-checks the context,
  inactive companies accept no new documents.
- **One `documents` table for every document type**, not a table per type. Columns: id, company_id,
  type (enum `App\Enums\DocumentType`: Invoice, Quotation, Estimate, Proforma, PurchaseOrder,
  DeliveryNote, CreditNote, DebitNote, Contract), number, party_id, template_id?, status (enum),
  issue_date, due_date?, valid_until?, currency fixed BDT, subtotal, discount_total, tax_total, total
  (all BIGINT paisa, recomputed server-side, never trusted from the form), discount (invoice-level,
  amount or basis points), notes?, terms?, body? (contracts), custom_values JSON, source_document_id?
  (conversions: quotation → invoice, PO → expense, invoice → delivery note), journal_entry_id?
  (issued invoice / credit / debit note), recurring_invoice_id?, share_token?, created_by,
  updated_by, voided_at/by/reason, timestamps.
- **`document_lines`**: document_id, item_id?, description, quantity_milli (integer thousandths,
  no floats), unit (≤20), unit_price (paisa), discount (paisa or basis points), tax_rate_bps,
  line_subtotal, line_tax, line_total, income_account_id (category the line posts to), sort.
  Rounding: each line's tax rounds half-up to paisa; document totals are sums of rounded lines.
- **`items`** catalogue per company (name, unit, price, tax_rate_bps, income category, is_active).
  Optional: a line can be free text.
- **Numbering**: `document_sequences` per company per type (prefix + zero-padded sequence, default
  `INV-00001`, `QUO-…`, `PO-…`, `BILL-…`; prefix and padding editable, e.g. `INV-2026-`), taken in
  `DocumentService::issue()` under the company row lock like journal numbers. Drafts get their
  number on issue, so the sequence has no gaps; a prefix change never renumbers issued documents.
- **Bills** (Anik: "invoice / bill"): supplier bills are a document type too. Purchase order →
  bill → debit note mirrors quotation → invoice → credit note.
- **Invoices post through `LedgerService` only**, as `EntryType::Income` bills, so dues, settle(),
  the Dues report, party statement and dashboard keep working unchanged:
  Cr each income category its net (after discounts); Cr **VAT Payable** (new system account 2100)
  the tax; Dr payment methods paid now; Dr Accounts Receivable the rest. `LedgerService` gains one
  method (`recordInvoice` / `updateInvoice`) that builds these lines; the "one-sided, balanced"
  rule is unchanged. Draft, quotation, estimate, proforma, PO, delivery note and contract never post.
- **"Post to accounts" switch (Anik, 2026-09-29), off by default** on invoices and credit/debit notes.
  Off: the document never touches the ledger; payments are recorded on the document
  (`document_payments`: date, amount, payment method account id, reference, created_by), and status
  is derived from them. On: the document posts through `LedgerService` as below. Switching on later
  posts the invoice and replays each recorded document payment through `settle()` with its own date
  and method, in one transaction; switching a posted invoice back off is refused (void instead).
  A credit note follows its invoice: it posts only when the invoice is posted.
- **Payments on posted invoices** are `LedgerService::settle()` against the invoice's entry, multiple
  methods allowed. Status Paid / Partly paid / Overdue is **derived** from outstanding, never stored.
  A **Receipt** is a rendered view of a settlement (numbered by the settlement's entry number), not
  a separate stored document.
- **Credit note** (against a sales invoice): posts Dr income categories, Dr VAT Payable, Cr AR, and
  reduces that invoice's outstanding. **Debit note** (against an expense bill): Dr AP, Cr expense
  category. Outstanding becomes receivable/payable line − settlements − posted notes; the Dues report
  and party statement pick it up through the same derived query. An invoice with posted notes or
  settlements can't be voided (same rule as today).
- **Templates**: `document_templates` per company (name, base layout, colours, font, logo media id,
  section order + visibility JSON, header/footer text, bank details, signature media, VAT/BIN no.),
  one default per document type. Custom field definitions (`document_fields`: company, doc type,
  label, kind text/number/date, required, sort); values in `documents.custom_values`.
- **Rendering**: one Blade view per base layout (Classic, Modern, Compact), used by the screen
  preview, the print page and the PDF, so all three match.
- **Sharing**: email via Laravel Mail sent synchronously (no queue on cPanel), PDF attached, logged
  to `document_activities` (sent, viewed, emailed to, by whom). WhatsApp via a `wa.me/<phone>?text=`
  link carrying a **public share link** (random 40-char token on the document, revocable,
  optional expiry, view + PDF only, no login). No paid WhatsApp API. Parties gain an `email` column.
- **Permissions** (`config/permissions.php`, group Sales): `sales.view`, `sales.create`,
  `sales.update` (edit, issue, accept/decline, convert, recurring), `sales.void`, `sales.delete`
  (drafts only), `sales.payments`, `sales.send` (email, share links), `sales.setup` (items,
  templates, numbering, custom fields), `sales.reports`. Posting to the books additionally needs
  `entries.create` (`entries.update` to re-post). Accountant gets all but `sales.setup`; data-entry
  gets view + create.
- **System accounts** get `accounts.system_key` (receivable, payable, opening_equity, vat_payable):
  the ledger used to find AR/AP as "the system asset/liability", which VAT Payable would break.
- **Protection**: a journal entry that belongs to a document can't be edited, voided or trashed
  from Transactions (the list links to the document instead); parties on documents can't be
  deleted; users who wrote documents can't be deleted; company deletion removes Sales data.
- **Accounting dashboard** monthly income/expense now comes from income/expense account movement
  (was the entry amount), so VAT is left out and credit/debit notes count. Same figures for
  existing data. The Transactions list's income/expense totals still show billed amounts.

### Decisions needed

| # | Decision (all confirmed by Anik 2026-09-29 as recommended) | Recommendation | Why it matters |
| --- | --- | --- | --- |
| D1 | PDF engine (new dependency) | `mpdf/mpdf`: pure PHP, runs on cPanel, renders ৳ and Bengali party names | dompdf is lighter but has no Bengali shaping; browser print only means no PDF email attachment |
| D2 | VAT in the books | Post VAT to a VAT Payable system account; rates per line, exclusive by default (15% BD standard as the default rate), inclusive toggle per document | VAT printed but not posted makes income overstated and gives no VAT report |
| D3 | Recurring invoices trigger | Daily cPanel cron `php artisan schedule:run` + a "Generate due now" button; generated invoices land as **drafts** for review | Auto-issue + auto-email is possible later but posts to the books with nobody looking |
| D4 | Builder scope | Section builder: drag to reorder and show/hide blocks (Livewire `wire:sort`, no new JS dependency), colours, logo, font, custom fields, 3 base layouts | A free-form canvas (drag any element anywhere) is several times larger and needs a JS editor library |

### Increments (ordered; each ends with Pint, its tests and `npm run build` green)

| # | Outcome | Depends on | Acceptance |
| --- | --- | --- | --- |
| 13.1 | Foundation: Sales module + nav, permissions, `parties.email`, items catalogue (CRUD), `DocumentNumbers`, VAT Payable 2100 in the default chart (and added to existing companies by migration) | — | Sales appears in the header for users with `sales.view`; item and number tests cover company isolation and concurrent numbering |
| 13.2 | Invoices: draft → issue → edit → void; lines, line/invoice discounts, VAT; `LedgerService::recordInvoice`/`updateInvoice`; list with filters (status, party, dates), CSV | 13.1, D2 | Issued invoice posts one balanced entry; trial balance still balances; totals recomputed server-side; editing below the settled amount refused; cross-company ids refused on crafted Livewire requests |
| 13.3 | Payments and receipts: receive payment (multi-method) from the invoice, derived status, receipt view/print | 13.2 | Partial then full payment moves status Due → Partly paid → Paid; voiding a receipt reopens it; Dues report matches invoice outstanding |
| 13.4 | Templates and rendering: branding on company/template, 3 base layouts, preview, print, PDF download | 13.2, D1, D4 | Same document renders identically in preview, print and PDF; ৳ and Bengali names render; logo from media |
| 13.5 | Sharing: email with PDF, WhatsApp link, public share link (revocable), activity log | 13.4 | Email logged with recipient; revoked/expired token returns 404; share page exposes no other document or company data |
| 13.6 | Non-posting documents: quotation, estimate, proforma (convert → invoice), purchase order (convert → expense bill via the entry form), delivery note (from invoice), contract (rich text with `{party.name}` style placeholders) | 13.4 | Conversion copies lines and links `source_document_id`; converting twice is refused; none of these touch the ledger |
| 13.7 | Credit and debit notes (ledger change) | 13.3 | Note reduces outstanding; note ≤ remaining outstanding; invoice with notes can't be voided; Dues/party statement include notes |
| 13.8 | Recurring invoices: schedule (weekly/monthly/yearly, start, end, day), `sales:generate-recurring` command, schedule entry, "Generate due now" | 13.2, D3 | Command is idempotent (unique per schedule + period); running twice creates nothing new; missed days catch up |
| 13.9 | Template builder: multiple templates per company, default per type, section reorder/show/hide, custom fields definitions and their inputs on the document form | 13.4, D4 | Reordering persists and shows in preview/PDF; required custom fields validated; a template of company A can't be used by B |
| 13.10 | Reports: sales register, receivables ageing (0–30/31–60/61–90/90+), VAT report (output VAT by period and rate), sales by customer and by item, quotation conversion; Sales dashboard tiles | 13.3, 13.7 | Report totals reconcile with the ledger (VAT report = VAT Payable credits − note debits for the period); all-companies mode consolidates |
| 13.11 | Demo data, MySQL check, README (cron + mail setup for cPanel and Dokploy), final review and behaviour verification | all | `composer check` and `npm run build` green; demo seeds invoices in every state |

### Status (2026-09-29, session frish-fc)

| # | Outcome | Owner | Status |
| --- | --- | --- | --- |
| 13.1–13.3 | Schema, enums, models, DocumentMath, DocumentService, LedgerService::postDocument, VAT Payable + system keys, module/routes/nav/permissions, payments | coordinator | done: DocumentServiceTest 12, DocumentMathTest 6, SalesLedgerIntegrationTest 3 |
| 13.2 UI, dashboard | Documents list/form/show, Sales dashboard, WhatsApp links | agent sales-docs | done: SalesDocumentsTest 18 |
| 13.4, 13.5, 13.9 | Renderer (3 layouts, blocks), print, PDF (mPDF, Bengali via FreeSerif), receipts, public share page, email, template builder | agent sales-render | done: SalesRenderingTest 13 |
| 13.6–13.8, 13.10 | Items, numbering + custom fields, recurring (service, `sales:generate-recurring`, daily 06:00), reports | agent sales-setup | done: SalesSetupTest 8, RecurringInvoicesTest 11, SalesReportsTest 6 |
| 13.11 | Demo data (JSL), README (cron, mail, gd), HTTP smoke (65 URLs × every demo role × every header scope, no 500) | coordinator | done; full suite 354/354, build and Pint clean; local MySQL migrated (accidental partial run repaired, see notes) |

| 13-review | Independent review (money, isolation, sharing) + fixes | agent review + coordinator | done: 13 findings fixed, SalesSafeguardsTest 12 (two fixes mutation-checked); full suite 372/372 |

Review fixes: accounts used by documents can't be hard-deleted (transfer moves document lines, items and
document payments; the transfer balance check allows up to 12 lines); document entries are purged only with
their company; a note can't be issued against a void invoice/bill; "Default category" lines resolve to the
item's or the first category; ageing balances are as of the chosen date; an expired share link is replaced,
not revived; edits that would block later posting are refused; replayed payments keep their recorder;
employees on documents can't be deleted; templates fall back document → type default → company default;
converting an offer/order needs `sales.update`; number-prefix matching is exact.

Notes: a `migrate` on local MySQL at 00:38 ran the sales migration before its enum existed and left two
empty columns; they were dropped and both migrations re-run cleanly (no data touched).

### Risks to watch

- **Money/ledger (flagged)**: 13.2 and 13.7 change posting rules and the outstanding query. They get
  their own review before merge, and a MySQL check of balances and dues.
- **Public share links (flagged, data exposure)**: token-only access, no enumeration, revocable,
  rate-limited route, no internal notes or other documents on the page.
- **Email on cPanel**: synchronous send can be slow or fail; failures are shown and logged, never
  silently dropped. Needs real SMTP credentials in `.env` (not committed).
- **Uncommitted party-category work** in the tree is left alone; 13.1 adds `parties.email` in a new
  migration rather than editing the parties migration.

### Out of scope unless asked

Multi-currency, supplier bills as their own document type (expense entries already cover them),
input-VAT credit and Mushak forms, online card payment links, WhatsApp Business API, free-form
canvas designer.
