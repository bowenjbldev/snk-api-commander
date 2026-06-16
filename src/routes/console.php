<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Control plane: requiere un proceso de scheduler corriendo
// (php artisan schedule:work, o cron con schedule:run). Ver docs/CONTROL-PLANE.md.
// Solo en deploys de tenant; el Commander apaga el control plane (CORE_CONTROL_PLANE=false).
if (config('core.control_plane', true)) {
    Schedule::command('core:usage:rollup --prune')->hourly()->withoutOverlapping();
    Schedule::command('core:quota:measure')->everySixHours()->withoutOverlapping();
}
