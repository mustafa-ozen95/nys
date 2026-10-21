<?php

declare(strict_types=1);

use App\Enums\PrefixAuthorizationPolicy;
use App\Services\Announcement\Authorities\IrrRouteAuthority;
use App\Services\Announcement\Authorities\RoaAuthority;

/*
|--------------------------------------------------------------------------
| Prefix anons talepleri
|--------------------------------------------------------------------------
| Alıcı listesi koda gömülmez. Her alıcının dili, gönderilecek metnin hangi
| şablondan üretileceğini belirler.
*/

return [

    'default_asn' => env('ANNOUNCEMENT_ASN', 'AS64496'),

    'recipients' => [
        [
            'key' => 'noc',
            'label' => 'NOC',
            'email' => env('ANNOUNCEMENT_MAIL_NOC', 'noc@example.test'),
            'locale' => 'tr',
        ],
        [
            'key' => 'peering',
            'label' => 'Peering',
            'email' => env('ANNOUNCEMENT_MAIL_PEERING', 'peering@example.test'),
            'locale' => 'en',
        ],
        [
            'key' => 'netops',
            'label' => 'NetOps',
            'email' => env('ANNOUNCEMENT_MAIL_NETOPS', 'netops@example.test'),
            'locale' => 'en',
        ],
    ],

    /*
    | Anons maillerinin işlendiği kuyruk.
    |
    | "default" BIRAKILAMAZ: o kuyruğu dinleyen hiçbir işçi yok. systemd
    | birimi nms-switch-write'ı, zamanlayıcı nms-switch-sync'i dinliyor.
    | Kuyruk belirtilmezse mailler alınır ama hiç işlenmez — talep
    | "kuyruğa alındı" der, mail hiç çıkmaz.
    |
    | nms-switch-sync bu projenin genel işçi kuyruğudur; zabbix, snmp,
    | netconf ve parola döndürme de oraya yazıyor.
    */
    'queue' => env('ANNOUNCEMENT_QUEUE', 'nms-switch-sync'),

    'subject' => 'Prefix List Ekleme Talebi / Prefix Addition Request',

    /*
    |--------------------------------------------------------------------------
    | Anons yetkisi denetimi
    |--------------------------------------------------------------------------
    |
    | Bir bloğun prefix listesine eklenmesini istemeden önce o bloğu anons
    | etme hakkımız kanıtlanır. İki bağımsız kanıt aranır: RPKI ROA (imzalı
    | yetki) ve IRR route nesnesi (karşı tarafın filtresini üreten kayıt).
    |
    | Yeni bir kaynak eklemek: PrefixAuthority uygulayan bir sınıf ve
    | aşağıdaki listeye BİR satır. Çıkarmak: aynı iki yerden silmek.
    |
    */

    'authorization' => [

        'enabled' => (bool) env('ANNOUNCEMENT_AUTHORIZATION', true),

        /*
        | Kaç kaynağın onayı yeter?
        |
        | Varsayılan "any": bir blok en az bir kaynak onaylıyorsa gönderilir,
        | HİÇBİRİ onaylamıyorsa (ne ROA ne route nesnesi uyuşuyorsa) durur.
        | Eksik kalan kaynak gizlenmez, ekranda uyarı olarak durur.
        |
        | "all" daha katıdır: her kaynağın onayını ister. Kayıtları tam
        | olmayan bir AS'te prefixlerin önemli bir kısmı sırf bir kayıt
        | eksik diye dururdu.
        */
        'policy' => env('ANNOUNCEMENT_AUTHORIZATION_POLICY', PrefixAuthorizationPolicy::Any->value),

        'authorities' => [
            RoaAuthority::class,
            IrrRouteAuthority::class,
        ],

        /*
        | Denetlenebilecek en fazla prefix. Her prefix her kaynağa bir sorgu
        | demektir; sınırın üstündeki talep sessizce yarım denetlenmez,
        | denetim yapılmadan reddedilir.
        */
        'max_prefixes' => (int) env('ANNOUNCEMENT_MAX_PREFIXES', 40),

        /*
        | Önbellek süreleri (saniye). Onaylı cevap gün boyu geçerlidir; ROA ve
        | IRR kaydı dakikalar içinde değişmez. Olumsuz cevap kısa tutulur:
        | eksik kaydı açtıran operatör beş dakika sonra tekrar denemelidir.
        */
        'cache' => [
            'approved' => (int) env('ANNOUNCEMENT_CACHE_APPROVED', 21600),
            'rejected' => (int) env('ANNOUNCEMENT_CACHE_REJECTED', 300),
        ],

        'ripestat' => [
            'url' => env('RIPESTAT_RPKI_URL', 'https://stat.ripe.net/data/rpki-validation/data.json'),
            'timeout' => (int) env('RIPESTAT_TIMEOUT', 10),
            // RIPEstat çağıranın kendini tanıtmasını ister.
            'source_app' => env('RIPESTAT_SOURCE_APP', 'nys-nms'),
        ],

        'irr' => [
            'host' => env('IRR_WHOIS_HOST', 'whois.radb.net'),
            'port' => (int) env('IRR_WHOIS_PORT', 43),
            'timeout' => (int) env('IRR_WHOIS_TIMEOUT', 10),
            /*
            | RPKI türevi nesneler sayılmaz: IRRd, ROA'lardan otomatik route
            | nesnesi üretir. Kabul edilseydi IRR kontrolü ROA kontrolünün
            | kopyası olur, ikinci kanıt diye bir şey kalmazdı.
            */
            'ignored_sources' => ['RPKI'],
        ],
    ],

    'history_limit' => 100,
];
