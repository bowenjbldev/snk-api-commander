<?php

namespace Packages\Commander\Contracts;

use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderInstanceModel;

interface CommanderMetricMeasurer
{
    public function measure(
        CommanderInstanceModel $instance,
        CommanderApplicationMetricModel $metric,
        bool $tenantConnected,
    ): ?int;
}
