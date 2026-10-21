<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ölçüm kaynağı
|--------------------------------------------------------------------------
| Panelin iki tür verisi vardır ve kaynakları ayrıdır:
|
|   YAPILANDIRMA  — portlar, VLAN, BGP, firewall. Kaynağı daima cihazdır
|                   (NETCONF). Burada seçilen değer bunu ETKİLEMEZ.
|   ÖLÇÜM         — çevrimiçilik, uptime, CPU, bellek, sıcaklık, port
|                   oper durumu, anlık trafik. Kaynağı burada seçilir.
|
| Zabbix en zengin kaynaktır (geçmiş grafikleri yalnızca onda vardır) ama tek
| kaynak olmamalıdır: izleme sistemi çöktüğünde panelin körelmesi, hatta
| bütün filoyu "çevrimdışı" göstermesi kabul edilemez. Yedek açıkken birincil
| kaynağa erişilemediği anda cihazın kendisine sorulur.
*/

return [

    'source' => env('MEASUREMENT_SOURCE', 'zabbix'),

    /*
    | Birincil kaynağa erişilemediğinde cihaza doğrudan sorulsun mu.
    | Kapatılırsa ölçüm "bilinmiyor" olur; hiçbir durumda uydurulmaz.
    */
    'fallback' => (bool) env('MEASUREMENT_FALLBACK', true),

    /*
    | Bir kaynak hata verdiğinde kaç saniye boyunca denenmesin (devre kesici).
    |
    | Bu olmadan Zabbix kesintisinde her istek timeout kadar donar: 15 saniye
    | zaman aşımı ve iki yeniden deneme, istek başına 45 saniye demektir.
    | Bayrak Redis'te tutulur ve süresi dolunca kaynak kendiliğinden yeniden
    | denenir; elle müdahale gerekmez.
    */
    'failure_ttl' => (int) env('MEASUREMENT_FAILURE_TTL', 60),

    /*
    | Cihazdan okunan anlık trafik örneklerinin saklanacağı gün sayısı.
    |
    | Zabbix kullanılmadığında geçmiş grafiği bu örneklerden çizilir; Zabbix
    | kullanılırken hiç yazılmaz. Sıfır yapılırsa grafik yalnızca Zabbix ile
    | çalışır.
    */
    'sample_retention_days' => (int) env('MEASUREMENT_SAMPLE_RETENTION_DAYS', 7),

    /*
    | Bir örnek kaç saniye boyunca "anlık" sayılır.
    |
    | Ekran anlık trafiği panelin kendi örneklerinden okuyor; eski bir örnek
    | anlık DEĞİLDİR ve sayı olarak basılırsa yanlış bilgi olur. Sınırı aşan
    | değer gösterilmez, sütun boş kalır.
    |
    | Varsayılan, port ölçüm turunun (sync.ports) birkaç katı olacak şekilde
    | seçildi: tek bir turun kaçması sütunu boşaltmasın.
    */
    'sample_freshness' => (int) env('MEASUREMENT_SAMPLE_FRESHNESS', 1200),

    /*
    | Cihaz saatinin panelden kaç saniye sapması UYARI sayılır.
    |
    | Birkaç saniyelik fark normaldir; her ekrana uyarı koymak uyarıyı
    | değersizleştirir. Varsayılan beş dakika: bunun üstü artık "saat yanlış"
    | demektir ve commit geçmişi, günlükler, sertifika doğrulaması yanlış
    | zamana yazılır.
    */
    'clock_drift_threshold' => (int) env('MEASUREMENT_CLOCK_DRIFT_THRESHOLD', 300),

    /*
    | Süren bir ölçüm arızası kaç saniyede bir günlüğe yazılsın?
    |
    | Devre kesici süresi kısadır (failure_ttl) ve ölçüm turu 30 saniyede bir
    | koşar: sınır olmasa ulaşılamayan tek bir cihaz saatte yüzlerce satır
    | üretir ve günlük dosyası hızla büyür. Gerçek bir üretim hatasını o
    | yığında bulmak mümkün olmaz.
    |
    | Arıza sürerken saatte bir satır yeterlidir; kaynak toparlayınca işaret
    | düşer ve bir sonraki arıza yeniden yazılır.
    */
    'report_interval' => (int) env('MEASUREMENT_REPORT_INTERVAL', 3600),
];
