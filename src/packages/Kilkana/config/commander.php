<?php

use Packages\Kilkana\Metrics\SnkKilkanaMetricMeasurer;

return [
    'metrics' => [
        'editorial_seats' => [
            'name' => 'Editorial seats',
            'unit' => 'count',
            'kind' => 'gauge',
            'window' => 'current',
            'measurer' => SnkKilkanaMetricMeasurer::class,
        ],
        'reads_month' => [
            'name' => 'Article reads per month',
            'unit' => 'count',
            'kind' => 'counter',
            'window' => 'month',
            'measurer' => SnkKilkanaMetricMeasurer::class,
        ],
    ],
];
