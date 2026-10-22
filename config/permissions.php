<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\AddressBlock;
use App\Models\AnnouncementRecipient;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\Authorization\Records\SettingGroupRecords;
use App\Services\Authorization\Records\ViewModeRecords;

return [

    /*
    |--------------------------------------------------------------------------
    | Aksiyonlar
    |--------------------------------------------------------------------------
    | 'connect' aksiyonu, switche/sunucuya uzak oturum (SSH/RDP) açma yetkisidir
    | ve 'view' yetkisinden bilinçli olarak ayrıdır: bir kullanıcı switchi
    | listede görebilir ama terminal açamayabilir.
    |
    | 'configure' aksiyonu cihazın yapılandırmasına YAZMA (NETCONF commit)
    | yetkisidir ve 'update' yetkisinden ayrıdır: 'update' envanter kaydını
    | düzenler, 'configure' cihazın kendisini değiştirir.
    |
    | 'provision' cihaza TAM config yükleme yetkisidir: elle yapıştırılan
    | metin ya da şablon çıktısı. "Tamamen değiştir" (override) seçeneğiyle
    | cihazın yapılandırmasının tamamını silip yerine yenisini koyabilir;
    | panelin en geniş etkili yazma yoludur ve bu yüzden ayrı durur.
    |
    | 'configure' YALNIZCA cihazın kendisine ait ayarlardır (SNMP adı,
    | yapılandırma yedeği). Port, VLAN, route, firewall, politika, BGP ve
    | toplama arayüzlerinin her biri KENDİ kaynağıdır ve kendi
    | görüntüle/oluştur/düzenle/sil yetkilerini taşır: "switchi okur-yazar"
    | diye tek bir anahtar yoktur.
    |
    | 'secret' cihaz erişim parolasını ekranda görme, 'rotate' ise onu
    | değiştirme, 'export' ise bağlantı bilgilerini dosyaya çıkarma
    | yetkisidir. Üçü de bilinçli olarak ayrıdır ve varsayılan olarak
    | KAPALIDIR: hiçbir rol şablonunda yoktur, yalnızca yönetici geçer.
    | Parola switch başına açılabilir — "şu switchin parolasını görsün,
    | diğerlerininkini görmesin" böyle söylenir.
    |
    | Nesne kaynaklarının kayıtları switchlerdir. Böylece "A switchinin
    | portlarını düzenlesin ama VLAN'larına dokunmasın" denebilir.
    */

    'actions' => [
        'view' => 'Görüntüle',
        'create' => 'Oluştur',
        'update' => 'Düzenle',
        'delete' => 'Sil',
        'connect' => 'Bağlan',
        'configure' => 'Cihaza Yaz',
        'provision' => 'Config Yükle',

        /*
         * "secret" cihaz erişim parolasını EKRANDA GÖRME yetkisidir,
         * "rotate" ise onu değiştirme. İkisi de "cihaza yaz"dan ayrıdır:
         * cihazı yapılandırabilen biri, parolayı okuyabilecek ya da
         * değiştirebilecek biri değildir. Varsayılan olarak hiçbir rol
         * şablonunda yoktur — yalnızca yönetici geçer.
         */
        'secret' => 'Parolayı Gör',
        'rotate' => 'Parolayı Değiştir',

        /*
         * "export" bağlantı bilgilerini DOSYAYA çıkarma yetkisidir ve
         * "secret"ı da aşar: ekranda görülen tek bir parola orada kalır,
         * dosyaya yazılan liste panelin dışına çıkar ve gittiği yerde
         * panelin denetiminde değildir. Varsayılan olarak hiçbir rol
         * şablonunda yoktur — yalnızca yönetici geçer.
         */
        'export' => 'Dosyaya Çıkar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Yetkilendirilebilir kaynaklar
    |--------------------------------------------------------------------------
    | Anahtar, user_permissions.resource kolonunda saklanır. Bu bir model sınıfı
    | değil, config anahtarıdır; veritabanında foreign key yoktur.
    */

    /*
     * PANELİN YÖNETİMİ YALNIZCA YÖNETİCİDE.
     *
     * Aşağıdaki kaynaklar panelin KENDİSİNİ yönetir —
     * ayarları, hesapları, etkinlik kaydı. Cihaz ekranları (switchler,
     * sunucular, adresleme, BGP, prefix anonsu) bu listede DEĞİL: onlar
     * işin kendisi, yönetim değil.
     *
     * Açıkken bu kaynakların hiçbir yeteneği yönetici olmayana verilemez —
     * ne rol şablonuyla ne kişisel istisnayla. Karar tek yerde uygulanır:
     * UserPermissionResolver. Yalnızca şablonları boşaltmak yetmezdi; bir
     * kutucuk işaretlenince sessizce geri açılırdı.
     *
     * Roller ve API jetonları zaten yalnızca yöneticide (bkz. routes/web.php).
     *
     * Kapatmak yetki dağıtımını geri açar ama tek başına kimseye yetki
     * VERMEZ: şablonlar boşaltıldı (göç 2026_09_15_230000), yeniden
     * seçilmeleri gerekir.
     */
    'admin_only_management' => (bool) env('ADMIN_ONLY_MANAGEMENT', true),

    'management_resources' => ['settings', 'users', 'logs'],

    'resources' => [

        'switches' => [
            'label' => 'Switchler',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'group' => 'role',
            'search' => ['hostname', 'management_ip', 'uplink_ip'],
            'order' => 'hostname',
            'actions' => ['view', 'create', 'update', 'delete', 'connect', 'configure', 'provision', 'secret', 'rotate', 'export'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_ports' => [
            'label' => 'Portlar',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'update'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_aggregation' => [
            'label' => 'Bağlantı toplama',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_vlans' => [
            'label' => 'VLAN’lar',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_ip_addresses' => [
            'label' => 'IP adresleri',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_routes' => [
            'label' => 'Route’lar',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Spanning tree ayrı yetkidir çünkü etkisi en geniş L2 yazmasıdır:
         * köprü önceliği kök seçimini, "edge" ise tek bir portta anında
         * döngüyü değiştirebilir.
         */
        'switch_rstp' => [
            'label' => 'Spanning tree',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * VoIP (ses) VLAN'ı ayrı yetkidir: telefonun etiketli VLAN'ını
         * değiştirmek bütün bir kattaki telefonları sessizce düşürür ve bu,
         * portun kendi VLAN üyeliğini değiştirmekten farklı bir karardır.
         */
        'switch_voip' => [
            'label' => 'VoIP VLAN',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Port aynalama AYRI bir yetkidir ve spanning tree ile fırtına
         * denetiminin altına konmadı: onlar port KORUMASIDIR, aynalama ise
         * bir teşhis aracı ve aynı zamanda bir DİNLEME yoludur — çıkış
         * portuna erişebilen kişi kaynak portun bütün trafiğini görür.
         * Döngü korumasını yönetme yetkisi, trafiği kopyalama yetkisini
         * İMA ETMEZ.
         */
        'switch_mirroring' => [
            'label' => 'Port aynalama',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * DHCP snooping ve port güvenliği ayrı yetkidir çünkü iki ayarı
         * yanlış vermek KORUMAYI kaldırır: "dhcp-trusted" erişim portuna
         * verildiğinde o porta takılan cihaz ağın DHCP sunucusu gibi
         * davranabilir, "shutdown" eylemi ise portu hizmet dışı bırakır.
         */
        'switch_secure_access' => [
            'label' => 'DHCP snooping',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Filtre tabanlı yönlendirme (FBF): yönlendirme tabloları ve RIB
         * grupları. İkisi TEK kaynaktır çünkü tek başlarına işe yaramazlar —
         * FBF üç parçanın birlikte kurulmasıyla çalışır ve panel onları tek
         * ekranda yönetir.
         */
        'switch_forwarding' => [
            'label' => 'Filtre yönlendirme',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        'switch_prefix_lists' => [
            'label' => 'Prefix listeler',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_firewall' => [
            'label' => 'Firewall',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_policies' => [
            'label' => 'Politikalar',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Cihaz nesnesi: kayıtları SWITCHLERDİR.
         *
         * Yetki switch bazında verilir — "A switchinin portlarını düzenlesin
         * ama VLAN'larına dokunmasın" böyle söylenir. Nesnenin kendi
         * satırları (tek tek portlar) yetki kaydı taşımaz; yüzlerce satırlık
         * bir matris kimsenin yönetemeyeceği bir şey olurdu.
         */
        'switch_bgp' => [
            'label' => 'BGP yapılandırması',
            'model' => NetworkSwitch::class,
            'title' => 'hostname',
            'subtitle' => 'management_ip',
            'search' => ['hostname', 'management_ip'],
            'order' => 'hostname',
            'device_object' => true,
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        'servers' => [
            'label' => 'Sunucular',
            'model' => Server::class,
            'title' => 'short_name',
            'subtitle' => 'ip_address',
            'group' => 'os_type',
            'search' => ['short_name', 'long_name', 'ip_address'],
            'order' => 'short_name',
            'actions' => ['view', 'create', 'update', 'delete', 'connect', 'export', 'rotate'],
        ],

        'addressing' => [
            'label' => 'Adresleme',
            'model' => AddressBlock::class,
            'title' => 'network',
            'subtitle' => 'description',
            'search' => ['network', 'description'],
            // Sıralama ikili aralık sütunudur; IPv6 adresleri de doğru sıralanır.
            'order' => 'range_start',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * BGP kayıtları cihazdan eşitlenir; panelden elle oluşturulmaz.
         * Yazma yolu switchin "configure_bgp" yetkisidir (switch kaynağında,
         * switch başına verilir), bu yüzden burada create/update/delete
         * YOKTUR — olsaydı, aslında hiçbir uca karşılık gelmeyen bir yetki
         * kutusu gösterilirdi.
         */
        'bgp' => [
            'label' => 'BGP',
            'model' => BgpNeighbor::class,
            'title' => 'address',
            'subtitle' => 'peer_asn',
            'search' => ['address', 'peer_asn', 'description'],
            'order' => 'address',
            'actions' => ['view'],
        ],

        'announcements' => [
            'label' => 'Prefix Anonsu',
            'model' => AnnouncementRecipient::class,
            'title' => 'label',
            'subtitle' => 'email',
            'search' => ['label', 'email'],
            'order' => 'label',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        /*
         * Panel ayarları. Kayıtları BÖLÜMLERDİR ve veritabanından değil
         * config'ten gelir (bkz. SettingGroupRecords); kimlikleri
         * config/settings.php içindeki "permission_id" sayılarıdır.
         *
         * Varsayılan KAPALIDIR: hiçbir rol şablonunda yoktur, yalnızca
         * yönetici geçer. Ayar ekranı ölçüm kaynağını, cihaz erişim süresini
         * ve geri alınabilir commit penceresini değiştirir — panelin güvenlik
         * ağını. Bir bölümü açmak bilinçli bir karardır.
         */
        /*
         * GELİŞMİŞ MOD: basit/gelişmiş anahtarını gelişmişe çevirme hakkı.
         * Kişisel tercih değil, rol yetkisi; kimin alacağı yetki ekranında
         * verilir.
         * Kaydı tektir (bkz. ViewModeRecords).
         */
        'gorunum_modu' => [
            'label' => 'Gelişmiş mod',
            'model' => Setting::class,
            'records' => ViewModeRecords::class,
            'title' => 'key',
            'order' => 'id',
            'actions' => ['view'],
        ],

        'settings' => [
            'label' => 'Panel ayarları',
            'model' => Setting::class,
            'records' => SettingGroupRecords::class,
            'title' => 'key',
            'order' => 'id',
            'actions' => ['view', 'update'],
        ],

        'users' => [
            'label' => 'Kullanıcılar',
            'model' => User::class,
            'title' => 'name',
            'subtitle' => 'email',
            'search' => ['name', 'email'],
            'order' => 'name',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],

        'logs' => [
            'label' => 'Etkinlik Kayıtları',
            'model' => ActivityLog::class,
            'title' => 'action',
            'subtitle' => 'description',
            'search' => ['action', 'description'],
            'order' => 'created_at',
            'actions' => ['view'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rol şablonları
    |--------------------------------------------------------------------------
    | Rol tek başına yetki DEĞİLDİR; hazır bir başlangıç kümesidir. Şablon
    | uygulandığında kullanıcının o kaynaklardaki yetkileri buradaki listeye
    | ayarlanır; sonrasında kişiye özel istisnalar yetki ekranından verilir
    | ("şu switchin portlarına dokunsun ama VLAN'larına dokunmasın").
    |
    | Şablon KENDİLİĞİNDEN uygulanmaz. Yeni kullanıcıda bir kez uygulanır,
    | sonrasında yalnızca ekrandaki düğmeyle. Rol değişince sessizce yeniden
    | yazılsaydı, elle ayarlanmış istisnalar kaybolurdu.
    |
    | Yönetici burada yoktur: bütün kontrolleri Gate::before ile geçer.
    */

    'templates' => [

        /*
         * Ağ ekibi: cihazlar üzerinde genel yetkili. Kullanıcı yönetimi ve
         * panel ayarları yöneticide kalır.
         */
        'network' => [
            'gorunum_modu' => ['view'],
            'switches' => ['view', 'create', 'update', 'delete', 'connect', 'configure', 'provision'],
            'switch_ports' => ['view', 'update'],
            'switch_aggregation' => ['view', 'create', 'update', 'delete'],
            'switch_vlans' => ['view', 'create', 'update', 'delete'],
            'switch_ip_addresses' => ['view', 'create', 'update', 'delete'],
            'switch_routes' => ['view', 'create', 'update', 'delete'],
            'switch_rstp' => ['view', 'create', 'update', 'delete'],
            'switch_secure_access' => ['view', 'create', 'update', 'delete'],
            'switch_mirroring' => ['view', 'create', 'update', 'delete'],
            'switch_voip' => ['view', 'create', 'update', 'delete'],
            'switch_forwarding' => ['view', 'create', 'update', 'delete'],
            'switch_prefix_lists' => ['view', 'create', 'update', 'delete'],
            'switch_firewall' => ['view', 'create', 'update', 'delete'],
            'switch_policies' => ['view', 'create', 'update', 'delete'],
            'switch_bgp' => ['view', 'create', 'update', 'delete'],
            'servers' => ['view', 'create', 'update', 'delete', 'connect'],
            'addressing' => ['view', 'create', 'update', 'delete'],
            'bgp' => ['view'],
            'announcements' => ['view', 'create', 'update', 'delete'],
            'logs' => ['view'],
        ],

        /*
         * Teknik ekip: görür ve bağlanır; cihazda YALNIZCA porta müdahale
         * eder. Saha işi budur — kablo takılan portu açmak, açıklamasını
         * düzeltmek. VLAN, route, firewall ve BGP okunur ama yazılmaz.
         */
        'teknik' => [
            'switches' => ['view', 'connect'],
            'switch_ports' => ['view', 'update'],
            'switch_aggregation' => ['view'],
            'switch_vlans' => ['view'],
            'switch_ip_addresses' => ['view'],
            'switch_routes' => ['view'],
            // Spanning tree teknik rolde salt okunurdur: tek bir "edge"
            // yanlış porta verildiğinde döngü anında oluşur.
            'switch_rstp' => ['view'],
            // DHCP snooping teknik rolde salt okunurdur: "dhcp-trusted"
            // yanlış porta verildiğinde snooping'in koruması tamamen kalkar.
            'switch_secure_access' => ['view'],
            /*
             * Aynalama teknik rolde salt okunurdur: bir portun bütün
             * trafiğini başka bir porta kopyalamak saha işi değildir.
             * Görmesi ise gerekir — çıkış portundaki "tuhaf" trafiğin
             * sebebi burada yazar.
             */
            'switch_mirroring' => ['view'],
            // Ses VLAN'ı teknik rolde salt okunurdur: yanlış VLAN bir kattaki
            // bütün telefonları sessizce düşürür.
            'switch_voip' => ['view'],
            'switch_forwarding' => ['view'],
            'switch_prefix_lists' => ['view'],
            'switch_firewall' => ['view'],
            'switch_policies' => ['view'],
            'switch_bgp' => ['view'],
            'servers' => ['view', 'connect'],
            'addressing' => ['view'],
            'bgp' => ['view'],
        ],
    ],
];
