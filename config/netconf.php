<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NETCONF
|--------------------------------------------------------------------------
| Juniper'ın XML yönetim API'si; SSH üzerinde ayrı bir subsystem olarak
| çalışır. SNMP'den farkı, cihazın DURUMUNU değil YAPILANDIRMASINI vermesidir:
| route tablosu, prefix list'ler, firewall filtreleri, port açıklamaları.
|
| Oturum açmak pahalıdır. Ölçüm (EX4200, 12.3R12.4): bir oturum kurmak ~2 sn
| sürer ve RE'de mgd fork'lar; oturum açıkken her ek RPC ~0.1 sn. Bu yüzden
| bir işte tek oturum açılır, bütün RPC'ler onun içinde çalışır ve iş biter
| bitmez oturum kapatılır. Oturum açık bırakılmaz, poll edilmez.
*/

return [

    'port' => (int) env('NETCONF_PORT', 830),

    // Saniye. Cihaz yanıt vermezse iş uzun sürmesin.
    'timeout' => (int) env('NETCONF_TIMEOUT', 20),

    /*
    | Değişim algısı: tam envanter okumasından önce cihazın son commit'ine
    | bakılır, değişmemişse okuma hiç yapılmaz.
    |
    | Yapılandırma ancak bir commit ile değişir — CLI'dan yapılan değişiklik
    | de commit üretir — bu yüzden atlamak veri tazeliğinden ödün vermez.
    | Cihaza binen yük onda birine iner: tek RPC (~0.1 sn) yerine oturum
    | boyunca on beş RPC.
    |
    | Kapatılırsa her turda her şey yeniden okunur; bir uyumsuzluktan
    | şüphelenildiğinde işe yarar.
    */
    'change_detection' => (bool) env('NETCONF_CHANGE_DETECTION', true),

    /*
    | Geri alınabilir commit süresi (dakika). Commit atıldıktan sonra bu süre
    | içinde onaylanmazsa cihaz kendini eski hâline döndürür. Değişiklik
    | yönetim erişimini bozarsa tek kurtarıcı budur; sıfır yapılmamalı.
    |
    | Onay ikinci bir NETCONF oturumunda verilir ve o oturum ~2 saniye sürer;
    | 2 dakika hem bol hem de aksilikte kısa sürede geri dönüş sağlar.
    */
    'confirm_minutes' => (int) env('NETCONF_CONFIRM_MINUTES', 2),

    /*
    | Eşitleme işleri SNMP ve Zabbix işleriyle aynı kuyruğu paylaşır: tek bir
    | işçi süreci hepsini işler. Ayrı kuyruğa alınırsa ikinci bir supervisor
    | süreci gerekir ve işler sessizce beklemede kalır.
    */
    'queue' => env('NETCONF_QUEUE', env('ZABBIX_QUEUE', 'nms-switch-sync')),

    /*
     * Cihaza YAZAN işlerin kuyruğu.
     *
     * Okumadan AYRIDIR ve ayrı bir işçi tarafından tüketilir. İki sebep:
     *
     *  1. Okuma işçisi "--tries=2" ile çalışıyor; bir cihaz yazmasını kör
     *     tekrar etmek, panelin bilmediği bir duruma ikinci kez yazmaktır.
     *  2. Bir yazma ELS switchte 45-60 saniye sürüyor; uzun yazmalar
     *     eşitleme turlarının arkasında sıraya girmemeli.
     */
    'write_queue' => env('NETCONF_WRITE_QUEUE', 'nms-switch-write'),

    /*
    | İstemcinin hello mesajında bildirdiği yetenekler. Çerçeveleme sürümü
    | buradan ve cihazın bildirdiklerinden kesişimle seçilir; yeni bir sürüm
    | desteklenecekse buraya eklemek ve Framing\FramingNegotiator'a bir sınıf
    | tanıtmak yeterlidir.
    |
    | base:1.1 bilinçli olarak açıktır: 12.3R12.4 sürümlü cihazlar yalnızca
    | 1.0 bildirdiği için onlarla 1.0 konuşulur, yeni bir cihaz 1.1 bildirdiği
    | anda kendiliğinden 1.1'e geçilir.
    */
    'capabilities' => [
        'urn:ietf:params:netconf:base:1.0',
        'urn:ietf:params:netconf:base:1.1',
    ],

    /*
    | ether-options altında yazılabilen hızlar: Mbps değeri => Junos elemanı.
    |
    | config/switching.php daha fazlasını listeler (2000, 10000, 25000...) ama
    | onlar panelde gösterim içindir; EX3200/4200 ether-options yalnızca bu
    | üçünü kabul eder. Form ve doğrulama buradan okur, ikisi ayrışamaz.
    */
    'speeds' => [
        '10' => 'ethernet-10m',
        '100' => 'ethernet-100m',
        '1000' => 'ethernet-1g',
    ],

    /*
    | ELS'te hız BAŞKA YERDE ve BAŞKA BİÇİMDE durur (test4, EX2300-24T,
    | 24.4R2-S4 üzerinde ölçüldü):
    |
    |   ELS öncesi : ether-options { speed { ethernet-100m; } }
    |   ELS        : interfaces <ad> { speed 100m; }
    |
    | ELS öncesi biçim ELS cihazda "syntax error" verir — ve panelin port
    | formu hızı HER KAYITTA gönderdiği için, bu ayrım yapılmadan ELS bir
    | switchte hiçbir port düzenlemesi çalışmıyordu.
    |
    | Değerin kendisi de porta bağlıdır: EX2300'ün ge- portu 10m/100m/auto
    | kabul ederken 1g'yi "invalid value" ile reddediyor (1G zaten doğal
    | hızı; oraya auto ile inilir). Panel değeri olduğu gibi yazar, cihaz
    | uymayanı reddeder ve hata cihaz işlem kaydına düşer.
    */
    'els_speeds' => [
        '10' => '10m',
        '100' => '100m',
        '1000' => '1g',
    ],

    /*
    | ether-options altında yazılabilen duplex biçimleri.
    |
    | HALF-DUPLEX YALNIZCA 10m/100m İLE GEÇERLİDİR. Cihaz aksini reddediyor:
    | "Half duplex is an invalid configuration when speed is 10G/1G/Auto"
    | (jn32-test, 12.3R12.4). Bu yüzden hız seçilmeden ya da 1000 seçiliyken
    | half sunulmaz; kısıt formda da doğrulamada da durur.
    |
    | Boş bırakmak "cihaz kendi seçsin" demektir; satır hiç yazılmaz.
    */
    'link_modes' => [
        'full' => 'full-duplex',
        'half' => 'half-duplex',
    ],

    // Half-duplex'in kabul edildiği hızlar (Mbps).
    'half_duplex_speeds' => ['10', '100'],
];
