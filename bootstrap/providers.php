<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,

    // Panelden değiştirilen ayarlar config üzerine bindirilir; diğer
    // sağlayıcılar config okurken güncel değeri görmeli.
    SettingsServiceProvider::class,
];
