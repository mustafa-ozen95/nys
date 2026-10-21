<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Uzak oturum ayarları
|--------------------------------------------------------------------------
| DİKKAT: Buradaki port aralığı ve proxy yolları nginx yapılandırmasıyla
| birebir eşleşmek zorundadır; eşleşmezse oturumlar sessizce kırılır.
|
|   ttyd.port_range      → location ~ ^/ssh-proxy/(?<ttyd_port>\d+)
|                          ve içindeki "^7[789][0-9][0-9]$" kısıtı
|   ttyd.proxy_path      → aynı location
|   guacamole.proxy_path → location ^~ /rdp-proxy/  →  127.0.0.1:8090/guacamole/
|
| İlgili dosya: /etc/nginx/sites-enabled/panel-a.ornek.test.conf
*/

return [

    'session' => [
        'ttl' => 600,
        'cache_prefix' => 'remote_session:',
    ],

    'ttyd' => [
        'binary' => env('TTYD_BINARY', '/usr/local/bin/ttyd'),
        'sshpass_binary' => env('SSHPASS_BINARY', '/usr/bin/sshpass'),
        'ssh_binary' => env('SSH_BINARY', '/usr/bin/ssh'),
        'port_range' => [7700, 7900],
        'proxy_path' => '/ssh-proxy',
        'session_path' => 'ssh-sessions',
        'orphan_log_ttl' => 3600,
    ],

    'guacamole' => [
        'url' => env('GUACAMOLE_URL', 'http://127.0.0.1:8090/guacamole'),
        'timeout' => (int) env('GUACAMOLE_TIMEOUT', 10),
        'secret' => env('GUACAMOLE_SECRET'),
        'username' => env('GUACAMOLE_USER', 'guacadmin'),
        'proxy_path' => '/rdp-proxy',
        'rdp' => [
            'security' => 'any',
            'ignore_cert' => 'true',
            'resize_method' => 'display-update',
            'enable_drive' => 'true',
            'drive_path' => env('GUACAMOLE_DRIVE_PATH', '/tmp'),
            'recording_path' => env('GUACAMOLE_RECORDING_PATH', '/var/lib/guacamole/recordings'),
            'server_layout' => 'tr-tr-qwerty',
        ],
    ],
];
