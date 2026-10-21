<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arayüz eşikleri
|--------------------------------------------------------------------------
| Metrik göstergelerinin hangi değerde hangi renge döneceği. Blade veya
| controller içine gömülmez.
*/

return [

    'thresholds' => [
        'cpu' => ['warning' => 70, 'danger' => 85],
        'memory' => ['warning' => 75, 'danger' => 90],
        'temperature' => ['warning' => 55, 'danger' => 70],
        'bandwidth' => ['warning' => 70, 'danger' => 90],
    ],

    'port_grid' => [
        ['max' => 48, 'columns' => 12],
        ['max' => 96, 'columns' => 16],
        ['max' => null, 'columns' => 20],
    ],

    'per_page' => 25,
];
