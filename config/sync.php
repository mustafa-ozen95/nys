<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Eşitleme takvimi
|--------------------------------------------------------------------------
| Sıklıklar bilinçli olarak farklıdır: ölçüm sık ve ucuz, envanter seyrek ve
| pahalıdır. Değerler App\Enums\SyncInterval içindeki kapalı listeden gelir;
| "off" o işi tamamen durdurur.
|
| routes/console.php bu değerleri her schedule:run çağrısında okur, yani ayar
| ekranından yapılan değişiklik bir dakika içinde yürürlüğe girer.
*/

return [

    // Ölçüm: çevrimiçilik, CPU, bellek, sıcaklık, uptime.
    'status' => env('SYNC_STATUS_INTERVAL', '30s'),

    // Port ölçümleri: oper durumu, hız, dupleks, anlık trafik.
    'ports' => env('SYNC_PORTS_INTERVAL', '5m'),

    // SNMP yedek okumaları: IP adresleri, NETCONF'suz cihazlarda VLAN.
    'snmp' => env('SYNC_SNMP_INTERVAL', '30m'),

    // Cihaz yapılandırması (NETCONF). Değişim algısı açıksa buradaki her tur
    // tek RPC'ye iner; yalnızca yeni commit varsa tam okuma yapılır.
    'netconf' => env('SYNC_NETCONF_INTERVAL', '15m'),

    // Bakım: eski etkinlik kayıtları ve trafik örnekleri.
    'maintenance' => env('SYNC_MAINTENANCE_INTERVAL', '1d'),

    /*
    | Kaç kuyruk işçisi çalışsın.
    |
    | İşçiler zamanlayıcıdan başlatılır: her dakika, kuyruk boşalınca kendi
    | kendine çıkan kısa ömürlü süreçler. Böylece işletim sistemi tarafında
    | supervisor gibi bir servis tanımı gerekmez; tek bağımlılık zamanlayıcıyı
    | çağıran cron satırıdır.
    |
    | Sayıyı artırmak eşzamanlı işlenen iş sayısını artırır. Cihaza giden
    | işler zaten switch başına tekil olduğu için (ShouldBeUnique) fazla
    | işçi aynı cihaza yüklenmez; darboğaz genelde NETCONF oturum süresidir.
    */
    'workers' => (int) env('SYNC_WORKERS', 5),

    /*
    | Bir işin en fazla ne kadar süreceği (saniye).
    |
    | NETCONF timeout'undan BÜYÜK olmak zorundadır. Cihaz yanıt vermediğinde
    | tek bir RPC config('netconf.timeout') kadar bekler; bir eşitleme işi
    | oturum kurar ve ardından on beşe yakın RPC çalıştırır. İş zamanaşımı
    | RPC zamanaşımından küçük olursa, cihaz sadece yavaşladığında iş
    | ortasından kesilir, yeniden denenir ve aynı yerde yine kesilir —
    | eşitleme hiç tamamlanmaz.
    |
    | İşçinin --max-time'ı (55 sn) buna engel değildir: o yalnızca YENİ iş
    | almayı durdurur, çalışan iş bitene kadar sürer.
    */
    'job_timeout' => (int) env('SYNC_JOB_TIMEOUT', 90),
];
