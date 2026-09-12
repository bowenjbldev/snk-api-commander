<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El scheduling del commander (commander:refresh, commander:usage:rollup, commander:quota:measure,
// commander:git:sync) vive en CommanderServiceProvider (el operador es dueño de esos comandos).
