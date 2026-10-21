# Yerel veri

Bu dizin **depoya girmez** (`.gitignore`). Yazılım depoda durur, veri burada.

## `envanter.php`

Ekip hesapları, switch envanteri ve sunucu envanteri. `NetworkSeeder`,
`ServerSeeder`, `NetworkTeamSeeder` ve `NetworkTeamPermissionSeeder` varsa
okur; **yoksa hiçbir şey yapmazlar**.

Şekli:

```php
<?php

use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;

return [
    /* Panele açılacak ekip hesapları. */
    'ekip' => [
        ['name' => 'Ad Soyad', 'email' => 'ad@ornek.test', 'password' => '...'],
    ],

    /* Envantere girecek switch'ler. CANLI cihaz yazılmaz. */
    'switchler' => [
        [
            'hostname' => 'sw1',
            'management_ip' => '...',
            'uplink_ip' => '...',
            'role' => SwitchRole::Core,          // Core | Access
            'location' => SwitchLocation::Plus,
            'username' => 'admin',
            'password' => '...',
            'community' => '...',                 // SNMP v2c
        ],
    ],

    /* Sunucu envanteri. 'tur' => linux | windows ('surum' yalnızca windows'ta) */
    'sunucular' => [
        ['tur' => 'linux', 'kisa' => 'LNX-01', 'uzun' => 'Ad', 'adres' => '...', 'port' => 22, 'kullanici' => 'root'],
    ],

    /* "adres:port" => parola. Eksik olan sunucu parolasız kaydedilir. */
    'sunucu_parolalari' => [
        '...:22' => '...',
    ],

    /* Ağ ekibine AÇILMAYAN sunucuların adresleri. */
    'ekibe_kapali_sunucular' => [],
];
```

Çalıştırmak için:

```bash
php artisan db:seed        # DatabaseSeeder hepsini sırayla çağırır
```

Dosya yoksa üretimde seeder **durur** ve dosyanın adını söyler; üretim dışında
sessizce boş geçer.
