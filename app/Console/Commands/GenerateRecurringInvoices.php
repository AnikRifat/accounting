<?php

namespace App\Console\Commands;

use App\Services\RecurringInvoices;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Run daily by the scheduler (cPanel cron `php artisan schedule:run`): turns due recurring schedules into draft invoices. */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'sales:generate-recurring';

    protected $description = 'Create the draft invoices of every recurring schedule that is due today';

    public function handle(RecurringInvoices $recurring): int
    {
        $summary = $recurring->run(CarbonImmutable::today());
        $this->info(trans_choice('{0} No draft invoices were due.|{1} Created :count draft invoice.|[2,*] Created :count draft invoices.', $summary['created'], ['count' => $summary['created']]));
        foreach ($summary['skipped'] as $skipped) {
            $this->warn(__('Skipped :schedule: :reason', $skipped));
        }

        return self::SUCCESS;
    }
}
