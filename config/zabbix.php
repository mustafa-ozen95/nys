<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bağlantı
    |--------------------------------------------------------------------------
    */

    /*
    | İzleme sistemi kullanılsın mı.
    |
    | Kapatıldığında Zabbix'e hiçbir istek gitmez ve eşitleme işleri kuyruğa
    | alınmaz; ölçüm cihazın kendisinden okunur. Panelin hiçbir ekranı
    | kapanmaz, yalnızca verinin kaynağı değişir.
    */
    'enabled' => (bool) env('ZABBIX_ENABLED', true),

    'url' => env('ZABBIX_URL'),

    'api_token' => env('ZABBIX_API_TOKEN'),

    'timeout' => (int) env('ZABBIX_TIMEOUT', 15),

    /*
    | Web isteği içindeki çağrılar için ayrı, kısa zaman aşımı.
    |
    | Kuyruktaki bir iş 15 saniye bekleyebilir; kullanıcının önündeki sayfa
    | bekleyemez. Bu değer olmadan Zabbix kesintisinde port sayfası zaman
    | aşımı ve yeniden denemelerle onlarca saniye donuyordu.
    */
    'web_timeout' => (int) env('ZABBIX_WEB_TIMEOUT', 4),

    'retry_times' => (int) env('ZABBIX_RETRY_TIMES', 2),

    'retry_sleep' => (int) env('ZABBIX_RETRY_SLEEP', 250),

    /*
    |--------------------------------------------------------------------------
    | Kuyruk
    |--------------------------------------------------------------------------
    | Redis başka uygulamalarla paylaşılabileceği için kuyruk adı ayrı tutulur.
    */

    'queue' => env('ZABBIX_QUEUE', 'nms-switch-sync'),

    /*
    |--------------------------------------------------------------------------
    | Önbellek süreleri (saniye)
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'host_map' => 3600,
        'items' => 300,
        'traffic' => 30,
        'history' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Zabbix item anahtar önekleri
    |--------------------------------------------------------------------------
    | Switch metrikleri ve arayüz sayaçları bu öneklerle eşleştirilir.
    */

    'items' => [
        'cpu' => ['system.cpu.util[jnxOperatingCPU'],
        'memory' => ['vm.memory.util[jnxOperatingBuffer'],
        'temperature' => ['sensor.temp.value[jnxOperatingTemp'],
        'uptime' => ['system.net.uptime[sysUpTime', 'system.hw.uptime[hrSystemUptime'],
        'hostname' => ['system.name'],
        'model' => ['system.hw.model'],
        'serial_number' => ['system.hw.serialnumber'],
        'os_version' => ['system.sw.os'],
        'ping' => ['icmpping'],
        'interface_in' => 'net.if.in[ifHCInOctets.',
        'interface_out' => 'net.if.out[ifHCOutOctets.',
        'interface_status' => 'net.if.status[ifOperStatus.',
        'interface_speed' => 'net.if.speed[ifHighSpeed.',
        'interface_type' => 'net.if.type[ifType.',
        'interface_duplex' => 'net.if.duplex[dot3StatsDuplexStatus.',
        'interface_in_errors' => 'net.if.in.errors[ifInErrors.',
        'interface_out_errors' => 'net.if.out.errors[ifOutErrors.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Arayüz filtreleri
    |--------------------------------------------------------------------------
    | ifType MIB kodları: softwareLoopback(24), l2vlan(135), l3ipvlan(136),
    | propVirtual(53) ve tünel/kapsülleme türleri envantere alınmaz.
    */

    'ignored_interface_types' => [24, 28, 48, 52, 71, 135, 136],

    'physical_interface_pattern' => '/^(ge|xe|et|fe)-\d+\/\d+\/\d+$/',

    'aggregate_interface_pattern' => '/^ae\d+$/',

    /*
    |--------------------------------------------------------------------------
    | Grafik aralıkları (saniye)
    |--------------------------------------------------------------------------
    | Eşiğin üstündeki aralıklarda trend.get, altında history.get kullanılır.
    */

    'chart_ranges' => [
        '1h' => 3600,
        '6h' => 21600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ],

    'chart_trend_threshold' => 86400,

];
