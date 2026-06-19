<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El scheduling del control-plane (commander:refresh / usage:rollup / quota:measure)
// vive en CommanderServiceProvider (el operador es dueño de esos comandos).
