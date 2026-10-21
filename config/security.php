<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Güvenlik başlıkları
|--------------------------------------------------------------------------
| Her yanıta eklenir. Değerler burada tutulur ki hangi kısıtın neden konduğu
| tek yerden okunabilsin.
*/

return [

    /*
    | BAŞLIKLARIN TEK KAYNAĞI BU DOSYADIR.
    |
    | X-Frame-Options, X-Content-Type-Options, Referrer-Policy ve
    | X-Permitted-Cross-Domain-Policies'in "önündeki web sunucusu zaten
    | koyuyor" diye atlanması yanlış olur: imajın /etc/nginx ağacında
    | add_header yoktur ve önde hangi katmanın durduğu kuruluma göre
    | değişir. Bir katmanın varlığı yorumla değil ölçümle bilinir
    | (bkz. SecurityHeadersTest).
    |
    | Çift gönderim riski yok — imajdaki nginx bunların hiçbirini koymuyor.
    */

    'headers' => [

        /*
        | Blade'de satır içi script ve style bırakılmadığı için 'unsafe-inline'
        | gerekmiyor. Yazı tipleri Google Fonts'tan gelir; başka hiçbir dış
        | kaynağa izin yok. frame-src 'self': uzak oturum penceresi kendi
        | alan adımızdaki ttyd/Guacamole proxy'sini gömer.
        */
        'Content-Security-Policy' => "default-src 'self'; "
            ."script-src 'self'; "
            ."style-src 'self' https://fonts.googleapis.com; "
            ."font-src 'self' https://fonts.gstatic.com; "
            ."img-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-src 'self'; "

            /*
             * İŞÇİ (worker) blob'dan kurulur.
             *
             * `worker-src` yazılmadığında tarayıcı `script-src`e düşüyor ve
             * blob engelleniyordu: portal fırlatmasının zamanlayıcısı sessizce
             * hiç çalışmıyor, ikinci vCenter açılışı hata sayfasında kalıyordu.
             * Blob işçisi kendi kökenimizden üretiliyor;
             * dışarıdan betik çalıştırma yolu açmıyor.
             */
            ."worker-src 'self' blob:; "
            ."frame-ancestors 'self'; "
            ."base-uri 'self'; "
            ."form-action 'self'; "
            ."object-src 'none'",

        /*
        | Çerçeveleme korumasını asıl CSP'deki frame-ancestors üstleniyor;
        | bu, onu tanımayan eski tarayıcılar için.
        */
        'X-Frame-Options' => 'SAMEORIGIN',

        /* Tarayıcı içerik türünü tahmin etmesin. */
        'X-Content-Type-Options' => 'nosniff',

        /*
        | Adres başka kökene taşınmaz. Uzak oturum adresleri port ve jeton
        | taşıyor; dışarı çıkarken yol da gitmemeli.
        */
        'Referrer-Policy' => 'same-origin',

        'X-Permitted-Cross-Domain-Policies' => 'none',

        /*
        | HSTS KAPALI — ve BİLEREK max-age=0 GÖNDERİLİYOR.
        |
        | Başlık, tarayıcıya "bu adrese bir daha asla http ile gitme" der ve
        | bunu KENDİ İÇİNDE SAKLAR. Sertifika bir gün düşerse (ör. yenileme
        | aksarsa) tarayıcı uyarı ekranını bile göstermiyor: "Yine de devam
        | et" düğmesi HSTS'li adreslerde YOKTUR. Yani yönetici kendi paneline
        | hiçbir şekilde ulaşamaz; varsayılanın kapalı olmasının sebebi budur.
        |
        | Başlığı SİLMEK YETMEZ: tarayıcıda zaten yerleşmiş kayıt bir yıl
        | daha yaşar. `max-age=0` o kaydı SİLDİRİR. Değer bu yüzden burada
        | duruyor; kaldırılırsa eski tarayıcılar kilitli kalır.
        |
        | Karşılığında ne kaybediliyor: ilk istek http ile yapılırsa araya
        | girme penceresi. Panel güvenilir bir ağdan erişiliyorsa ve http
        | zaten https'e yönlendiriliyorsa bu risk, panele hiç girememe
        | riskinden küçüktür.
        */
        'Strict-Transport-Security' => 'max-age=0',

        /*
        | Kullanılmayan tarayıcı yetenekleri kapatılır. Pano açıkta bırakılır:
        | uzak oturum penceresinde kopyala/yapıştır gerekiyor.
        */
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), '
            .'payment=(), usb=(), magnetometer=(), gyroscope=(), autoplay=()',

        /*
        | Uzak oturum ayrı pencerede açılır ve panel onun kapanmasını izler;
        | bu yüzden "allow-popups" gerekiyor.
        */
        'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',

        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],
];
