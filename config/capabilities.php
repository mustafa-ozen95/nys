<?php

declare(strict_types=1);

use App\Services\Capabilities\PortSpeedCapability;
use App\Services\Capabilities\RoutingInstanceTypeCapability;

return [

    /*
    |--------------------------------------------------------------------------
    | Cihaza sorulan yetenekler
    |--------------------------------------------------------------------------
    |
    | Bir alanın kabul ettiği DEĞERLER modele göre değişebiliyor; lehçeye
    | yazmak ölçülmemiş bir kısıtı bütün aileye dayatmak olurdu. Cevabı cihaz
    | verir (bkz. App\Services\Capabilities\CapabilityProbe).
    |
    | Yeni bir yetenek eklemek: DeviceCapability uygulayan bir sınıf ve
    | buraya BİR satır. Çıkarmak: aynı iki yerden silmek.
    |
    */

    'probes' => [
        PortSpeedCapability::class,
        RoutingInstanceTypeCapability::class,
    ],

];
