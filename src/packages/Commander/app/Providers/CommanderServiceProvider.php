<?php

namespace Packages\Commander\Providers;

use Illuminate\Support\ServiceProvider;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Solo cuando el Commander es el package ÚNICO del deploy (API_PACKAGE=Commander, panel
        // del operador aislado) apaga el control plane de Core (metering/kill-switch/cuotas). En
        // modo centralizado (API_PACKAGE vacio) el Commander es un package mas y NO debe apagar el
        // control plane del resto de tenants. Bajo tests se mantiene ON (la suite ejercita core_*).
        $locked = CorePackageSelectorSupport::activePackageName();

        if (! $this->app->runningUnitTests()
            && $locked !== null
            && strcasecmp($locked, 'Commander') === 0) {
            config(['core.control_plane' => false]);
        }
    }
}
