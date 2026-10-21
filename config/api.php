<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Panelin dışarıya açtığı okuma arayüzü. Kimlik doğrulaması jetonla yapılır
| ("Authorization: Bearer ..."); oturum çerezi kabul edilmez.
|
| Jeton bir KİMLİK BİLGİSİDİR ve sahibi adına konuşur. İki sınırı vardır ve
| ikisi birden geçerlidir:
|
|   1. Jetonun kendi yetkileri (hangi kaynakta ne yapabilir).
|   2. SAHİBİNİN yetkileri. Jeton sahibini aşamaz — sahibinin yetkisi
|      daraltılınca jeton da daralır.
|
| Bu yüzden jeton üretmek yöneticiye özeldir: başkası adına jeton çıkarmak,
| o kişinin yetkisini kalıcı bir anahtara dönüştürmektir.
*/

return [

    /*
    | Jetonun görünen öneki. Sızan bir dizgenin ne olduğu ilk bakışta
    | anlaşılsın; sır değildir, kayıtta ve ekranda basılabilir.
    */
    'prefix' => env('API_TOKEN_PREFIX', 'nsm'),

    /*
    | Jetonun sır kısmının bayt uzunluğu. 32 bayt = 256 bit; base62'ye
    | çevrildiğinde ~43 karakter.
    */
    'secret_bytes' => (int) env('API_TOKEN_BYTES', 32),

    /*
    | Yeni jetonun varsayılan ömrü (gün). Boş bırakılırsa süresiz olur;
    | süresiz jeton unutulur ve unutulan jeton sızar.
    */
    'default_lifetime_days' => (int) env('API_TOKEN_LIFETIME_DAYS', 365),

    /*
    | Dakikada izin verilen istek sayısı (jeton başına). Sınır jetondadır,
    | IP'de değil: aynı IP'den gelen iki jeton birbirini yavaşlatmamalı.
    */
    'rate_limit' => (int) env('API_RATE_LIMIT', 120),

    /*
    | Kimliği doğrulanamayan istekler için dakikadaki sınır (adres başına).
    | Geçerli jetonlu istekler de bu kapıdan geçtiği için jeton sınırından
    | belirgin biçimde yüksektir; amaç kimliksiz bir uca sınırsız istek
    | atılabilmesini engellemektir.
    */
    'guest_rate_limit' => (int) env('API_GUEST_RATE_LIMIT', 600),

    /*
    |----------------------------------------------------------------------
    | Uçların belgesi
    |----------------------------------------------------------------------
    | Anahtar rota adıdır ("api.v1." öneki olmadan). Belge buradan üretilir;
    | ekranda elle yazılmış ikinci bir liste YOKTUR — ayrışırdı.
    |
    | "ability" ucun istediği yetkidir ve "kaynak:aksiyon" biçimindedir.
    | Jeton bunu taşımak zorunda; SAHİBİ de aynı yetkiye sahip olmalı.
    |
    | Bir uç eklenip buraya yazılmazsa test kırılır (ApiDocumentationTest).
    */
    'endpoints' => [

        'switches.index' => [
            'summary' => 'Switch envanteri',
            'ability' => 'switches:view',
            'description' => 'İsteği yapanın görebildiği switchler. Sayfalıdır.',
            'parameters' => [
                'per_page' => 'Sayfa başına kayıt (varsayılan 50).',
                'page' => 'Sayfa numarası.',
            ],
        ],

        'switches.show' => [
            'summary' => 'Tek switch',
            'ability' => 'switches:view',
            'description' => 'Adresteki kimlik switchin uuid’sidir; sıralı birincil anahtar dışarı çıkmaz.',
            'parameters' => [],
        ],

        'servers.index' => [
            'summary' => 'Sunucu envanteri',
            'ability' => 'servers:view',
            'description' => 'İsteği yapanın görebildiği sunucular. Sayfalıdır. '
                .'Bağlantı parolası ve kullanıcı adı DÖNMEZ; parola kasadan okunur.',
            'parameters' => [
                'per_page' => 'Sayfa başına kayıt (varsayılan 50).',
                'page' => 'Sayfa numarası.',
            ],
        ],

        'servers.show' => [
            'summary' => 'Tek sunucu',
            'ability' => 'servers:view',
            'description' => 'Adresteki kimlik sunucunun uuid’sidir. '
                .'Son parola turunun durumu da döner; parolanın kendisi dönmez.',
            'parameters' => [],
        ],

        'addressing.blocks.index' => [
            'summary' => 'Adresleme blokları',
            'ability' => 'addressing:view',
            'description' => 'Tanımlı ağlar. "assignable" alanı bloğun hâlâ '
                .'atanabilir olup olmadığını söyler: bir erişim switchine route '
                .'edilmiş blok tükenmiştir ve ikinci bir yere verilemez.',
            'parameters' => [
                'per_page' => 'Sayfa başına kayıt (varsayılan 50).',
                'page' => 'Sayfa numarası.',
            ],
        ],

        'addressing.blocks.show' => [
            'summary' => 'Tek adresleme bloğu',
            'ability' => 'addressing:view',
            'description' => 'Adresteki kimlik bloğun uuid’sidir.',
            'parameters' => [],
        ],

        'addressing.blocks.hosts' => [
            'summary' => 'Blok içindeki adresler',
            'ability' => 'addressing:view',
            'description' => 'Hangi adres kimde, hangisi boşta. Sahip bilgisi tek '
                .'tablodan değil; adres switch arayüzünde, sunucuda ya da BGP '
                .'komşusunda olabilir. Çok büyük bloklarda liste verilmez '
                .'("limited": true) — bir /8 ağı adres adres dökmek kimseye yaramaz.',
            'parameters' => [],
        ],

        'switches.routes' => [
            'summary' => 'Switchin statik route’ları',
            'ability' => 'switch_routes:view',
            'description' => 'Yetki route kaynağının kendisindedir: switchi görebilen '
                .'biri route tablosunu göremeyebilir.',
            'parameters' => [],
        ],

        'switches.bgp-neighbors' => [
            'summary' => 'Switchin BGP komşuları',
            'ability' => 'switch_bgp:view',
            'description' => 'Komşular ve oturum durumları. Durum cihazdan okunur. '
                .'MD5 oturum anahtarı DÖNMEZ.',
            'parameters' => [],
        ],

        'switches.ports' => [
            'summary' => 'Switchin portları',
            'ability' => 'switch_ports:view',
            'description' => 'Yetki port kaynağının kendisindedir: switchi görebilen biri portlarını göremeyebilir.',
            'parameters' => [],
        ],

        'switches.vlans' => [
            'summary' => 'Switchin VLAN’ları',
            'ability' => 'switch_vlans:view',
            'description' => 'VLAN adı ve etiketi.',
            'parameters' => [],
        ],

        'switches.ip-addresses' => [
            'summary' => 'Switchin IP adresleri',
            'ability' => 'switch_ip_addresses:view',
            'description' => 'Arayüz adı, adres ve prefix uzunluğu.',
            'parameters' => [],
        ],
    ],
];
