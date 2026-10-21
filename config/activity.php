<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Etkinlik kaydı
|--------------------------------------------------------------------------
*/

return [

    /*
    | Aynı kullanıcının aynı IP'den arka arkaya gelen oturum olayları tek
    | kayda düşer. Tarayıcı testleri ve sekme yenilemeleri panelin "son
    | hareketler" listesini aynı satırla doldurmasın diye.
    */
    'auth_dedupe_minutes' => (int) env('ACTIVITY_AUTH_DEDUPE_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Değiştirilemezlik
    |--------------------------------------------------------------------------
    | Kayıt satırları HMAC-SHA256 ile zincirlenir: her satır kendinden
    | öncekinin özetini taşır. Bir satır değiştirilirse ya da silinirse
    | ondan sonraki bütün özetler tutmaz.
    |
    | Anahtar ayrı tutulabilir: APP_KEY'e düşmek çalışır ama .env'i okuyabilen
    | biri zinciri yeniden hesaplayabilir. Daha güçlü bir kurulumda bu değer
    | uygulama kullanıcısının okuyamadığı bir yerden gelmelidir.
    |
    | BOŞ DEĞER = VERİLMEMİŞ: compose değişkeni listeden geçirir; .env'de
    | boş kalan "ACTIVITY_CHAIN_KEY=" satırı konteynere boş dize olarak
    | gelir. env()'in varsayılanı yalnız değişken HİÇ yokken devreye girdiği
    | için zincir boş anahtarla imzalanırdı. ?: boşu da
    | APP_KEY'e düşürür.
    */
    'chain_key' => env('ACTIVITY_CHAIN_KEY') ?: env('APP_KEY'),

    /*
    | Zincirin başlangıç noktası. Budama eski satırları sildiğinde en son
    | silinen satırın özeti buraya yazılır; doğrulama kalan ilk satırın
    | oradan devam ettiğini görür. Bu olmadan saklama süresi dolduğunda
    | zincir "kırık" görünürdü.
    */
    'chain_anchor_key' => 'activity:chain-anchor',

    /*
    | Doğrulama sonucu bu anahtarla önbelleğe yazılır; kayıtlar ekranı her
    | açılışta yüz bin satırı yeniden özetlemek yerine buradan okur.
    */
    'verification_key' => 'activity:verification',

    /*
    |--------------------------------------------------------------------------
    | Cihaz işlem kaydı
    |--------------------------------------------------------------------------
    | Cihazla yapılan işlemlerin YAPISAL kaydı (device_operations). Etkinlik
    | kaydından ayrıdır: orası "kim ne yaptı", burası "cihaz ne dedi".
    |
    | Varsayılan olarak yalnızca DÜŞEN ve UYARI ÜRETEN işlemler yazılır.
    | Başarılı işlemler zaten etkinlik kaydındadır ve burada tekrarlanmaları
    | tabloyu şişirir; hata ayıklarken açılabilir.
    */
    'device_operations' => [
        'record_success' => (bool) env('DEVICE_OPERATIONS_RECORD_SUCCESS', false),

        /*
        | Saklama süresi (gün). Bu tablo hash zincirinde DEĞİLDİR — ayrıntı
        | burada, olayın kendisi zincirdeki etkinlik kaydında durur — bu
        | yüzden ortadan silmek bir şeyi kırmaz.
        */
        'retention_days' => (int) env('DEVICE_OPERATIONS_RETENTION_DAYS', 90),
    ],
    /*
    |--------------------------------------------------------------------------
    | Bildirim akışı
    |--------------------------------------------------------------------------
    |
    | Panelin bildirim alanı bir ARŞİV değildir; geçmişin tamamı kayıt
    | ekranlarında duruyor. Akış yalnızca "yakın zamanda ne oldu" sorusunu
    | cevaplar ve bu pencereyle sınırlıdır.
    |
    */

    'notifications' => [
        'window_hours' => (int) env('NOTIFICATION_WINDOW_HOURS', 24),
    ],

];
