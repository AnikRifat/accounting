<?php

use App\Support\Modules;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('sales:generate-recurring')->dailyAt('06:00')->when(fn (): bool => Modules::enabled(Modules::SALES));
