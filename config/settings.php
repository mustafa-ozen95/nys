<?php

declare(strict_types=1);

use App\Enums\MailScheme;
use App\Enums\MailTransport;
use App\Enums\MeasurementSource;
use App\Enums\SyncInterval;

/*
|--------------------------------------------------------------------------
| Ayar ekranının tanımı
|--------------------------------------------------------------------------
| Ayar ekranı, doğrulama kuralları ve config bindirmesi TEK yerden beslenir.
| Yeni bir ayar eklemek için buraya bir satır yazmak yeterlidir; Blade,
| Form Request ve servis sağlayıcı kendiliğinden uyar.
|
| Her bölüm kendi alt sayfasıdır; "slug" o sayfanın adres parçasıdır
| (/settings/monitoring). Bütün ayarların tek sayfada durması karışıklık üretir.
|
| Anahtar, üzerine bindirilecek config yolunun kendisidir: "zabbix.url"
| yazınca config('zabbix.url') değişir. Böylece config() diyen çağrı yerleri
| hiç değişmez ve bir ayar silindiğinde uygulama config dosyasındaki
| varsayılana geri döner.
|
| "permission_id" bölümün YETKİ KİMLİĞİDİR ve asla değişmez: kullanıcı
| izinleri bu sayıya bağlı saklanıyor. Yeni bir bölüm eklenirken sıradaki
| sayı verilir; silinen bir bölümün sayısı yeniden kullanılmaz.
|
| Alan türleri:
|   boolean  açık/kapalı
|   integer  sayı (min/max ile)
|   string   düz metin
|   secret   şifreli saklanır, ekranda hiç basılmaz, boş bırakılınca değişmez
|   select   enum'dan gelen kapalı liste
*/

/*
 * Saat dilimi listesi PHP'nin kendi IANA veritabanından gelir; elle yazılmış
 * bir liste güncel kalmazdı. config:cache bunu diziye çevirip dosyaya yazar,
 * yani çalışma anında maliyeti yoktur.
 */
$timeZones = array_combine(
    DateTimeZone::listIdentifiers(),
    DateTimeZone::listIdentifiers(),
);

/*
 * BASİT MOD anahtarları bölüm listesinden ÜRETİLİR (bkz. config/simple_mode.php):
 * listeye eklenen bölüm Ayarlar ekranına kendiliğinden gelir. Etiketler yetki
 * kataloğundan okunur; aynı ad iki yerde yazılı kalmaz.
 */
$simpleModeSources = (require __DIR__.'/permissions.php')['resources'];
$simpleMode = require __DIR__.'/simple_mode.php';
$simpleModeKeys = [];
$simpleModeSettings = [];
foreach (array_keys($simpleMode['bolumler']) as $section) {
    $name = $simpleModeSources[$section]['label'] ?? $section;
    foreach (['goruntule' => 'görüntülenir', 'yonet' => 'yönetilir'] as $action => $verb) {
        $key = "simple_mode.bolumler.{$section}.{$action}";
        $simpleModeKeys[] = $key;
        $simpleModeSettings[$key] = [
            'label' => "{$name} — {$verb}",
            'hint' => $action === 'goruntule'
                ? 'Kapalıysa bölüm basit modda menüden kalkar ve açılmaz.'
                : 'Kapalıysa basit modda ekleme, düzenleme ve silme yapılamaz; yalnızca görülür.',
            'type' => 'boolean',
        ];
    }

    /* Bölümün alanları: basit modda görünsün mü (bkz. config/simple_mode.php · alanlar). */
    foreach ($simpleMode['alanlar'][$section] ?? [] as $alan => $definition) {
        $key = "simple_mode.alanlar.{$section}.{$alan}.acik";
        $simpleModeKeys[] = $key;
        $simpleModeSettings[$key] = [
            'label' => $definition['etiket'],
            'hint' => 'Kapalıysa basit modda görünmez; cihazdaki değeri korunur.',
            'type' => 'boolean',
        ];
    }
}

return [

    'groups' => [

        /*
         * Basit modda hangi switch bölümü görünür ve yönetilebilir. Gelişmiş
         * moda kimin geçebildiği burada DEĞİL, rol yetkisinde ("Gelişmiş
         * mod"): o bir kişiye/role verilen haktır, panel geneli ayar değil.
         */
        'basit_mod' => [
            'permission_id' => 16,
            'slug' => 'simple-mode',
            'label' => 'Basit mod',
            'description' => 'Basit moddaki kullanıcının hangi switch bölümlerini görebileceği ve yönetebileceği. '
                .'Rol yetkisini genişletmez, yalnızca daraltır. Gelişmiş moda kimin geçebileceği rol ayarlarındaki '
                .'“Gelişmiş mod” yetkisidir.',
            'keys' => $simpleModeKeys,
        ],

        'panel' => [
            'permission_id' => 15,
            'slug' => 'timezone',
            'label' => 'Saat dilimi',
            'description' => 'Kayıtların, zamanlayıcının ve ekranlardaki bütün tarih/saatlerin '
                .'hangi dilime göre gösterileceği. Denetim kaydı takibi için doğru olması şart: '
                .'yanlış dilim, olayların gerçekte ne zaman olduğunu saatlerce kaydırır.',
            'keys' => [
                'app.timezone',
            ],
        ],

        'measurement' => [
            'permission_id' => 1,
            'slug' => 'measurement',
            'label' => 'Ölçüm kaynağı',
            'description' => 'Çevrimiçilik, uptime, CPU, sıcaklık ve trafik nereden okunsun. '
                .'Cihaz yapılandırması (port, VLAN, BGP, firewall) bundan etkilenmez; o daima NETCONF’tan gelir.',
            'keys' => [
                'measurement.source',
                'measurement.fallback',
                'measurement.failure_ttl',
                'measurement.sample_retention_days',
            ],
        ],

        'zabbix' => [
            'permission_id' => 2,
            'slug' => 'monitoring',
            'label' => 'İzleme sistemi (Zabbix)',
            'description' => 'Yalnızca okuma yapılır; Zabbix’e hiçbir şey yazılmaz.',
            'keys' => [
                'zabbix.enabled',
                'zabbix.url',
                'zabbix.api_token',
                'zabbix.timeout',
                'zabbix.web_timeout',
                'zabbix.retry_times',
                'zabbix.cache.host_map',
                'zabbix.cache.traffic',
            ],
        ],

        'mail' => [
            'permission_id' => 12,
            'slug' => 'email',
            'label' => 'E-posta (SMTP)',
            'description' => 'Prefix anons talepleri buradan gider. '
                .'“Gönderme, günlüğe yaz” seçiliyken hiçbir mail çıkmaz; '
                .'talep gönderilmiş görünür ama karşı tarafa ulaşmaz.',
            'keys' => [
                'mail.default',
                'mail.mailers.smtp.host',
                'mail.mailers.smtp.port',
                'mail.mailers.smtp.scheme',
                'mail.mailers.smtp.username',
                'mail.mailers.smtp.password',
                'mail.from.address',
                'mail.from.name',
            ],
        ],

        'netconf' => [
            'permission_id' => 3,
            'slug' => 'device',
            'label' => 'Cihaz erişimi (NETCONF)',
            'description' => 'Cihaza yazan tek yol. Geri alınabilir commit süresi güvenlik ağıdır; sıfırlanamaz.',
            'keys' => [
                'netconf.port',
                'netconf.timeout',
                'netconf.confirm_minutes',
                'netconf.change_detection',
            ],
        ],

        'snmp' => [
            'permission_id' => 4,
            'slug' => 'snmp',
            'label' => 'SNMP',
            'description' => 'Cihazdan yalnızca okuma. Yazma (SET) hiçbir yerde yoktur.',
            'keys' => [
                'snmp.enabled',
                'snmp.timeout',
                'snmp.retries',
            ],
        ],

        'sync' => [
            'permission_id' => 5,
            'slug' => 'calendar',
            'label' => 'Eşitleme takvimi',
            'description' => 'Değişiklik bir dakika içinde yürürlüğe girer; zamanlayıcı bu değerleri her turda okur.',
            'keys' => [
                'sync.status',
                'sync.ports',
                'sync.snmp',
                'sync.netconf',
                'sync.maintenance',
                'sync.workers',
            ],
        ],

        'thresholds' => [
            'permission_id' => 6,
            'slug' => 'thresholds',
            'label' => 'Uyarı eşikleri',
            'description' => 'Bir switchin ne zaman “uyarı” sayılacağı ve göstergelerin ne zaman renk değiştireceği. '
                .'Tehlike eşiği aşılırsa switch listede uyarı durumuna geçer.',
            'keys' => [
                'ui.thresholds.cpu.warning',
                'ui.thresholds.cpu.danger',
                'ui.thresholds.memory.warning',
                'ui.thresholds.memory.danger',
                'ui.thresholds.temperature.warning',
                'ui.thresholds.temperature.danger',
                'ui.thresholds.bandwidth.warning',
                'ui.thresholds.bandwidth.danger',
                'ui.per_page',
            ],
        ],

        'remote' => [
            'permission_id' => 7,
            'slug' => 'remote-session',
            'label' => 'Uzak oturum',
            'description' => 'SSH ve RDP pencerelerinin ömrü. Jeton kısa ömürlüdür; uzatmak açık bırakılmış bir pencerenin '
                .'ne kadar süre kullanılabilir kalacağını da uzatır.',
            'keys' => [
                'remote.session.ttl',
                'remote.guacamole.timeout',
            ],
        ],

        'session' => [
            'permission_id' => 8,
            'slug' => 'session',
            'label' => 'Panel oturumu ve kayıt',
            'description' => 'Panele girişin ne kadar açık kalacağı ve etkinlik kaydının nasıl tutulacağı.',
            'keys' => [
                'session.lifetime',
                'activity.auth_dedupe_minutes',
            ],
        ],

        'announcements' => [
            'permission_id' => 9,
            'slug' => 'announcement',
            'label' => 'Prefix anonsu',
            'description' => 'Anons metninde kullanılan varsayılanlar.',
            'keys' => [
                'announcements.default_asn',
                'announcements.history_limit',
            ],
        ],

        /*
         * Toplu parola değişiminde üretilen parolanın biçimi. Cihazın
         * kuralı burada da geçerlidir ve doğrulamada uygulanır: yalnız
         * küçük (ya da yalnız büyük) harften oluşan parola reddedilir.
         */
        'credentials' => [
            'permission_id' => 11,
            'slug' => 'device-password',

            /*
             * Şeritte AYRI SEKME olarak görünmez: alanları "Cihaz parolaları"
             * ekranının içinde, ürettiği parolaların hemen üstünde basılır.
             * Biçimi seçtiğin yer ile sonucunu gördüğün yer aynı olmalı.
             *
             * Grup yine de eksiksizdir: doğrulama, saklama ve bölüm bazlı
             * yetki değişmeden çalışır, adresi de açık kalır.
             */
            'tab' => false,
            'label' => 'Cihaz parolası',
            'description' => 'Toplu parola değişiminde üretilen parolanın uzunluğu ve hangi karakterlerden oluşacağı. '
                .'Cihaz EN AZ İKİ farklı karakter sınıfı ister; tek sınıflı parolayı reddeder. '
                .'Az sınıf seçilirse gereken uzunluk artar.',
            'keys' => [
                'credentials.rotation.length',
                'credentials.rotation.lowercase',
                'credentials.rotation.uppercase',
                'credentials.rotation.digits',
                'credentials.rotation.symbols',
                'credentials.rotation.avoid_ambiguous',
            ],
        ],

        'kimlik' => [
            'permission_id' => 13,
            'slug' => 'identity',
            'label' => 'Kimlik Yönetim Merkezi',
            'description' => 'Kullanıcılar parolalarını buraya değil, Kimlik Yönetim Merkezi’ne yazar. '
                .'Panelin kendi parola girişi kapanmaz; merkez erişilemez olduğunda tek yol odur.',
            'keys' => [
                'identity.acik',
                'identity.yonetici_devri',
            ],
        ],

        'maintenance' => [
            'permission_id' => 10,
            'slug' => 'maintenance',
            'label' => 'Bakım',
            'description' => 'Sınırsız büyüyen kayıtların saklama süresi.',
            'keys' => [
                'maintenance.activity_retention_days',
                'maintenance.access_retention_days',
            ],
        ],
    ],

    /*
    | Ayar olmayan, ama Ayarlar şeridinde durması gereken ekranlar.
    |
    | Bunlar bir değer düzenlemez; bir iş yaptırır (cihaz parolalarını
    | değiştirmek gibi). Kendi rotaları ve kendi ekranları vardır; burada
    | yalnızca şeritte görünmek için dururlar.
    */
    'actions' => [

        'roles' => [
            'slug' => 'roles',
            'label' => 'Roller',
            'route' => 'settings.roles',
            'gate' => 'manageRoles',
        ],

        'api' => [
            'slug' => 'api',
            'label' => 'API jetonları',
            'route' => 'settings.api',
            'gate' => 'manageApiTokens',
        ],

        'passwords' => [
            'slug' => 'passwords',
            'label' => 'Cihaz parolaları',
            'route' => 'settings.passwords',
            'gate' => 'viewDevicePasswords',
        ],

        /*
         * Sertifika: tur sunucuda koşuyor ve sertifika aynı vekilin
         * arkasındaki panelleri birlikte kapsayabiliyor.
         */
        'sertifika' => [
            'slug' => 'certificate',
            'label' => 'Sertifika',
            'route' => 'settings.certificate',
            'gate' => 'manageCertificate',
        ],

        /*
         * Veritabanı: panelin veritabanını uzak bir sunucuya taşır.
         */
        'veritabani' => [
            'slug' => 'database',
            'label' => 'Veritabanı',
            'route' => 'settings.database',
            'gate' => 'manageDatabase',
        ],

        /*
         * Güncelleme: yeni sürüm var mı (yalnızca bakar, kurmaz).
         */
        'guncelleme' => [
            'slug' => 'update',
            'label' => 'Güncelleme',
            'route' => 'settings.updates',
            'gate' => 'manageUpdate',
        ],
    ],

    'items' => [

        ...$simpleModeSettings,

        /*
         * Merkez ile giriş.
         *
         * Açıldığında giriş ekranı merkezi ÖNE alır ve parola formunu
         * katlar — kapatmaz. İki koşullu: istemci bilgileri yoksa bu ayar
         * açık olsa da akış çalışmaz (bkz. CenterClient::acikMi).
         */
        'identity.acik' => [
            'label' => 'Kimlik Yönetim Merkezi ile giriş',
            'hint' => 'Açıkken giriş ekranı merkezi öne alır; parolayla giriş '
                .'katlanmış hâlde durur ve merkez erişilemediğinde kullanılabilir.',
            'type' => 'boolean',
            'rules' => ['boolean'],
        ],

        'identity.yonetici_devri' => [
            'label' => 'Merkezdeki yönetici burada da yönetici olsun',
            'hint' => 'KAPALI TUTULMASI ÖNERİLİR. Merkezin yöneticisi kimlikleri yönetir; buranın '
                .'yöneticisi cihazlara yazabilir ve parola değiştirebilir. Açıkken merkezde rol '
                .'dağıtabilen biri, buradaki hiçbir yöneticinin haberi olmadan kendini burada da '
                .'yönetici yapabilir. Kapalıyken yeni hesap varsayılan rolle doğar.',
            'type' => 'boolean',
            'rules' => ['boolean'],
        ],

        'mail.default' => [
            'label' => 'Gönderim biçimi',
            'hint' => 'Günlüğe yazma bir hata ayıklama kipidir ve SESSİZDİR: '
                .'mail gitmiş görünür, aslında storage/logs içine düşer.',
            'type' => 'select',
            'enum' => MailTransport::class,
            'rules' => ['required'],
        ],

        'mail.mailers.smtp.host' => [
            'label' => 'SMTP sunucusu',
            'hint' => 'Yalnızca adres: posta.ornek.test',
            'type' => 'string',
            'mono' => true,
            'rules' => ['nullable', 'string', 'max:255'],
        ],

        'mail.mailers.smtp.port' => [
            'label' => 'Port',
            'hint' => 'STARTTLS için 587, doğrudan TLS için 465, şifresiz için 25.',
            'type' => 'integer',
            'rules' => ['required', 'integer', 'between:1,65535'],
        ],

        'mail.mailers.smtp.scheme' => [
            'label' => 'Şifreleme',
            'hint' => 'Port ile uyumlu olmalı: 465 doğrudan TLS ister, '
                .'STARTTLS ile açılmaya çalışılırsa el sıkışma hiç tamamlanmaz.',
            'type' => 'select',
            'enum' => MailScheme::class,
            'rules' => ['required'],
        ],

        'mail.mailers.smtp.username' => [
            'label' => 'Kullanıcı adı',
            'hint' => 'Sunucu kimlik doğrulaması istemiyorsa boş bırakın.',
            'type' => 'string',
            'mono' => true,
            'rules' => ['nullable', 'string', 'max:255'],
        ],

        'mail.mailers.smtp.password' => [
            'label' => 'Parola',
            'hint' => 'Şifreli saklanır ve hiçbir ekranda basılmaz. Boş bırakılırsa mevcut parola korunur. '
                .'Sunucu, port, şifreleme ya da kullanıcı adı değişirse parola yeniden girilmelidir.',
            'type' => 'secret',
            // Parolanın gönderildiği uç: bunlar değişirse parola yeni uca giderdi.
            'binds' => [
                'mail.mailers.smtp.host',
                'mail.mailers.smtp.port',
                'mail.mailers.smtp.scheme',
                'mail.mailers.smtp.username',
            ],
            'rules' => ['nullable', 'string', 'max:255'],
        ],

        'mail.from.address' => [
            'label' => 'Gönderen adresi',
            'hint' => 'Alıcı tarafta bu adres görünür; SMTP sunucusunun göndermesine izin verdiği bir adres olmalı.',
            'type' => 'string',
            'mono' => true,
            'rules' => ['nullable', 'email', 'max:255'],
        ],

        'mail.from.name' => [
            'label' => 'Gönderen adı',
            'type' => 'string',
            'rules' => ['nullable', 'string', 'max:100'],
        ],

        'credentials.rotation.length' => [
            'label' => 'Parola uzunluğu',
            'hint' => 'Karakter sayısı. Az sınıf seçilirse gereken uzunluk artar: parola en az 64 bit taşımalı. '
                .'Cihaz parolayı özet olarak tutuyor ve yapılandırma yedeği her commit’te cihazdan çıkıyor.',
            'type' => 'integer',
            'unit' => 'karakter',
            'rules' => ['required', 'integer', 'between:8,128'],
        ],

        'credentials.rotation.lowercase' => [
            'label' => 'Küçük harf',
            'type' => 'boolean',
        ],

        'credentials.rotation.uppercase' => [
            'label' => 'Büyük harf',
            'hint' => 'Cihaz en az iki sınıf ister; hangi ikisi olduğu fark etmez.',
            'type' => 'boolean',
        ],

        'credentials.rotation.digits' => [
            'label' => 'Rakam',
            'type' => 'boolean',
        ],

        'credentials.rotation.symbols' => [
            'label' => 'Noktalama',
            'hint' => 'Kapalı tutmak önerilir: noktalama kabuk, beklenti dosyası ve URL geçişlerinde kaçırılmak zorunda ve saha araçlarında sessizce bozuluyor.',
            'type' => 'boolean',
        ],

        'credentials.rotation.avoid_ambiguous' => [
            'label' => 'Karışan karakterleri ele',
            'hint' => '0/O ve 1/l/I havuzdan çıkar. Parola arıza anında konsoldan elle de yazılıyor.',
            'type' => 'boolean',
        ],

        'measurement.source' => [
            'label' => 'Birincil kaynak',
            'hint' => 'Geçmiş grafikleri yalnızca izleme sisteminde vardır; cihaz kaynaklarında grafik panelin kendi örneklemesinden çizilir.',
            'type' => 'select',
            'enum' => MeasurementSource::class,
            'rules' => ['required'],
        ],

        'measurement.fallback' => [
            'label' => 'Erişilemezse cihaza sor',
            'hint' => 'Birincil kaynak yanıt vermediğinde ölçüm cihazın kendisinden okunur. Kapalıyken ölçüm “bilinmiyor” olur; hiçbir durumda uydurulmaz.',
            'type' => 'boolean',
        ],

        'measurement.failure_ttl' => [
            'label' => 'Devre kesici süresi',
            'hint' => 'Hata veren kaynak bu süre boyunca denenmez. Bu olmadan kesinti anında her sayfa zaman aşımı kadar donar.',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:10', 'max:3600'],
        ],

        'app.timezone' => [
            'label' => 'Saat dilimi',
            'hint' => 'Türkiye için Europe/Istanbul. Değişiklik yeni kayıtların yazılışını değil, '
                .'saklanan anın gösterimini etkiler: kayıtlar UTC saklanır, ekranda bu dilime çevrilir.',
            'type' => 'select',
            'options' => $timeZones,
            'rules' => ['required'],
        ],

        'measurement.sample_retention_days' => [
            'label' => 'Trafik örneği saklama',
            'hint' => 'Cihazdan okunan anlık trafik bu kadar gün saklanır ve grafik ondan çizilir. Sıfır: örnek tutulmaz.',
            'type' => 'integer',
            'unit' => 'gün',
            'rules' => ['integer', 'min:0', 'max:90'],
        ],

        'zabbix.enabled' => [
            'label' => 'Kullanılsın',
            'hint' => 'Kapatıldığında Zabbix’e hiçbir istek gitmez ve eşitleme işleri kuyruğa alınmaz.',
            'type' => 'boolean',
        ],

        'zabbix.url' => [
            'label' => 'Sunucu adresi',
            'hint' => 'Şema dahil: https://izleme.ornek.test',
            'type' => 'string',
            'mono' => true,
            'rules' => ['nullable', 'url', 'max:255'],
        ],

        'zabbix.api_token' => [
            'label' => 'API jetonu',
            'hint' => 'Şifreli saklanır ve hiçbir ekranda basılmaz. Boş bırakılırsa mevcut jeton korunur. '
                .'Sunucu adresi değişirse jeton yeniden girilmelidir.',
            'type' => 'secret',
            // Jetonun gönderildiği uç: adres değişirse jeton yeni adrese giderdi.
            'binds' => ['zabbix.url'],
            'rules' => ['nullable', 'string', 'max:255'],
        ],

        'zabbix.timeout' => [
            'label' => 'Zaman aşımı (kuyruk)',
            'hint' => 'Arka plan işlerinde beklenecek süre.',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:1', 'max:120'],
        ],

        'zabbix.web_timeout' => [
            'label' => 'Zaman aşımı (sayfa)',
            'hint' => 'Kullanıcının önündeki sayfada beklenecek süre. Kuyruk değerinden kısa olmalı.',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:1', 'max:30'],
        ],

        'zabbix.retry_times' => [
            'label' => 'Yeniden deneme',
            'hint' => 'Toplam bekleme, zaman aşımı ile çarpılır: 15 saniye ve 2 deneme, en kötü ihtimalle 45 saniyedir.',
            'type' => 'integer',
            'unit' => 'kez',
            'rules' => ['integer', 'min:0', 'max:5'],
        ],

        'netconf.port' => [
            'label' => 'Port',
            'hint' => 'Junos’ta NETCONF ayrı bir SSH subsystem’idir; öntanımlı 830.',
            'type' => 'integer',
            'rules' => ['integer', 'min:1', 'max:65535'],
        ],

        'netconf.timeout' => [
            'label' => 'Zaman aşımı',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:5', 'max:120'],
        ],

        'netconf.confirm_minutes' => [
            'label' => 'Geri alınabilir commit süresi',
            'hint' => 'Commit bu süre içinde onaylanmazsa cihaz kendini eski hâline döndürür. Yönetim erişimini bozan bir değişiklikte tek kurtarıcı budur.',
            'type' => 'integer',
            'unit' => 'dakika',
            'rules' => ['integer', 'min:1', 'max:60'],
        ],

        'netconf.change_detection' => [
            'label' => 'Değişim algısı',
            'hint' => 'Tam okumadan önce son commit’e bakılır; değişmemişse okuma yapılmaz. Cihaza binen yükü onda birine indirir.',
            'type' => 'boolean',
        ],

        'snmp.enabled' => [
            'label' => 'Kullanılsın',
            'type' => 'boolean',
        ],

        'snmp.timeout' => [
            'label' => 'Zaman aşımı',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:1', 'max:60'],
        ],

        'snmp.retries' => [
            'label' => 'Yeniden deneme',
            'type' => 'integer',
            'unit' => 'kez',
            'rules' => ['integer', 'min:0', 'max:5'],
        ],

        'sync.status' => [
            'label' => 'Ölçüm',
            'hint' => 'Çevrimiçilik, CPU, bellek, sıcaklık, uptime.',
            'type' => 'select',
            'enum' => SyncInterval::class,
            'rules' => ['required'],
        ],

        'sync.ports' => [
            'label' => 'Port ölçümleri',
            'hint' => 'Oper durumu, hız, dupleks, anlık trafik.',
            'type' => 'select',
            'enum' => SyncInterval::class,
            'rules' => ['required'],
        ],

        'sync.snmp' => [
            'label' => 'SNMP okumaları',
            'hint' => 'IP adresleri; NETCONF kimliği olmayan cihazlarda VLAN ve commit geçmişi.',
            'type' => 'select',
            'enum' => SyncInterval::class,
            'rules' => ['required'],
        ],

        'sync.netconf' => [
            'label' => 'Cihaz yapılandırması',
            'hint' => 'Portlar, VLAN, IP, route, prefix list, firewall, politika, BGP.',
            'type' => 'select',
            'enum' => SyncInterval::class,
            'rules' => ['required'],
        ],

        'sync.maintenance' => [
            'label' => 'Bakım',
            'hint' => 'Süresi dolmuş etkinlik kayıtlarını ve trafik örneklerini siler.',
            'type' => 'select',
            'enum' => SyncInterval::class,
            'rules' => ['required'],
        ],

        'zabbix.cache.host_map' => [
            'label' => 'Host eşlemesi önbelleği',
            'hint' => 'Yönetim IP’si ile Zabbix host kimliğinin eşlemesi bu kadar süre saklanır. Zabbix’te host eklendiğinde bu süre kadar gecikme olabilir.',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:60', 'max:86400'],
        ],

        'zabbix.cache.traffic' => [
            'label' => 'Anlık trafik önbelleği',
            'hint' => 'Port trafiği bu kadar süre boyunca yeniden sorulmaz. Kısaltmak ekranı tazeler, kaynağa binen yükü artırır.',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:5', 'max:600'],
        ],

        'ui.thresholds.cpu.warning' => [
            'label' => 'İşlemci — uyarı',
            'type' => 'integer',
            'unit' => '%',
            'rules' => ['integer', 'min:1', 'max:100'],
        ],

        'ui.thresholds.cpu.danger' => [
            'label' => 'İşlemci — tehlike',
            'hint' => 'Bu değeri aşan switch listede “uyarı” durumuna geçer.',
            'type' => 'integer',
            'unit' => '%',
            'rules' => ['integer', 'min:1', 'max:100'],
        ],

        'ui.thresholds.memory.warning' => [
            'label' => 'Bellek — uyarı',
            'type' => 'integer',
            'unit' => '%',
            'rules' => ['integer', 'min:1', 'max:100'],
        ],

        'ui.thresholds.memory.danger' => [
            'label' => 'Bellek — tehlike',
            'hint' => 'Bu değeri aşan switch listede “uyarı” durumuna geçer.',
            'type' => 'integer',
            'unit' => '%',
            'rules' => ['integer', 'min:1', 'max:100'],
        ],

        'ui.thresholds.temperature.warning' => [
            'label' => 'Sıcaklık — uyarı',
            'type' => 'integer',
            'unit' => '°C',
            'rules' => ['integer', 'min:1', 'max:150'],
        ],

        'ui.thresholds.temperature.danger' => [
            'label' => 'Sıcaklık — tehlike',
            'type' => 'integer',
            'unit' => '°C',
            'rules' => ['integer', 'min:1', 'max:150'],
        ],

        'ui.thresholds.bandwidth.warning' => [
            'label' => 'Port doluluğu — uyarı',
            'type' => 'integer',
            'unit' => '%',
            'rules' => ['integer', 'min:1', 'max:100'],
        ],

        'ui.thresholds.bandwidth.danger' => [
            'label' => 'Port doluluğu — tehlike',
            'type' => 'integer',
            'unit' => '%',
            'rules' => ['integer', 'min:1', 'max:100'],
        ],

        'ui.per_page' => [
            'label' => 'Sayfa başına kayıt',
            'hint' => 'Uzun listelerde (kayıtlar, kullanıcılar) bir sayfada kaç satır gösterileceği.',
            'type' => 'integer',
            'unit' => 'satır',
            'rules' => ['integer', 'min:10', 'max:200'],
        ],

        'remote.session.ttl' => [
            'label' => 'Oturum jetonu ömrü',
            'hint' => 'Uzak oturum jetonu bu süre sonunda geçersiz olur. Uzatmak, açık bırakılmış bir pencerenin kullanılabilir kalma süresini de uzatır.',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:60', 'max:7200'],
        ],

        'remote.guacamole.timeout' => [
            'label' => 'RDP ağ geçidi zaman aşımı',
            'type' => 'integer',
            'unit' => 'saniye',
            'rules' => ['integer', 'min:1', 'max:60'],
        ],

        'session.lifetime' => [
            'label' => 'Panel oturum süresi',
            'hint' => 'Hareketsiz kalan oturum bu süre sonunda kapanır.',
            'type' => 'integer',
            'unit' => 'dakika',
            'rules' => ['integer', 'min:5', 'max:10080'],
        ],

        'activity.auth_dedupe_minutes' => [
            'label' => 'Oturum olaylarını birleştirme',
            'hint' => 'Aynı kullanıcının aynı IP’den arka arkaya gelen giriş/çıkış olayları bu süre içinde tek kayda düşer.',
            'type' => 'integer',
            'unit' => 'dakika',
            'rules' => ['integer', 'min:0', 'max:1440'],
        ],

        'announcements.default_asn' => [
            'label' => 'Varsayılan AS numarası',
            'hint' => 'Anons metninde bizim AS’imiz olarak yazılır.',
            'type' => 'string',
            'mono' => true,
            'rules' => ['nullable', 'string', 'max:32'],
        ],

        'announcements.history_limit' => [
            'label' => 'Anons geçmişi',
            'hint' => 'Anons ekranında en fazla kaç geçmiş kayıt listelenir.',
            'type' => 'integer',
            'unit' => 'kayıt',
            'rules' => ['integer', 'min:10', 'max:1000'],
        ],

        'sync.workers' => [
            'label' => 'Kuyruk işçisi sayısı',
            'hint' => 'Aynı anda kaç iş işlenir. İşçiler zamanlayıcıdan başlar; işletim sistemi tarafında servis tanımı gerekmez. '
                .'Değişiklik bir dakika içinde yürürlüğe girer.',
            'type' => 'integer',
            'unit' => 'süreç',
            'rules' => ['integer', 'min:1', 'max:20'],
        ],

        'maintenance.activity_retention_days' => [
            'label' => 'Denetim kaydı saklama',
            'hint' => 'Oturum açma, kayıt işlemleri ve cihaza yazma. Sıfır: hiçbir kayıt silinmez.',
            'type' => 'integer',
            'unit' => 'gün',
            'rules' => ['integer', 'min:0', 'max:3650'],
        ],

        'maintenance.access_retention_days' => [
            'label' => 'Erişim kaydı saklama',
            'hint' => 'Sayfa açma ve form gönderme kayıtları; hacmin neredeyse tamamı budur. '
                .'Kendi zincirinde korunur, ayrı budanır. Sıfır: hiçbir kayıt silinmez.',
            'type' => 'integer',
            'unit' => 'gün',
            'rules' => ['integer', 'min:0', 'max:3650'],
        ],
    ],
];
