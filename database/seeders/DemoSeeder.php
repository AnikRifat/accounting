<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmSource;
use App\Models\CrmStatus;
use App\Models\JournalEntry;
use App\Models\Lead;
use App\Models\Party;
use App\Models\PartyCategory;
use App\Models\User;
use App\Services\CallLogger;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Local demo data: 4 companies with about six months of income and expense bills (paid, plus a few due, partly paid
 * and overdue) and salaries, and three sign-ins sharing the fixed demo password `password`. Transfers, opening
 * balances, receipts and payments are left out for now.
 * Runs only in the local or testing environment and only on empty books (no company and no user),
 * so it can never touch real data or reset a password. Run with `composer demo` or `php artisan migrate:fresh --seed`.
 */
class DemoSeeder extends Seeder
{
    public const OWNER_EMAIL = 'superadmin@gmail.com';

    public const ACCOUNTANT_EMAIL = 'accountant@frish.test';

    public const DATA_ENTRY_EMAIL = 'dataentry@frish.test';

    public const SALES_MANAGER_EMAIL = 'salesmanager@frish.test';

    public const SALES_EMAIL = 'sales@frish.test';

    /** CRM demo: services per company code, and lead names to draw from. */
    private const CRM_SERVICES = ['PAS' => ['Knitwear sourcing', 'Woven sourcing', 'Quality inspection'], 'JSL' => ['ERP software', 'Website development', 'Mobile app']];

    private const LEAD_NAMES = ['Arif Hossain', 'Sabina Yasmin', 'Kamrul Hasan', 'Tahmina Akter', 'Jubayer Ahmed', 'Shirin Sultana', 'Masud Rana',
        'Nazmul Huda', 'Farzana Rahman', 'Rakib Hasan', 'Mahmuda Khatun', 'Shakil Ahmed', 'Rokeya Begum', 'Towhid Islam', 'Lipi Akter'];

    /** Months of history, including the current month up to today. */
    private const MONTHS = 6;

    /**
     * Each company: identity, extra payment methods [name, type, details], extra categories [type, name], the sales
     * and purchase categories, customers and suppliers [name, phone], landlord, monthly sales and purchases
     * [count min, count max, taka min, taka max], rent in taka, and staff [name, designation, department, salary in taka].
     *
     * @var list<array<string, mixed>>
     */
    private const COMPANIES = [
        ['code' => 'MTL', 'name' => 'Meghna Traders Ltd.', 'address' => '42 Motijheel C/A, Dhaka 1000', 'phone' => '02-9551234',
            'methods' => [['Dutch-Bangla Bank', PaymentType::Bank, 'A/C 101-110-0045678'], ['Sonali Bank', PaymentType::Bank, 'A/C 0002-3340-1122']],
            'categories' => [[AccountType::Expense, 'Goods Purchase'], [AccountType::Expense, 'Marketing & Promotion']],
            'sales' => 'Sales & Service Income', 'purchases' => 'Goods Purchase',
            'customers' => [['Rahman Traders, Chawkbazar', '01711-203344'], ['Bismillah General Store', '01819-556677'], ['Karim Brothers Wholesale', '01552-908172']],
            'suppliers' => [['Chattogram Commodities Ltd.', '01730-114455'], ['Noor Rice Mills', '01914-660022']],
            'landlord' => ['Alhaj Mofizur Rahman', '01711-889900'],
            'sale' => [4, 6, 1_20_000, 4_50_000], 'purchase' => [2, 3, 60_000, 2_50_000], 'rent' => 85_000,
            'staff' => [['Abdul Karim', 'General Manager', 'Management', 95_000], ['Nasrin Akter', 'Accounts Officer', 'Accounts', 42_000],
                ['Mizanur Rahman', 'Sales Executive', 'Sales', 35_000], ['Shahidul Islam', 'Store Keeper', 'Warehouse', 22_000],
                ['Rubel Hossain', 'Delivery Driver', 'Logistics', 18_000]]],
        ['code' => 'PAS', 'name' => 'Padma Apparel Sourcing', 'address' => 'House 12, Road 7, Sector 4, Uttara, Dhaka 1230', 'phone' => '02-8931456',
            'methods' => [['Eastern Bank', PaymentType::Bank, 'A/C 1041-060-123456']],
            'categories' => [[AccountType::Income, 'Buying Commission'], [AccountType::Expense, 'Sample & Courier Charges'], [AccountType::Expense, 'Lab Testing Fees']],
            'sales' => 'Buying Commission', 'purchases' => 'Sample & Courier Charges',
            'customers' => [['Hanse Textil GmbH', '+49 40 5550123'], ['Maple Apparel Inc.', '+1 416 5550199'], ['Gazipur Knitwear Ltd.', '01713-445566']],
            'suppliers' => [['Uttara Courier Services', '01715-221133'], ['Textile Lab Testing BD', '01819-330044']],
            'landlord' => ['Mrs. Shahana Parvin', '01711-667788'],
            'sale' => [3, 5, 2_00_000, 6_00_000], 'purchase' => [2, 3, 8_000, 40_000], 'rent' => 1_20_000,
            'staff' => [['Farhana Yasmin', 'Merchandising Manager', 'Merchandising', 1_10_000], ['Tanvir Ahmed', 'Senior Merchandiser', 'Merchandising', 65_000],
                ['Sadia Islam', 'QA Inspector', 'Quality', 45_000], ['Jahangir Alam', 'Office Assistant', 'Administration', 16_000]]],
        ['code' => 'JSL', 'name' => 'Jamuna Soft Ltd.', 'address' => 'Level 6, 17 Kemal Ataturk Avenue, Banani, Dhaka 1213', 'phone' => '02-9887766',
            'methods' => [['BRAC Bank', PaymentType::Bank, 'A/C 1501-2040-567801'], ['Nagad', PaymentType::MobileBanking, '01819-445566']],
            'categories' => [[AccountType::Income, 'Software Maintenance Fees'], [AccountType::Expense, 'Cloud & Software Subscriptions']],
            'sales' => 'Sales & Service Income', 'purchases' => 'Cloud & Software Subscriptions',
            'customers' => [['Sonar Bangla Pharma Ltd.', '01730-778899'], ['Dhaka Logistics Hub', '01911-202030'], ['Padma Microfinance Foundation', '01552-121314']],
            'suppliers' => [['Cloudline Hosting BD', '01716-909090'], ['Techno Hardware Point', '01819-707070']],
            'landlord' => ['Banani Tower Management', '01713-505050'],
            'sale' => [3, 5, 1_50_000, 4_00_000], 'purchase' => [1, 2, 15_000, 90_000], 'rent' => 1_50_000,
            'staff' => [['Imran Hossain', 'Chief Technology Officer', 'Engineering', 1_50_000], ['Sharmin Sultana', 'Senior Software Engineer', 'Engineering', 1_05_000],
                ['Rafiqul Islam', 'Software Engineer', 'Engineering', 70_000], ['Nusrat Jahan', 'UI/UX Designer', 'Design', 60_000]]],
        ['code' => 'SKR', 'name' => 'Shapla Kitchen & Restaurant', 'address' => 'House 27, Road 11A, Dhanmondi, Dhaka 1209', 'phone' => '02-9123344',
            'methods' => [['Islami Bank Bangladesh', PaymentType::Bank, 'A/C 2050-1234-5678'], ['Nagad', PaymentType::MobileBanking, '01713-778899']],
            'categories' => [[AccountType::Income, 'Catering Income'], [AccountType::Expense, 'Food & Beverage Purchases'], [AccountType::Expense, 'Gas & Fuel']],
            'sales' => 'Catering Income', 'purchases' => 'Food & Beverage Purchases',
            'customers' => [['Uttara Club Catering Desk', '01711-343434'], ['Rahima Event Management', '01819-565656']],
            'suppliers' => [['Kawran Bazar Fresh Suppliers', '01552-787878'], ['Meghna Poultry Farm', '01914-898989'], ['Rupsha Fish Traders', '01716-676767']],
            'landlord' => ['Dhanmondi Properties Ltd.', '01713-454545'],
            'sale' => [2, 3, 40_000, 1_50_000], 'purchase' => [4, 6, 20_000, 80_000], 'rent' => 1_10_000,
            'staff' => [['Kamal Uddin', 'Restaurant Manager', 'Operations', 55_000], ['Shafiqul Alam', 'Head Chef', 'Kitchen', 48_000],
                ['Monir Hossain', 'Cook', 'Kitchen', 24_000], ['Ayesha Begum', 'Cashier', 'Front of house', 20_000],
                ['Sumon Mia', 'Waiter', 'Front of house', 14_000]]],
    ];

    private LedgerService $ledger;

    private User $owner;

    private CarbonImmutable $today;

    /**
     * Running balance per payment method, keyed by company id then method id. With no opening balances or transfers,
     * expenses are paid from money that income brought in.
     *
     * @var array<int, array<int, int>>
     */
    private array $funds = [];

    /** The one demo password every seeded account signs in with. */
    private string $password;

    /** @throws RuntimeException when not local/testing or when the books are not empty; nothing is written then */
    public function run(LedgerService $ledger): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder runs only in the local or testing environment. Nothing was changed.');
        }
        if (Company::query()->exists() || User::query()->exists()) {
            throw new RuntimeException('DemoSeeder needs empty books, but companies or users already exist. Nothing was changed and no password was reset.');
        }
        $this->ledger = $ledger;
        $this->today = CarbonImmutable::today();
        $password = 'password';
        $summary = DB::transaction(fn (): array => $this->seedDemo($password));

        $this->command?->info("Demo data created: {$summary['companies']} companies, {$summary['parties']} parties, {$summary['entries']} entries "
            ."(2 voided), {$summary['open']} open bills ({$summary['overdue']} overdue).");
        $this->command?->line('Sign in at /admin/login with the demo password (local only):');
        $this->command?->line('  Owner       '.self::OWNER_EMAIL);
        $this->command?->line('  Accountant  '.self::ACCOUNTANT_EMAIL.' (Meghna Traders Ltd., Jamuna Soft Ltd.)');
        $this->command?->line('  Data entry  '.self::DATA_ENTRY_EMAIL.' (Shapla Kitchen & Restaurant)');
        $this->command?->line('  Sales mgr   '.self::SALES_MANAGER_EMAIL.' (CRM: Padma Apparel Sourcing, Jamuna Soft Ltd.)');
        $this->command?->line('  Sales       '.self::SALES_EMAIL.' (CRM: Padma Apparel Sourcing, own leads only)');
        $this->command?->line('  Password    '.$password);
    }

    /** @return array{companies: int, parties: int, entries: int, open: int, overdue: int} */
    private function seedDemo(string $password): array
    {
        $this->password = $password;
        $this->owner = $this->user('Super Admin', self::OWNER_EMAIL, 'owner', $password);
        $start = $this->today->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);
        $companies = [];
        foreach (self::COMPANIES as $definition) {
            $companies[$definition['code']] = $this->company($definition, $start);
        }
        $this->voidSamples($companies['MTL'], $companies['JSL']);

        $this->user('Rafia Chowdhury', self::ACCOUNTANT_EMAIL, 'accountant', $password, [$companies['MTL']->id, $companies['JSL']->id]);
        $this->user('Habib Rahman', self::DATA_ENTRY_EMAIL, 'data-entry', $password, [$companies['SKR']->id]);
        $salesManager = $this->user('Mim Chowdhury', self::SALES_MANAGER_EMAIL, 'sales-manager', $password, [$companies['PAS']->id, $companies['JSL']->id]);
        $sales = $this->user('Santa Akter', self::SALES_EMAIL, 'sales', $password, [$companies['PAS']->id]);
        $this->crm($companies['PAS'], [$salesManager, $sales]);
        $this->crm($companies['JSL'], [$salesManager]);

        $ids = array_map(fn (Company $company): int => $company->id, $companies);
        $today = $this->today->toDateString();

        return ['companies' => count($companies), 'parties' => Party::query()->whereIn('company_id', $ids)->count(),
            'entries' => JournalEntry::query()->whereIn('company_id', $ids)->count(),
            'open' => JournalEntry::query()->whereIn('company_id', $ids)->open()->count(),
            'overdue' => JournalEntry::query()->whereIn('company_id', $ids)->open()->where('due_date', '<', $today)->count()];
    }

    /**
     * Every user but the super admin is an employee with a party in each assigned company.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $staff  employee details (code, designation, department, phone, salary, joining date)
     */
    private function user(string $name, string $email, string $role, string $password, array $companyIds = [], array $staff = []): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => $role, 'is_active' => true, 'email_verified_at' => now(), ...$staff])->save();
        $user->companies()->attach($companyIds);
        $user->syncParties();

        return $user;
    }

    /**
     * About 30 leads with services, statuses, follow-ups (some overdue, some today) and a call history logged
     * through CallLogger, so each lead's status and next call match its latest call.
     *
     * @param  list<User>  $people  who the leads are assigned to and who calls them
     */
    private function crm(Company $company, array $people): void
    {
        $random = new Randomizer(new Mt19937(crc32('crm-'.$company->code)));
        $pick = fn (array $items): mixed => $items[$random->getInt(0, count($items) - 1)];
        $sources = CrmSource::query()->where('company_id', $company->id)->pluck('id')->all();
        $services = array_map(fn (string $name): int => CrmService::create(['company_id' => $company->id, 'name' => $name])->id, self::CRM_SERVICES[$company->code]);
        $statuses = CrmStatus::query()->where('company_id', $company->id)->get();
        $leadStatuses = $statuses->where('type.value', 'lead')->values();
        $callStatuses = $statuses->where('type.value', 'call')->pluck('id')->all();
        $logger = app(CallLogger::class);
        for ($number = 0; $number < 30; $number++) {
            $created = $this->today->subDays($random->getInt(0, 40));
            $lead = Lead::create(['company_id' => $company->id, 'name' => $random->getInt(0, 5) === 0 ? null : $pick(self::LEAD_NAMES),
                'phone' => sprintf('01%d%08d', $random->getInt(3, 9), $number * 7919 + $random->getInt(0, 999)), 'crm_service_id' => $pick($services),
                'crm_status_id' => $leadStatuses->first()->id, 'assigned_to' => $pick($people)->id, 'created_by' => $this->owner->id,
                'crm_source_id' => $random->getInt(0, 4) === 0 ? null : $pick($sources), 'next_call_on' => $created->addDays($random->getInt(0, 3))->toDateString()]);
            $lead->forceFill(['created_at' => $created, 'updated_at' => $created])->save();
            $calledAt = $created;
            for ($calls = $random->getInt(0, 3); $calls > 0; $calls--) {
                $calledAt = $calledAt->addDays($random->getInt(1, 6))->setTime($random->getInt(10, 18), $random->getInt(0, 59));
                if ($calledAt->isAfter(now())) {
                    break;
                }
                $status = $pick($leadStatuses->slice(1)->values()->all());
                $logger->record($lead, ['type' => $random->getInt(0, 6) === 0 ? 'visit' : 'call', 'called_at' => $calledAt->toDateTimeString(),
                    'call_status_id' => $pick($callStatuses), 'lead_status_id' => $status->id,
                    'summary' => $pick(['Asked for a price list', 'Demo sent, will decide next week', 'No response, try again', 'Busy, call back later', 'Wants a meeting at the office']),
                    'next_call_on' => $this->today->addDays($random->getInt(-6, 12))->toDateString()], $lead->assignee);
            }
        }
    }

    /** @param array<string, mixed> $definition one row of self::COMPANIES */
    private function company(array $definition, CarbonImmutable $start): Company
    {
        $random = new Randomizer(new Mt19937(crc32($definition['code'])));
        $company = Company::create(['name' => $definition['name'], 'code' => $definition['code'], 'address' => $definition['address'],
            'phone' => $definition['phone'], 'is_active' => true]);
        foreach ($definition['methods'] as [$name, $paymentType, $details]) {
            $company->accounts()->forceCreate(['code' => $this->ledger->nextCode($company, LedgerService::PAYMENT_METHOD), 'name' => $name,
                'type' => AccountType::Asset, 'is_cash' => true, 'payment_type' => $paymentType, 'details' => $details, 'is_system' => false, 'is_active' => true]);
        }
        foreach ($definition['categories'] as [$type, $name]) {
            $company->accounts()->forceCreate(['code' => $this->ledger->nextCode($company, $type), 'name' => $name, 'type' => $type,
                'is_cash' => false, 'is_system' => false, 'is_active' => true]);
        }
        $account = $company->accounts()->pluck('id', 'name')->map(fn (mixed $id): int => (int) $id);
        $methods = Account::query()->where('company_id', $company->id)->paymentMethods()->orderBy('code')->get()->groupBy(fn (Account $method): string => $method->payment_type->value);
        $cash = $methods['cash']->first()->id;
        $banks = $methods['bank']->pluck('id')->all();
        $wallets = $methods['mobile_banking']->pluck('id')->all();
        $this->funds[$company->id] = array_fill_keys([$cash, ...$banks, ...$wallets], 0);

        $categories = collect(['Customer', 'Supplier', 'Landlord'])->mapWithKeys(fn (string $name): array => [$name => PartyCategory::create(['company_id' => $company->id, 'name' => $name])->id]);
        $party = fn (array $row, string $notes): int => Party::create(['company_id' => $company->id, 'party_category_id' => $categories[$notes],
            'name' => $row[0], 'phone' => $row[1], 'notes' => $notes, 'is_active' => true])->id;
        $customers = array_map(fn (array $row): int => $party($row, 'Customer'), $definition['customers']);
        $suppliers = array_map(fn (array $row): int => $party($row, 'Supplier'), $definition['suppliers']);
        $landlord = $party($definition['landlord'], 'Landlord');
        $staff = [];
        foreach ($definition['staff'] as $index => [$name, $designation, $department, $salary]) {
            $employee = $this->user($name, Str::slug($name, '.').'@frish.test', 'data-entry', $this->password, [$company->id], [
                'employee_code' => sprintf('%s-%03d', $company->code, $index + 1), 'designation' => $designation, 'department' => $department,
                'phone' => sprintf('01711-%06d', $random->getInt(100000, 999999)), 'monthly_salary' => $salary * 100,
                'joined_on' => $start->subMonths($random->getInt(3, 48))->toDateString()]);
            $staff[] = [(int) $employee->parties()->value('id'), $salary];
        }

        $pick = fn (array $items): int => $items[$random->getInt(0, count($items) - 1)];
        $receiveInto = $definition['code'] === 'SKR' ? [$cash, $cash, ...$wallets, $banks[0]] : [$cash, ...$banks, ...$wallets];
        $sale = 0;
        for ($month = $start; $month <= $this->today; $month = $month->addMonthNoOverflow()) {
            $day = fn (int $number): CarbonImmutable => $month->setDate($month->year, $month->month, $number);
            $monthName = $month->format('F Y');
            for ($count = $random->getInt($definition['sale'][0], $definition['sale'][1]); $count > 0; $count--) {
                $sale++;
                $this->bill($company, EntryType::Income, $day($random->getInt(1, 27)), $random->getInt($definition['sale'][2], $definition['sale'][3]) * 100,
                    $account[$definition['sales']], $pick($receiveInto), $pick($customers), 'Invoice for goods and services', reference: sprintf('INV-%s-%04d', $definition['code'], $sale));
            }
            if ($definition['code'] === 'SKR') {
                foreach ([7, 14, 21, 27] as $number) {
                    $this->bill($company, EntryType::Income, $day($number), $random->getInt(1_20_000, 2_20_000) * 100, $account['Sales & Service Income'], $pick([$cash, $cash, ...$wallets]), null, 'Weekly dine-in and takeaway sales');
                }
            }
            $this->bill($company, EntryType::Expense, $day(5), $definition['rent'] * 100, $account['Office Rent'], $banks[0], $landlord, "Office rent for {$monthName}");
            $this->bill($company, EntryType::Expense, $day(12), $random->getInt(8, 25) * 1_000_00, $account['Utilities'], $pick([$banks[0], ...$wallets]), null, 'Electricity, gas and internet bills');
            for ($count = $random->getInt($definition['purchase'][0], $definition['purchase'][1]); $count > 0; $count--) {
                $this->bill($company, EntryType::Expense, $day($random->getInt(1, 27)), $random->getInt($definition['purchase'][2], $definition['purchase'][3]) * 100,
                    $account[$definition['purchases']], $pick([...$banks, ...$wallets]), $pick($suppliers), $definition['purchases']);
            }
            for ($count = $random->getInt(2, 4); $count > 0; $count--) {
                $this->bill($company, EntryType::Expense, $day($random->getInt(1, 27)), $random->getInt(3, 25) * 100_00, $account['Transport & Conveyance'], $cash, null, 'CNG and rickshaw fares');
            }
            $this->bill($company, EntryType::Expense, $day($random->getInt(1, 27)), $random->getInt(15, 60) * 100_00, $account['Office Supplies'], $cash, null, 'Stationery and printer toner');
            foreach ($staff as [$partyId, $salary]) {
                $this->bill($company, EntryType::Expense, $day(28), $salary * 100, $account['Salaries & Wages'], $banks[0], $partyId, "Salary for {$monthName}");
            }
        }
        $this->dueSamples($company, $start, $account[$definition['sales']], $account[$definition['purchases']], $customers[0], $suppliers[0], $banks[0]);

        return $company;
    }

    /**
     * A fully paid bill; skipped (null) when dated after today. An expense the given method can't cover is paid from
     * the company's best-funded method instead.
     */
    private function bill(Company $company, EntryType $type, CarbonImmutable $date, int $amount, int $categoryId, int $methodId, ?int $partyId, string $description, ?int $paid = null, ?CarbonImmutable $due = null, ?string $reference = null): ?JournalEntry
    {
        if ($date->greaterThan($this->today)) {
            return null;
        }
        $paid ??= $amount;
        $funds = &$this->funds[$company->id];
        if ($type === EntryType::Expense && $funds[$methodId] < $paid) {
            $methodId = array_search(max($funds), $funds, true);
        }
        $funds[$methodId] += $type === EntryType::Income ? $paid : -$paid;

        return $this->ledger->record($company, $type, ['entry_date' => $date->toDateString(), 'amount' => $amount,
            'payments' => $paid > 0 ? [['account_id' => $methodId, 'amount' => $paid]] : [], 'category_account_id' => $categoryId, 'party_id' => $partyId,
            'due_date' => $paid < $amount ? $due?->toDateString() : null, 'description' => $description, 'reference' => $reference], $this->owner);
    }

    /** One open bill in each due status, so every screen has something to show: due, partly paid and overdue. */
    private function dueSamples(Company $company, CarbonImmutable $start, int $salesId, int $purchasesId, int $customerId, int $supplierId, int $bankId): void
    {
        $this->bill($company, EntryType::Income, $this->today->subDays(3), 75_000_00, $salesId, $bankId, $customerId, 'Invoice on 30-day credit', 0, $this->today->addDays(27));
        $this->bill($company, EntryType::Expense, $this->today->subDays(6), 50_000_00, $purchasesId, $bankId, $supplierId, 'Supplier bill, 40% paid on delivery', 20_000_00, $this->today->addDays(14));
        $this->bill($company, EntryType::Income, $start->addDays(40), 1_20_000_00, $salesId, $bankId, $customerId, 'Invoice on 30-day credit, one third paid', 40_000_00, $start->addDays(70));
    }

    /** Posts two mistaken entries and voids them with a reason, as staff would. */
    private function voidSamples(Company $trading, Company $software): void
    {
        $date = $this->today->subDays(2);
        $category = fn (Company $company, string $name): int => (int) $company->accounts()->where('name', $name)->value('id');
        $bank = fn (Company $company): int => (int) $company->accounts()->where('name', 'Bank Account')->value('id');
        $customer = (int) Party::query()->where('company_id', $trading->id)->whereNull('user_id')->orderBy('id')->value('id');

        $duplicate = $this->bill($trading, EntryType::Income, $date, 2_50_000_00, $category($trading, 'Sales & Service Income'), $bank($trading), $customer, 'Invoice for goods and services', reference: 'INV-MTL-DUP');
        $this->ledger->void($duplicate, 'Duplicate entry; the payment was already recorded.', $this->owner);

        $wrongAmount = $this->bill($software, EntryType::Expense, $date, 52_000_00, $category($software, 'Utilities'), $bank($software), null, 'Electricity, gas and internet bills');
        $this->ledger->void($wrongAmount, 'Wrong amount; re-entered as 5,200.', $this->owner);
        $this->bill($software, EntryType::Expense, $date, 5_200_00, $category($software, 'Utilities'), $bank($software), null, 'Electricity, gas and internet bills');
    }
}
