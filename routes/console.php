<?php

use App\Support\Configuration;
use App\Support\Modules;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
// Checked hourly so the run hour set in Settings applies without touching the cron job.
Schedule::command('sales:generate-recurring')->hourly()->when(fn (): bool => Modules::enabled(Modules::SALES)
    && Configuration::get('sales.recurring_enabled') && now()->hour === Configuration::get('sales.recurring_hour'));
