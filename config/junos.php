<?php

declare(strict_types=1);

use App\Services\Junos\ElsDialect;
use App\Services\Junos\PreElsDialect;

return [

    /*
    |---------------------------------------------------------------------
    | Junos lehçeleri
    |---------------------------------------------------------------------
    |
    | Junos aynı ayarı aileden aileye farklı yerde ve farklı biçimde tutar.
    | Servisler bu farkları BİLMEZ; şekli lehçeye sorarlar
    | (App\Services\Junos\JunosDialect).
    |
    | Yeni bir Junos ailesi eklemek üç adımdır ve hiçbiri servislere dokunmaz:
    |
    |   1. JunosDialect'ten türeyen bir sınıf yaz; yalnızca FARKLI olan
    |      cevapları ez. Varsayılanlar ELS öncesi davranıştır.
    |   2. Anahtarını buraya ekle.
    |   3. Kalıcı ayrım için JunosSchemaProfile'a aynı anahtarla bir case ekle.
    |
    | Anahtar, veritabanındaki switches.schema_profile değeriyle aynıdır.
    |
    */

    'dialects' => [
        'pre_els' => PreElsDialect::class,
        'els' => ElsDialect::class,
    ],

];
