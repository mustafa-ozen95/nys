<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AddressBlockController;
use App\Http\Controllers\Ajax\AddressSlotController;
use App\Http\Controllers\Ajax\BgpReceivedRouteController;
use App\Http\Controllers\Ajax\PortTrafficController;
use App\Http\Controllers\Ajax\RemoteProxyGuardController;
use App\Http\Controllers\Ajax\RemoteSessionController;
use App\Http\Controllers\Ajax\SwitchPasswordController;
use App\Http\Controllers\Ajax\SwitchTargetController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AnnouncementRecipientController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MerkezGirisController;
use App\Http\Controllers\BaglantiCiktisiController;
use App\Http\Controllers\BgpOverviewController;
use App\Http\Controllers\ConfigurationPushController;
use App\Http\Controllers\DeviceOperationController;
use App\Http\Controllers\GuncellemeController;
use App\Http\Controllers\Hesap\ProfilController;
use App\Http\Controllers\NetworkSwitchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PanelModeController;
use App\Http\Controllers\RemoteSessionPageController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SertifikaController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerPasswordController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SwitchArchivalController;
use App\Http\Controllers\SwitchAsPathController;
use App\Http\Controllers\SwitchBgpController;
use App\Http\Controllers\SwitchBgpGroupController;
use App\Http\Controllers\SwitchBgpNeighborController;
use App\Http\Controllers\SwitchCommitController;
use App\Http\Controllers\SwitchCommunityController;
use App\Http\Controllers\SwitchDeviceSettingsController;
use App\Http\Controllers\SwitchFirewallController;
use App\Http\Controllers\SwitchForwardingController;
use App\Http\Controllers\SwitchIpAddressController;
use App\Http\Controllers\SwitchLinkAggregationController;
use App\Http\Controllers\SwitchLoginUserController;
use App\Http\Controllers\SwitchMirroringController;
use App\Http\Controllers\SwitchPasswordRotationController;
use App\Http\Controllers\SwitchPolicyController;
use App\Http\Controllers\SwitchPortController;
use App\Http\Controllers\SwitchPrefixListController;
use App\Http\Controllers\SwitchRouteController;
use App\Http\Controllers\SwitchRstpController;
use App\Http\Controllers\SwitchSecureAccessController;
use App\Http\Controllers\SwitchSettingsController;
use App\Http\Controllers\SwitchSnmpCommunityController;
use App\Http\Controllers\SwitchSnmpIdentityController;
use App\Http\Controllers\SwitchVoipController;
use App\Http\Controllers\SwitchWriteJobController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionController;
use App\Http\Controllers\VeritabaniController;
use App\Http\Controllers\VlanController;
use Illuminate\Support\Facades\Route;

/*
 * Oturumsuz erişilebilen TEK uç giriştir.
 *
 * KENDİ KENDİNE PAROLA SIFIRLAMA YOKTUR ve kayıt olma da yoktur. Bu bir ağ
 * yönetim panelidir: hesabı yönetici açar, parolayı yönetici verir. Sıfırlama
 * akışı e-posta kutusunu hesabın anahtarı hâline getirir — posta kutusuna
 * erişen, switchlere yazma yetkisi olan bir hesabı ele geçirir.
 *
 * Yönetici, kullanıcı ekranından parolayı doğrudan değiştirebilir; kimse
 * kilitli kalmaz (bkz. UserRequest).
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/giris', [LoginController::class, 'create'])->name('login');
    Route::post('/giris', [LoginController::class, 'store']);

    /*
     * Kimlik Yönetim Merkezi ile giriş — İKİNCİ yol, tek yol değil.
     *
     * Parolayla giriş yukarıda yerinde duruyor; merkez erişilemez olduğunda
     * panele girilebilmeli. Kapalı kurulumda (sır yoksa) controller 404 döner,
     * yani rota tanımlı olmakla birlikte hiçbir şey yapmaz.
     *
     * Dönüş ucu GET ve gövdesiz olmak zorunda: kullanıcı merkezden buraya
     * tarayıcı yönlendirmesiyle gelir, POST edemez. CSRF'in yerini oturumda
     * saklanan `state` tutar (bkz. MerkezGirisController).
     */
    Route::get('/giris/merkez', [MerkezGirisController::class, 'baslat'])->name('kimlik.giris');
    Route::get('/giris/merkez/geri', [MerkezGirisController::class, 'geri'])->name('kimlik.donus');
});

/*
 * nginx'in auth_request ile sorduğu bekçi ucu.
 *
 * "auth" ara katmanının dışındadır: o katman kimliksiz isteği login'e
 * yönlendirir, nginx ise 2xx/401/403 dışındaki her yanıtı 500 sayar.
 * Kimlik kontrolü controller içinde yapılır ve 401 döner.
 */
Route::middleware('web')
    ->get('ajax/uzak-oturum/dogrula', RemoteProxyGuardController::class)
    ->name('ajax.remote.guard');

Route::middleware('auth')->group(function (): void {
    Route::post('/cikis', [LoginController::class, 'destroy'])->name('logout');

    /*
     * Panelin ayrıntı düzeyi. Kullanıcı yalnızca KENDİ görünüm tercihini
     * değiştirir; ayrı bir yetki gerekmez.
     */
    Route::post('/gorunum-modu', PanelModeController::class)->name('panel-mode.toggle');

    Route::get('/', OverviewController::class)->name('overview');

    /*
     * Çalışma zamanı ayarları. Yalnızca yönetici; yetki kontrolü
     * SettingController içinde HasMiddleware ile tanımlı.
     */
    /*
     * Cihaz parolalarının toplu değiştirilmesi. Ayar bölümlerinden ÖNCE
     * tanımlanır: "{section}" her şeyi yakalar ve "parolalar" bir ayar
     * bölümü sanılırdı.
     */
    Route::controller(SwitchPasswordRotationController::class)
        ->prefix('ayarlar/parolalar')
        ->name('settings.passwords')
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('durum', 'progress')->name('.progress');

            Route::post('/', 'store')->name('.store');
            Route::put('{switch}', 'update')->name('.update');

            // Elle verilen parola: cihaza yaz ya da yalnızca panele kaydet.
            Route::put('{switch}/elle', 'manual')->name('.manual');

            Route::put('{rotation}/kurtarma', 'repair')->name('.repair');
        });

    /*
     * Roller ve hazır yetkileri. Ayar bölümlerinden ÖNCE tanımlanır:
     * "{section}" her şeyi yakalar ve "roller" bir ayar bölümü sanılırdı.
     */
    Route::controller(RoleController::class)
        ->prefix('ayarlar/roller')
        ->name('settings.roles')
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('durum', 'progress')->name('.progress');

            Route::post('/', 'store')->name('.store');
            Route::get('{role}', 'show')->name('.show');
            Route::put('{role}', 'update')->name('.update');
            Route::delete('{role}', 'destroy')->name('.destroy');
        });

    /*
     * API jetonları. Jeton bir kimlik bilgisidir ve sahibi adına konuşur;
     * çıkarmak yalnızca yöneticidedir.
     */
    Route::controller(ApiTokenController::class)
        ->prefix('ayarlar/api')
        ->name('settings.api')
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('durum', 'progress')->name('.progress');

            Route::post('/', 'store')->name('.store');

            // Belge {apiToken}'dan ÖNCE: yoksa "dokuman" bir uuid sanılırdı.
            Route::get('dokuman', 'documentation')->name('.docs');

            Route::get('{apiToken}', 'show')->name('.show');
            Route::put('{apiToken}', 'update')->name('.update');
            Route::put('{apiToken}/sir', 'rotate')->name('.rotate');
            Route::put('{apiToken}/iptal', 'revoke')->name('.revoke');
            Route::delete('{apiToken}', 'destroy')->name('.destroy');
        });

    Route::controller(SettingController::class)->prefix('ayarlar')->name('settings.')->group(function (): void {
        Route::get('/', 'index')->name('index');

        // Sınama ucu bölüm rotasından ÖNCE tanımlanır; aksi hâlde "sina"
        // bir bölüm adı sanılırdı.
        Route::post('sina', 'test')->name('test');

        /*
         * SERTİFİKA de bölüm rotasından ÖNCE: "sertifika" bir ayar bölümü
         * adı sanılırdı. Tur sunucuda koşar, panel yalnızca istek bırakır
         * (bkz. SertifikaController ve kurulum tarafındaki sertifika ajanı).
         */
        Route::get('sertifika', [SertifikaController::class, 'index'])->name('sertifika');
        Route::post('sertifika/tur', [SertifikaController::class, 'baslat'])->name('sertifika.baslat');
        Route::post('sertifika/onayla', [SertifikaController::class, 'tamamla'])->name('sertifika.tamamla');

        /*
         * VERİTABANI de bölüm rotasından ÖNCE. Panel bağlantıyı sınar ve
         * taşıma isteğini bırakır; kopya yeniden başlatmada, panel kapalıyken
         * yapılır (bkz. VeritabaniController ve BaglantiDosyasi).
         */
        Route::get('veritabani', [VeritabaniController::class, 'index'])->name('veritabani');
        Route::post('veritabani/sina', [VeritabaniController::class, 'sina'])->name('veritabani.sina');
        Route::post('veritabani/tasi', [VeritabaniController::class, 'tasi'])->name('veritabani.tasi');

        /*
         * GÜNCELLEME de bölüm rotasından ÖNCE. Yalnızca bakar: çalışan sürüm
         * GitHub'daki son başarılı derlemeyle karşılaştırılır (bkz. GuncellemeDenetimi).
         */
        Route::get('guncelleme', [GuncellemeController::class, 'index'])->name('guncelleme');
        Route::post('guncelleme', [GuncellemeController::class, 'denetle'])->name('guncelleme.denetle');

        Route::get('{section}', 'show')->name('show');
        Route::put('{section}', 'update')->name('update');
    });

    /*
     * Cihaza tam config yükleme. Yetki hedefe bağlı olduğu için ara katmanda
     * değil controller içinde denenir.
     */
    Route::controller(ConfigurationPushController::class)->prefix('config')->name('provisioning.')->group(function (): void {
        Route::get('/', 'create')->name('create');
        Route::post('/', 'store')->name('store');
    });

    Route::resource('switchler', NetworkSwitchController::class)
        ->parameters(['switchler' => 'switch'])
        ->names('switches')
        // Ekleme ve düzenleme pencerede yapılır, ayrı sayfa yok.
        ->except(['create', 'edit']);

    // Cihaza YAZAN tek panel ucu. Yetkisi "configure", envanteri düzenleyen
    // "update"ten ayrıdır.
    Route::put('switchler/{switch}/snmp-adi', SwitchSnmpIdentityController::class)
        ->name('switches.snmp-identity');

    // Yapılandırma yedeği: hedef adres ayarlardan gelir, cihaza yazılır.
    Route::put('switchler/{switch}/yedek', SwitchArchivalController::class)
        ->name('switches.archival');

    /*
     * Switchin kendi ayarları tek ekranda: SNMP adı ve yapılandırma yedeği.
     * Yazma uçları yukarıdaki kendi rotalarıdır; burası onları toplar.
     */
    /*
     * Cihaz erişim parolasını EKRANDA gösterir. Ayrı yetki ister ("secret"),
     * her gösterim kayda geçer ve adres POST'tur: tarayıcı geçmişine ve ön
     * yüklemeye takılmasın.
     */
    Route::post('switchler/{switch}/parola', SwitchPasswordController::class)
        ->name('switches.password');

    Route::get('switchler/{switch}/ayarlar', SwitchSettingsController::class)
        ->name('switches.settings');

    /*
     * Kuyruğa alınmış yazmanın durumu. Ekran bunu yoklar; yazma artık
     * isteği beklemiyor (bkz. ApplySwitchWriteJob).
     */
    /*
     * Bildirim akışı: olan biten. HERKESE görünür — cihazda olan bir şey,
     * onu başlatan kişinin değil ağın olayıdır (bkz. NotificationFeed).
     */
    Route::get('bildirimler', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('bildirimler/okundu', [NotificationController::class, 'seen'])->name('notifications.seen');

    Route::get('switchler/{switch}/yazma-islemleri/{writeJob}', SwitchWriteJobController::class)
        ->scopeBindings()
        ->name('switches.write-jobs.show');

    Route::get('switchler/{switch}/portlar', [SwitchPortController::class, 'index'])
        ->name('switches.ports');

    Route::put('switchler/{switch}/portlar/{port}', [SwitchPortController::class, 'update'])
        ->scopeBindings()
        ->name('switches.ports.update');

    Route::controller(VlanController::class)->prefix('switchler/{switch}/vlanlar')->name('switches.vlans')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('{vlan}', 'update')->name('.update');
        Route::delete('{vlan}', 'destroy')->name('.destroy');
    });

    Route::controller(SwitchIpAddressController::class)->prefix('switchler/{switch}/ip-adresleri')->name('switches.ip-addresses')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('{ipAddress}', 'update')->name('.update');
        Route::delete('{ipAddress}', 'destroy')->name('.destroy');
    });

    Route::controller(SwitchRouteController::class)->prefix('switchler/{switch}/routelar')->name('switches.routes')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('{route}', 'update')->name('.update');
        Route::delete('{route}', 'destroy')->name('.destroy');
    });

    /*
     * Filtre tabanlı yönlendirme (FBF): tablolar ve RIB grupları TEK ekranda.
     * Üçüncü parça (firewall terimi) kendi ekranında kalır; ayrı yönetilmesi
     * gereken bir nesne değil, oradaki bir eylem.
     */
    Route::controller(SwitchForwardingController::class)->prefix('switchler/{switch}/filtre-yonlendirme')->name('switches.forwarding')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');

        Route::post('tablolar', 'storeInstance')->name('.instances.store');
        Route::put('tablolar/{routingInstance}', 'updateInstance')->name('.instances.update');
        Route::delete('tablolar/{routingInstance}', 'destroyInstance')->name('.instances.destroy');

        Route::post('gruplar', 'storeGroup')->name('.groups.store');
        Route::put('gruplar/{ribGroup}', 'updateGroup')->name('.groups.update');
        Route::delete('gruplar/{ribGroup}', 'destroyGroup')->name('.groups.destroy');

        // Arayüz yollarının hangi gruba kopyalanacağı: FBF'nin sessiz parçası.
        Route::put('baglama', 'bind')->name('.bind');
    });

    Route::controller(SwitchPrefixListController::class)->prefix('switchler/{switch}/prefix-listeler')->name('switches.prefix-lists')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        // Listenin kendi ayarları: apply-path ifadeleri.
        Route::put('{prefixList}', 'update')->name('.update');
        Route::delete('{prefixList}', 'destroy')->name('.destroy');
        Route::post('{prefixList}/prefix', 'storeEntry')->name('.entries.store');
        Route::delete('{prefixList}/prefix/{entry}', 'destroyEntry')->name('.entries.destroy');
    });

    /*
     * Firewall filtreleri. YAZMA yalnızca yöneticidedir; görüntüleme "view"
     * ile açıktır. {firewallFilter} firewallFilters() ilişkisi üzerinden
     * çözülür: başka switchin filtresi eşleşmez.
     */
    Route::controller(SwitchFirewallController::class)->prefix('switchler/{switch}/firewall')->name('switches.firewall')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');

        /*
         * Policer uçları {firewallFilter}'den ÖNCE tanımlanır: "policer"
         * sabit bir segmenttir, filtre uuid'si sanılmamalı. Parametre adı
         * firewallPolicer'dır çünkü scopeBindings ilişkiyi addan türetir
         * (firewallPolicers()).
         */
        Route::post('policer', 'storePolicer')->name('.policers.store');
        Route::put('policer/{firewallPolicer}', 'updatePolicer')->name('.policers.update');
        Route::delete('policer/{firewallPolicer}', 'destroyPolicer')->name('.policers.destroy');

        Route::put('{firewallFilter}', 'update')->name('.update');
        Route::delete('{firewallFilter}', 'destroy')->name('.destroy');
        Route::post('{firewallFilter}/terim', 'storeTerm')->name('.terms.store');
        Route::put('{firewallFilter}/terim/{term}', 'updateTerm')->name('.terms.update');
        Route::put('{firewallFilter}/siralama', 'reorderTerms')->name('.terms.order');
        Route::delete('{firewallFilter}/terim/{term}', 'destroyTerm')->name('.terms.destroy');
    });

    /*
     * Bağlantı toplama. ae arayüzleri panelde YARATILMAZ: chassis'in
     * device-count kadarını oluşturmasıyla var olurlar, bu yüzden "store" ucu
     * yoktur. {aggregate} parametresi aggregates() ilişkisi üzerinden çözülür;
     * fiziksel bir portun ya da başka switchin uuid'si eşleşmez.
     */
    Route::controller(SwitchLinkAggregationController::class)->prefix('switchler/{switch}/toplama')->name('switches.aggregation')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        // "limit" {aggregate}'ten ÖNCE tanımlanır, yoksa uuid sanılır.
        Route::put('limit', 'deviceCount')->name('.device-count');
        Route::put('{aggregate}', 'update')->name('.update');
        Route::delete('{aggregate}', 'destroy')->name('.destroy');
    });

    /*
     * Politikalar ve community'ler. BGP grubunun import/export alanı
     * buradaki adlara atıfta bulunur; tanımsız ada atıf commit'i düşürür.
     */
    Route::controller(SwitchPolicyController::class)->prefix('switchler/{switch}/politikalar')->name('switches.policies')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::delete('{policyStatement}', 'destroy')->name('.destroy');
        Route::post('{policyStatement}/terim', 'storeTerm')->name('.terms.store');
        Route::put('{policyStatement}/terim/{term}', 'updateTerm')->name('.terms.update');
        Route::put('{policyStatement}/siralama', 'reorderTerms')->name('.terms.order');

        /*
         * Politikanın KENDİ from/then'i: terimlerden sonra gelen varsayılan
         * davranış ("then reject;"). Terimlerden ayrı bir uçtur ama aynı
         * yazma yolunu kullanır — politika silinip yeniden yazılır.
         */
        Route::put('{policyStatement}/varsayilan', 'updateDefaults')->name('.defaults');
        Route::delete('{policyStatement}/terim/{term}', 'destroyTerm')->name('.terms.destroy');
    });

    /*
     * Cihazın kendi ayarları: adı, saat, isim/zaman/günlük sunucuları ve
     * SNMP community'leri. Hepsi cihaza yazar, hepsi "configure" ister.
     */
    Route::put('switchler/{switch}/cihaz-ayarlari', SwitchDeviceSettingsController::class)
        ->name('switches.device-settings');

    /*
     * Cihaz saatini NTP ile HEMEN düzeltir. Yapılandırma değişmediği için
     * yazma kuyruğundan geçmez (bkz. DeviceClockSynchronizer).
     */
    Route::post('switchler/{switch}/cihaz-ayarlari/saat', [SwitchDeviceSettingsController::class, 'clock'])
        ->name('switches.device-settings.clock');

    Route::controller(SwitchSnmpCommunityController::class)
        ->prefix('switchler/{switch}/snmp-community')
        ->name('switches.snmp-communities')
        ->scopeBindings()
        ->group(function (): void {
            Route::post('/', 'store')->name('.store');
            /*
             * Parametre adı "snmpCommunity"dir, "community" DEĞİL:
             * scopeBindings üst ilişkiyi parametre adından türetiyor ve
             * switchte zaten bir communities() ilişkisi var — o BGP
             * community'leridir. Ad çakıştığında bağlama yanlış ilişkiye
             * gidiyor ve kayıt hiç bulunamıyordu (404).
             */
            Route::put('{snmpCommunity}', 'update')->name('.update');
            Route::delete('{snmpCommunity}', 'destroy')->name('.destroy');
        });

    /*
     * Cihaz üzerindeki yerel giriş hesapları. Panel kullanıcılarından
     * ayrıdır: buradan silinen hesap cihazda kapanır, paneldeki hesabın
     * silinmesi ise cihazdakine dokunmaz.
     */
    Route::controller(SwitchLoginUserController::class)
        ->prefix('switchler/{switch}/cihaz-hesaplari')
        ->name('switches.login-users')
        ->scopeBindings()
        ->group(function (): void {
            Route::post('/', 'store')->name('.store');
            Route::put('{loginUser}', 'update')->name('.update');
            Route::delete('{loginUser}', 'destroy')->name('.destroy');
        });

    /*
     * Spanning tree. Ayrı yetkidir: köprü önceliği kök seçimini, "edge" tek
     * bir portta döngüyü değiştirebilir.
     */
    Route::controller(SwitchRstpController::class)
        ->prefix('switchler/{switch}/spanning-tree')
        ->name('switches.rstp')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::put('kopru', 'bridge')->name('.bridge');
            Route::post('arayuz', 'store')->name('.store');
            Route::put('arayuz/{rstpInterface}', 'update')->name('.update');
            Route::delete('arayuz/{rstpInterface}', 'destroy')->name('.destroy');

            /*
             * Fırtına denetimi aynı ekranda: ikisi de port korumasıdır ve
             * aynı yetkiye bağlıdır (yanlış eşik portu kullanılamaz yapar).
             */
            Route::put('firtina', 'stormShutdown')->name('.storm.shutdown');
            Route::post('firtina/arayuz', 'stormStore')->name('.storm.store');
            Route::put('firtina/arayuz/{stormControl}', 'stormUpdate')->name('.storm.update');
            Route::delete('firtina/arayuz/{stormControl}', 'stormDestroy')->name('.storm.destroy');
        });

    /*
     * Port aynalama. Spanning tree ve fırtına denetimi port KORUMASIDIR;
     * aynalama bir teşhis aracıdır ve aynı zamanda bir dinleme yoludur:
     * çıkış portuna erişebilen kişi kaynak portun bütün trafiğini görür.
     * Bu yüzden kendi ekranı ve kendi yetkisi vardır.
     *
     * scopeBindings: analyzer üst switch üzerinden çözülür; başka switchin
     * kaydı eşleşmez.
     */
    Route::controller(SwitchMirroringController::class)
        ->prefix('switchler/{switch}/port-aynalama')
        ->name('switches.mirroring')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('durum', 'progress')->name('.progress');

            Route::post('/', 'store')->name('.store');
            Route::put('{analyzer}', 'update')->name('.update');
            Route::delete('{analyzer}', 'destroy')->name('.destroy');
        });

    /*
     * VoIP (ses) VLAN'ı. Ayrı yetkidir: telefonun etiketli VLAN'ını
     * değiştirmek bütün bir kat için telefonları sessizce düşürür ve bu,
     * portun kendi VLAN'ını değiştirmekten farklı bir karardır.
     *
     * Parametre adı "voipInterface"dır: scopeBindings üst ilişkiyi parametre
     * adından türetir ve switchte voipInterfaces() ilişkisi vardır.
     */
    Route::controller(SwitchVoipController::class)
        ->prefix('switchler/{switch}/voip')
        ->name('switches.voip')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::post('arayuz', 'store')->name('.store');
            Route::put('arayuz/{voipInterface}', 'update')->name('.update');
            Route::delete('arayuz/{voipInterface}', 'destroy')->name('.destroy');
        });

    /*
     * DHCP snooping ve port güvenliği (secure-access-port). Kendi yetkisidir:
     * "dhcp-trusted" yanlış porta verildiğinde snooping'in koruması tamamen
     * kalkar, "shutdown" eylemi ise portu hizmet dışı bırakır.
     *
     * Rota parametreleri ilişki adlarından türer ({secureVlan} ->
     * secureVlans()); scopeBindings ancak böyle çalışır ve alt kaydın üst
     * kayda ait olmasını ZORUNLU kılar.
     */
    Route::controller(SwitchSecureAccessController::class)
        ->prefix('switchler/{switch}/dhcp-snooping')
        ->name('switches.secure-access')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::post('vlan', 'vlanStore')->name('.vlan.store');
            Route::put('vlan/{secureVlan}', 'vlanUpdate')->name('.vlan.update');
            Route::delete('vlan/{secureVlan}', 'vlanDestroy')->name('.vlan.destroy');
            Route::post('arayuz', 'interfaceStore')->name('.interface.store');
            Route::put('arayuz/{secureInterface}', 'interfaceUpdate')->name('.interface.update');
            Route::delete('arayuz/{secureInterface}', 'interfaceDestroy')->name('.interface.destroy');
        });

    Route::controller(SwitchCommunityController::class)->prefix('switchler/{switch}/community')->name('switches.communities')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{community}', 'update')->name('.update');
        Route::delete('{community}', 'destroy')->name('.destroy');
    });

    /*
     * policy-options as-path tanımları. Politika terimi "from as-path <ad>"
     * ile bunlara atıfta bulunur; community'de olduğu gibi tanımsız ada atıf
     * commit'i düşürür.
     */
    Route::controller(SwitchAsPathController::class)->prefix('switchler/{switch}/as-path')->name('switches.as-paths')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{asPath}', 'update')->name('.update');
        Route::delete('{asPath}', 'destroy')->name('.destroy');
    });

    /*
     * BGP. Rota parametreleri ilişki adlarından türer ({bgpGroup} ->
     * bgpGroups()); scopeBindings ancak böyle çalışır ve alt kaydın üst kayda
     * ait olmasını ZORUNLU kılar. Bunsuz, A switchinde yetkisi olan biri B
     * switchinin grubunu değiştirebilirdi.
     */
    Route::controller(SwitchBgpController::class)->prefix('switchler/{switch}/bgp')->name('switches.bgp')->group(function (): void {
        Route::get('/', 'index');
        Route::put('as-numarasi', 'autonomousSystem')->name('.autonomous-system');
    });

    Route::controller(SwitchBgpGroupController::class)->prefix('switchler/{switch}/bgp/gruplar')->name('switches.bgp.groups')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{bgpGroup}', 'update')->name('.update');
        Route::delete('{bgpGroup}', 'destroy')->name('.destroy');
    });

    Route::controller(SwitchBgpNeighborController::class)->prefix('switchler/{switch}/bgp/gruplar/{bgpGroup}/komsular')->name('switches.bgp.neighbors')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{neighbor}', 'update')->name('.update');
        Route::delete('{neighbor}', 'destroy')->name('.destroy');
    });

    /*
     * Commit geçmişi ve geri alma. scopeBindings: commit üst switch üzerinden
     * çözülür, başka switchin commit'i eşleşmez.
     */
    Route::controller(SwitchCommitController::class)
        ->prefix('switchler/{switch}/commitler')
        ->name('switches.commits')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::put('{commit}/fark', 'preview')->name('.preview');
            Route::put('{commit}/geri-al', 'rollback')->name('.rollback');
        });

    Route::get('ajax/switchler/{switch}/portlar/{port}/trafik', PortTrafficController::class)
        ->scopeBindings()
        ->name('ajax.port.traffic');

    /*
     * Bağlantı bilgilerinin Excel çıktısı.
     *
     * Sunucular ve switchler için ORTAK: ikisinin bağlantı bilgisi aynı beş
     * sorudan ibaret ve iki ayrı uç, aynı kuralı iki yerde tutmak olurdu.
     *
     * Yol 'sunucular/{server}' kalıbıyla çakışmasın diye ondan ÖNCE ve sabit
     * bir önekte; sonra tanımlansaydı "cikti" bir sunucu uuid'si sanılırdı.
     */
    Route::post('cikti/baglanti', BaglantiCiktisiController::class)->name('export.connections');

    /*
     * Sunucu parolası değiştirme.
     *
     * Sabit yollar resource rotasından ÖNCE: sonra tanımlansaydı "parola" bir
     * sunucu uuid'si sanılırdı.
     */
    Route::controller(ServerPasswordController::class)->group(function (): void {
        Route::get('sunucular/parola/durum', 'durum')->name('servers.password.progress');
        Route::post('sunucular/parola/toplu', 'toplu')->name('servers.password.bulk');
        Route::post('sunucular/{server}/parola', 'dondur')->name('servers.password');
        Route::post('parola-turu/{rotation}/onar', 'onar')->name('servers.password.repair');
    });

    Route::resource('sunucular', ServerController::class)
        ->parameters(['sunucular' => 'server'])
        ->names('servers')
        ->except('show');

    Route::controller(AddressBlockController::class)->prefix('adresleme')->name('addressing.')->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('{block}/alt-ag', 'storeSubnet')->name('subnets.store');
        Route::put('{block}', 'update')->name('update');
        Route::delete('{block}', 'destroy')->name('destroy');
    });

    Route::get('ajax/adresleme/{block}/yuvalar', AddressSlotController::class)->name('ajax.addressing.slots');

    Route::get('ajax/switchler/{switch}/hedefler', SwitchTargetController::class)->name('ajax.switches.targets');

    /*
     * Komşudan alınan prefixler. Cihaza gidilir, bu yüzden sayfa yüklenirken
     * değil kullanıcı istediğinde çağrılır. {bgpNeighbor} bgpNeighbors()
     * ilişkisi üzerinden çözülür: başka switchin komşusu eşleşmez.
     */
    Route::get('ajax/switchler/{switch}/bgp/{bgpNeighbor}/alinan', BgpReceivedRouteController::class)
        ->scopeBindings()
        ->name('ajax.bgp.received');

    // Salt okunur genel bakış; yönetim switchin kendi BGP ekranındadır.
    Route::get('bgp', BgpOverviewController::class)->name('bgp.index');

    Route::get('uzak-oturum/{token}', RemoteSessionPageController::class)->name('remote.session');

    Route::prefix('ajax/uzak-oturum')->name('ajax.remote.')->group(function (): void {
        Route::post('baglan', [RemoteSessionController::class, 'connect'])->name('connect');
        Route::post('canli-tut', [RemoteSessionController::class, 'keepAlive'])->name('keep-alive');
        Route::post('kapat', [RemoteSessionController::class, 'disconnect'])->name('disconnect');
    });

    Route::resource('kullanicilar', UserController::class)
        ->parameters(['kullanicilar' => 'user'])
        ->names('users')
        ->except('show');

    Route::controller(AnnouncementController::class)->prefix('prefix-anonsu')->name('announcements.')->group(function (): void {
        Route::get('/', 'create')->name('create');
        Route::post('onizle', 'preview')->name('preview');
        Route::post('gonder', 'send')->name('send');
    });

    Route::controller(AnnouncementRecipientController::class)
        ->prefix('prefix-anonsu/alicilar')
        ->name('announcements.recipients.')
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{recipient}', 'update')->name('update');
            Route::delete('{recipient}', 'destroy')->name('destroy');
        });

    /*
     * KAYITLAR: iki ekran, iki ayrı soru.
     *
     *   etkinlik-kayitlari — kim ne yaptı (panelin kendi kaydı)
     *   hata-kayitlari     — cihaz ne dedi (reddettiği, uyardığı işlemler)
     *
     * İkisi de "kayitlar" altında durur: adres yapısı menüdeki adı yansıtır
     * ve hangisine bakıldığı adresten okunur. Yetki ikisinde de aynı
     * kaynaktan ("logs") gelir.
     */
    Route::get('kayitlar/etkinlik-kayitlari', ActivityLogController::class)->name('logs.index');

    Route::get('kayitlar/hata-kayitlari', DeviceOperationController::class)
        ->name('logs.device-operations');

    /*
     * Eski adresler ölü bırakılmaz; yer imleri çalışmaya devam eder.
     *
     * "/kayitlar" ETKİNLİK kayıtlarıydı ve oraya gider: eski bir yer imini
     * başka bir ekrana açmak, sessizce yanlış sayfayı göstermek olurdu.
     */
    Route::get('kayitlar', fn () => redirect()->route('logs.index', [], 301));
    Route::get('kayitlar/cihaz-islemleri', fn () => redirect()->route('logs.device-operations', [], 301));
    Route::get('hata-kayitlari', fn () => redirect()->route('logs.device-operations', [], 301));

    Route::get('kullanicilar/{user}/yetkiler', [UserPermissionController::class, 'show'])
        ->name('users.permissions');

    // Rolün hazır şablonunu yeniden uygular; kişiye özel istisnaları
    // ezdiği için ayrı ve açık bir eylemdir.

    Route::put('kullanicilar/{user}/yetkiler', [UserPermissionController::class, 'update'])
        ->name('users.permissions.update');
});

/*
 * Hesabım: herkes yalnızca KENDİ hesabını düzenler.
 *
 * Rol ve yetkiler burada değişmez; onlar yöneticinin ekranında durur.
 */
Route::middleware('auth')->prefix('hesap')->name('hesap.')->group(function (): void {
    Route::get('profil', [ProfilController::class, 'index'])->name('profil');
    Route::put('profil', [ProfilController::class, 'update'])->name('profil.kaydet');
    Route::put('parola', [ProfilController::class, 'parola'])->name('parola');
});
