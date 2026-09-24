# Frish Accounts

Multi-company income and expense accounting for a Bangladeshi business group, in BDT. One owner
sees all companies and consolidated reports. Accountants and data-entry staff see only the
companies they are assigned to. Simple income, expense and transfer forms post to a double-entry
ledger underneath, so later ERP modules (payroll, inventory, assets) can post to the same books.

Design decisions, data contracts and acceptance criteria: [docs/delivery-plan.md](docs/delivery-plan.md).
Engineering rules for contributors and agents: [AGENTS.md](AGENTS.md).

## What it does

| Area | Features |
| --- | --- |
| Company switcher | One switcher in the header: **All companies** (combined figures) or a single company (only that company's data). No page has its own company filter. Creating a record while on All companies first asks which company. "+ New company" opens a side drawer. |
| Companies | Any number of companies. Each gets its own books from a default template: Cash, Bank and bKash payment methods, income and expense categories, and receivable/payable accounts. Inactive companies take no new records but stay in reports. |
| Transactions | Income and expense with a **category**, a **party** and a **payment method**. You enter the total and the amount paid now, and any unpaid rest gets a due date. Dues are paid off later in any number of receipts or payments. Statuses: Paid, Partly paid, Due, Overdue. Transfers between payment methods. Per-company numbering (`MTL-000123`). Entries are edited, or voided with a reason, never deleted. Filters, and a CSV export for Excel. |
| Parties | Customers, suppliers and people per company. Employees are parties automatically. New parties and categories can be added from the entry form. |
| Categories and payment methods | Managed without account codes, which are assigned automatically. On All companies, the category list is combined and a new category is added to every company. Payment methods (Cash, Bank, bKash/Nagad, card) have opening balances. |
| Reports | Income statement (accrual: dues count in full on the entry date), dues (who owes you and whom you owe, with overdue items), party statement, account ledger, trial balance, employee cost, and the chart of accounts. Bangladesh fiscal-year presets (1 July–30 June). Printable. |
| Dashboard | This month versus last month, receivable/payable/overdue totals and the next dues, cash position per payment method, a six-month income/expense chart, recent transactions. |
| Employees and access | Employees are user accounts that sign in, with staff details (code, designation, department, phone, monthly salary, joining date). A user can belong to several companies and gets a party in each. One super admin (created with `php artisan app:create-admin`) can do everything. The other roles (administrator, accountant, data-entry, custom roles) have editable permissions and per-user denials. User managers can't take over accounts with more power than their own. |

Not included yet: attendance, leave, payroll processing, inter-company transfers, multi-currency,
assets and inventory.

## Requirements

- PHP 8.3+ with `pdo_mysql` (or `pdo_sqlite` locally), `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `curl`
- Composer 2
- Node.js 22+ and npm, only to build the CSS and JS. The app doesn't need Node when it runs.
- MySQL 5.7.7+ / 8.x or MariaDB 10.2.2+ (InnoDB, utf8mb4) in production. SQLite works locally.

## Run locally

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # SQLite default; skip when .env points to MySQL
php artisan migrate
npm ci && npm run build
php artisan app:create-admin   # creates the owner account; asks for the password without echoing it
composer dev                   # http://127.0.0.1:8000/admin
```

The default `.env.example` uses SQLite. To use MySQL locally, set the `DB_*` variables in `.env`.

### Demo data

```sh
php artisan migrate:fresh --seed   # wipes the local database, then DatabaseSeeder runs DemoSeeder
```

This creates 4 companies (Meghna Traders, Padma Apparel Sourcing, Jamuna Soft, Shapla Kitchen)
with about 6 months of transactions: parties, partial payments, open and overdue dues, and later
settlements. It also creates three sign-ins:

- owner `superadmin@gmail.com`
- accountant `accountant@frish.test` (Meghna, Jamuna)
- data-entry user `dataentry@frish.test` (Shapla)

All three sign in with the demo password `password`. The seeder only runs in the `local` or
`testing` environment and only on an empty database (no companies, no users). Otherwise it refuses
with a non-zero exit, so it can never mix demo books into real ones.

> **Warning:** because the demo password is public, never leave a server on `APP_ENV=local`. A
> server on `local` with an empty database that someone runs `migrate --seed` on would get a super
> admin whose password is `password`.

## Checks

```sh
composer check     # Pint formatting + full test suite (in-memory SQLite)
npm run build      # production CSS/JS into public/build
```

## Preparing a cPanel deployment

The target is cPanel shared hosting with MySQL or MariaDB. Nothing needs Node, a queue worker or a
long-running process at runtime.

1. **Build locally** (or in cPanel Terminal if available):
   ```sh
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```
   Upload the project, including `vendor/` and `public/build/`, but not `node_modules/`, `.env` or `database/*.sqlite`.
2. **Document root**: point the domain or subdomain's document root to the project's `public/`
   folder (cPanel → Domains). Never serve the project root, because `.env` would be exposed.
3. **Database**: create a MySQL database and user in cPanel → MySQL Databases, with all privileges on that database only.
4. **Environment**: create `.env` from `.env.example` on the server and set at least:
   ```ini
   APP_NAME="Frish Accounts"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain
   LOG_LEVEL=error
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...
   SESSION_SECURE_COOKIE=true
   QUEUE_CONNECTION=sync
   ```
5. **First-time commands** (cPanel Terminal or SSH):
   ```sh
   php artisan key:generate --force
   php artisan migrate --force
   php artisan app:create-admin
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Re-run the three cache commands after every update, and run `php artisan migrate --force` when new migrations ship.
6. **Cron** (cPanel → Cron Jobs, once a day or every minute): `php /home/USER/path-to-app/artisan schedule:run`.
   It only prunes expired API tokens, so the accounting app works without it.
7. **Backups**: these are the company's books. Turn on cPanel's daily database backups, or JetBackup if the host offers it, and test a restore.

Never run `migrate:fresh` or `db:seed` on the production database. The demo seeder refuses there anyway.

## Known limitations

- The starter's versioned API (`/api/v1`: token login, profile and media) is still enabled for the
  future ERP. The accounting UI doesn't use it. Public self-registration is off by default, and
  Settings has a switch that would turn it on, so leave it off unless the API is actually needed.
- Entry numbering is protected by a row lock plus a unique index. It has been verified on MySQL 9.7
  locally but not under real concurrent load.
- Interface text is English. Every string goes through Laravel's translator, so a Bangla
  translation can be added as `lang/bn.json` without code changes.
