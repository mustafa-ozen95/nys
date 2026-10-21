<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cihaz erişim parolaları
|--------------------------------------------------------------------------
| Panelin switchlere bağlanmak için kullandığı hesabın parolası buradan
| yönetilir. Toplu değiştirme her cihaza AYRI ve rastgele bir parola verir;
| tek bir parolanın bütün sahayı açması diye bir durum kalmaz.
|
| Değiştirme sırası güvenliğin kendisidir (bkz. SwitchPasswordRotator):
| önce tam yetkili bir kurtarma hesabı açılır ve ÇALIŞTIĞI kanıtlanır,
| parola ancak ondan sonra değişir, yeni parola da denendikten sonra
| kurtarma hesabı silinir. Herhangi bir adımda takılırsa kurtarma hesabı
| cihazda BIRAKILIR: panelin cihaza dönebileceği bir kapı hep açık kalır.
*/

return [

    'rotation' => [

        /*
        | Üretilecek parolanın uzunluğu ve hangi karakter sınıflarından
        | oluşacağı. Hepsi Ayarlar > Cihaz parolası ekranından değiştirilir.
        |
        | CİHAZIN KURALI: Junos altı karakterden kısa parolayı ve TEK
        | KARAKTER SINIFINDAN oluşan parolayı reddeder — "require change of
        | case, digits or punctuation". Junos 12.3'te ölçüldü: yalnız küçük,
        | yalnız büyük, yalnız rakam ve yalnız noktalama reddedilir; iki
        | sınıfın her birleşimi kabul edilir.
        |
        | Panel ayrıca bir ENTROPİ TABANI uygular (64 bit): cihaz iki sınıfla
        | sekiz karakteri kabul eder ama böyle bir parola özet olarak
        | yedeklere çıkıyor ve çevrimdışı kırılabiliyor.
        |
        | PasswordGenerator ikisini de üretmeden reddeder.
        |
        | Üretilen parola, açık olan HER sınıftan en az bir karakter taşır.
        */
        'length' => (int) env('CREDENTIAL_ROTATION_LENGTH', 24),

        'lowercase' => (bool) env('CREDENTIAL_ROTATION_LOWERCASE', true),
        'uppercase' => (bool) env('CREDENTIAL_ROTATION_UPPERCASE', true),
        'digits' => (bool) env('CREDENTIAL_ROTATION_DIGITS', true),

        /*
        | Noktalama VARSAYILAN OLARAK KAPALI. Kabuk, beklenti dosyası ve URL
        | geçişlerinde kaçırılmak zorunda ve saha araçlarında sessizce
        | bozuluyor. Uzunluk aynı güvenliği zaten fazlasıyla veriyor.
        */
        'symbols' => (bool) env('CREDENTIAL_ROTATION_SYMBOLS', false),

        /*
        | Karışan karakterleri (0/O, 1/l/I) havuzdan çıkarır. Parola arıza
        | anında konsoldan elle de yazılıyor ve o anda 0 ile O'yu ayırmak
        | mümkün olmuyor.
        */
        'avoid_ambiguous' => (bool) env('CREDENTIAL_ROTATION_AVOID_AMBIGUOUS', true),

        /*
        | Kurtarma hesabının adı. Cihazda geçici olarak açılır, parola
        | değişimi doğrulandıktan sonra silinir.
        |
        | Panelin bağlandığı hesabın adıyla AYNI OLAMAZ: aynı olsaydı
        | kurtarma hesabını yaratmak asıl hesabın üstüne yazardı.
        */
        'recovery_username' => env('CREDENTIAL_ROTATION_USER', 'nsm-kurtarma'),

        /*
        | Toplu değiştirme kuyruğu. Eşitleme işleriyle aynı kuyruk kullanılır;
        | ayrı kuyruk ikinci bir işçi süreci ister ve işler sessizce beklemede
        | kalırdı.
        */
        'queue' => env('CREDENTIAL_ROTATION_QUEUE', env('NETCONF_QUEUE', 'nms-switch-sync')),

        /*
        | Tek cihazın turu için işçi zamanaşımı.
        |
        | Eşitleme işlerine göre ölçülmüş 90 saniyelik işçi zamanaşımı bu tur
        | için ÇOK KISADIR. Tur, cihazda üç ayrı yazma yapar (kurtarma hesabı,
        | parola, hesabın silinmesi) ve her yazma GERİ ALINABİLİR commit'tir:
        | önce "commit confirmed" atılır, sonra YENİ bir oturum açılıp onay
        | commit'i atılır. Yani üç yazma altı commit ve dokuz NETCONF oturumu
        | demektir; eski EX'lerde tek commit onlarca saniye sürebilir.
        |
        | Eski EX modellerinde bir tur 90 saniyeyi rahatça aşar. Zamanaşımı
        | kısa kalırsa işçi turu KESER; süreç SSH akışının ortasında öldüğü
        | için hata ekranda "NETCONF bağlantısı koptu: Invalid packet length"
        | diye görünür ve cihazın suçu sanılır — değildir. Kesilen turda
        | kurtarma hesabı cihazda AÇIK kalır.
        |
        | Kuyruğun retry_after'ı bundan BÜYÜK olmak zorundadır (bkz.
        | config/queue.php); küçük olursa kuyruk turu daha bitmeden ikinci kez
        | dağıtır. İkisinin ayrışmadığını KuyrukZamanasimiTest denetler.
        */
        'job_timeout' => (int) env('CREDENTIAL_ROTATION_TIMEOUT', 900),
    ],
];
