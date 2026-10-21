<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Yapılandırma arşivi — varsayılan hedef
|--------------------------------------------------------------------------
| Junos, her commit'ten sonra yapılandırmayı bir dosya sunucusuna
| gönderebilir:
|
|   set system archival configuration transfer-on-commit
|   set system archival configuration archive-sites "ftp://kullanici:parola@sunucu/dizin/"
|
| Yedek AYARI CİHAZA ÖZGÜDÜR ve switch kaydında durur; her cihaz kendi
| sunucusuna gönderebilir. Buradaki değer yalnızca VARSAYILANDIR: cihaza
| ayrıca adres girilmediğinde bu kullanılır.
|
| Panel ayarları ekranında YOKTUR ve olmamalıdır — bir cihazın nereye yedek
| attığı o cihazın ekranında görünmeli, genel bir ayarda değil.
|
| Adres KİMLİK İÇERİR: cihaz kaydında şifreli saklanır, $hidden'dır, hiçbir
| ekranda ve commit açıklamasında basılmaz.
*/

return [

    'enabled' => (bool) env('ARCHIVAL_ENABLED', true),

    /*
     * Varsayılan BOŞ: adres kimlik içerir ve depoda durmamalı. Gerçek adres
     * ARCHIVAL_SITE ortam değişkeniyle verilir. Boşsa panel cihaza yedek
     * adresi yazmaz (bkz. BaseConfigurationTemplate).
     */
    'site' => env('ARCHIVAL_SITE', ''),
];
