<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Switch port ayarları
|--------------------------------------------------------------------------
| Portun elle ayarlanabilen değerleri. Controller ve Form Request aynı
| listeyi buradan okur; iki yerde tutulursa doğrulama ile arayüz ayrışır.
*/

return [

    /*
    | Hız değerleri Mbps cinsinden saklanır; JunOS'ta "1g" gibi kısaltmalar
    | da geçerli olsa da sayısal Mbps hem sıralanabilir hem de eski panelle
    | aynı yazım. 2000, iki 1G bağlantının birleştirildiği durumlar için.
    |
    | Auto-negotiation buraya girmez; o bir hız değeri değil, ayrı bir
    | ayardır ve bir hızla birlikte de açık olabilir (auto + 1000 Mbps).
    */
    'speeds' => [
        '' => 'Belirtilmemiş',
        '10' => '10 Mbps',
        '100' => '100 Mbps',
        '1000' => '1000 Mbps',
        '2000' => '2000 Mbps',
        '10000' => '10000 Mbps',
        '25000' => '25000 Mbps',
        '40000' => '40000 Mbps',
        '100000' => '100000 Mbps',
    ],

    /*
    | VoIP (ses) arayüzüne verilebilen class-of-service sınıfları.
    |
    | Junos'un dört VARSAYILAN sınıfı budur. Cihazda "class-of-service
    | forwarding-classes" ile başkaları da tanımlanabilir ve panel onları
    | reddetmez: cihazda olmayan bir kısıt uydurmak, kullanıcının geçerli
    | bir yapılandırmayı yazamamasına yol açar. Cihazdan okunan bir sınıf
    | bu listede yoksa o kaydın formuna EKLENİR — aksi hâlde düzenlemede
    | kullanıcının dokunmadığı satır sessizce değişirdi.
    */
    'voip_forwarding_classes' => [
        'best-effort' => 'best-effort',
        'expedited-forwarding' => 'expedited-forwarding (ses için yaygın)',
        'assured-forwarding' => 'assured-forwarding',
        'network-control' => 'network-control',
    ],

    /*
    | Arayüze tanımlanan adresin maskesi. Elle yazıldığında yanlış maske
    | fark edilmeden kaydediliyordu; listeden seçilir. Doğrulama da bu
    | listeden okur, form ile kural ayrışamaz.
    */
    'prefix_lengths' => [
        8 => '/8', 9 => '/9', 10 => '/10', 11 => '/11', 12 => '/12', 13 => '/13',
        14 => '/14', 15 => '/15', 16 => '/16', 17 => '/17', 18 => '/18', 19 => '/19',
        20 => '/20', 21 => '/21', 22 => '/22', 23 => '/23', 24 => '/24', 25 => '/25',
        26 => '/26', 27 => '/27', 28 => '/28', 29 => '/29', 30 => '/30', 31 => '/31',
        32 => '/32',
    ],
];
