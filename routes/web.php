<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ProfileController;
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
use App\Http\Controllers\Auth\CenterLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BgpOverviewController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ConfigurationPushController;
use App\Http\Controllers\ConnectionOutputController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DeviceOperationController;
use App\Http\Controllers\NetworkSwitchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PanelModeController;
use App\Http\Controllers\RemoteSessionPageController;
use App\Http\Controllers\RoleController;
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
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionController;
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
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    /*
     * Kimlik Yönetim Merkezi ile giriş — İKİNCİ yol, tek yol değil.
     *
     * Parolayla giriş yukarıda yerinde duruyor; merkez erişilemez olduğunda
     * panele girilebilmeli. Kapalı kurulumda (sır yoksa) controller 404 döner,
     * yani rota tanımlı olmakla birlikte hiçbir şey yapmaz.
     *
     * Dönüş ucu GET ve gövdesiz olmak zorunda: kullanıcı merkezden buraya
     * tarayıcı yönlendirmesiyle gelir, POST edemez. CSRF'in yerini oturumda
     * saklanan `state` tutar (bkz. CenterLoginController).
     */
    Route::get('/login/center', [CenterLoginController::class, 'start'])->name('identity.login');
    Route::get('/login/center/callback', [CenterLoginController::class, 'back'])->name('identity.callback');
});

/*
 * nginx'in auth_request ile sorduğu bekçi ucu.
 *
 * "auth" ara katmanının dışındadır: o katman kimliksiz isteği login'e
 * yönlendirir, nginx ise 2xx/401/403 dışındaki her yanıtı 500 sayar.
 * Kimlik kontrolü controller içinde yapılır ve 401 döner.
 */
Route::middleware('web')
    ->get('ajax/remote-session/verify', RemoteProxyGuardController::class)
    ->name('ajax.remote.guard');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    /*
     * Panelin ayrıntı düzeyi. Kullanıcı yalnızca KENDİ görünüm tercihini
     * değiştirir; ayrı bir yetki gerekmez.
     */
    Route::post('/view-mode', PanelModeController::class)->name('panel-mode.toggle');

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
        ->prefix('settings/passwords')
        ->name('settings.passwords')
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('status', 'progress')->name('.progress');

            Route::post('/', 'store')->name('.store');
            Route::put('{switch}', 'update')->name('.update');

            // Elle verilen parola: cihaza yaz ya da yalnızca panele kaydet.
            Route::put('{switch}/manual', 'manual')->name('.manual');

            Route::put('{rotation}/recovery', 'repair')->name('.repair');
        });

    /*
     * Roller ve hazır yetkileri. Ayar bölümlerinden ÖNCE tanımlanır:
     * "{section}" her şeyi yakalar ve "roller" bir ayar bölümü sanılırdı.
     */
    Route::controller(RoleController::class)
        ->prefix('settings/roles')
        ->name('settings.roles')
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('status', 'progress')->name('.progress');

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
        ->prefix('settings/api')
        ->name('settings.api')
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('status', 'progress')->name('.progress');

            Route::post('/', 'store')->name('.store');

            // Belge {apiToken}'dan ÖNCE: yoksa "dokuman" bir uuid sanılırdı.
            Route::get('docs', 'documentation')->name('.docs');

            Route::get('{apiToken}', 'show')->name('.show');
            Route::put('{apiToken}', 'update')->name('.update');
            Route::put('{apiToken}/secret', 'rotate')->name('.rotate');
            Route::put('{apiToken}/revoke', 'revoke')->name('.revoke');
            Route::delete('{apiToken}', 'destroy')->name('.destroy');
        });

    Route::controller(SettingController::class)->prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', 'index')->name('index');

        // Sınama ucu bölüm rotasından ÖNCE tanımlanır; aksi hâlde "sina"
        // bir bölüm adı sanılırdı.
        Route::post('test', 'test')->name('test');

        /*
         * SERTİFİKA de bölüm rotasından ÖNCE: "sertifika" bir ayar bölümü
         * adı sanılırdı. Tur sunucuda koşar, panel yalnızca istek bırakır
         * (bkz. CertificateController ve kurulum tarafındaki sertifika ajanı).
         */
        Route::get('certificate', [CertificateController::class, 'index'])->name('certificate');
        Route::post('certificate/start', [CertificateController::class, 'start'])->name('certificate.start');
        Route::post('certificate/confirm', [CertificateController::class, 'complete'])->name('certificate.confirm');

        /*
         * VERİTABANI de bölüm rotasından ÖNCE. Panel bağlantıyı sınar ve
         * taşıma isteğini bırakır; kopya yeniden başlatmada, panel kapalıyken
         * yapılır (bkz. DatabaseController ve ConnectionFile).
         */
        Route::get('database', [DatabaseController::class, 'index'])->name('database');
        Route::post('database/test', [DatabaseController::class, 'sina'])->name('database.test');
        Route::post('database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');

        /*
         * GÜNCELLEME de bölüm rotasından ÖNCE. Yalnızca bakar: çalışan sürüm
         * GitHub'daki son başarılı derlemeyle karşılaştırılır (bkz. UpdateCheck).
         */
        Route::get('update', [UpdateController::class, 'index'])->name('updates');
        Route::post('update', [UpdateController::class, 'check'])->name('updates.check');

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

    Route::resource('switches', NetworkSwitchController::class)
        ->parameters(['switches' => 'switch'])
        ->names('switches')
        // Ekleme ve düzenleme pencerede yapılır, ayrı sayfa yok.
        ->except(['create', 'edit']);

    // Cihaza YAZAN tek panel ucu. Yetkisi "configure", envanteri düzenleyen
    // "update"ten ayrıdır.
    Route::put('switches/{switch}/snmp-name', SwitchSnmpIdentityController::class)
        ->name('switches.snmp-identity');

    // Yapılandırma yedeği: hedef adres ayarlardan gelir, cihaza yazılır.
    Route::put('switches/{switch}/backup', SwitchArchivalController::class)
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
    Route::post('switches/{switch}/password', SwitchPasswordController::class)
        ->name('switches.password');

    Route::get('switches/{switch}/settings', SwitchSettingsController::class)
        ->name('switches.settings');

    /*
     * Kuyruğa alınmış yazmanın durumu. Ekran bunu yoklar; yazma artık
     * isteği beklemiyor (bkz. ApplySwitchWriteJob).
     */
    /*
     * Bildirim akışı: olan biten. HERKESE görünür — cihazda olan bir şey,
     * onu başlatan kişinin değil ağın olayıdır (bkz. NotificationFeed).
     */
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/seen', [NotificationController::class, 'seen'])->name('notifications.seen');

    Route::get('switches/{switch}/write-jobs/{writeJob}', SwitchWriteJobController::class)
        ->scopeBindings()
        ->name('switches.write-jobs.show');

    Route::get('switches/{switch}/ports', [SwitchPortController::class, 'index'])
        ->name('switches.ports');

    Route::put('switches/{switch}/ports/{port}', [SwitchPortController::class, 'update'])
        ->scopeBindings()
        ->name('switches.ports.update');

    Route::controller(VlanController::class)->prefix('switches/{switch}/vlans')->name('switches.vlans')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('{vlan}', 'update')->name('.update');
        Route::delete('{vlan}', 'destroy')->name('.destroy');
    });

    Route::controller(SwitchIpAddressController::class)->prefix('switches/{switch}/ip-addresses')->name('switches.ip-addresses')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('{ipAddress}', 'update')->name('.update');
        Route::delete('{ipAddress}', 'destroy')->name('.destroy');
    });

    Route::controller(SwitchRouteController::class)->prefix('switches/{switch}/routes')->name('switches.routes')->scopeBindings()->group(function (): void {
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
    Route::controller(SwitchForwardingController::class)->prefix('switches/{switch}/filter-forwarding')->name('switches.forwarding')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');

        Route::post('tables', 'storeInstance')->name('.instances.store');
        Route::put('tables/{routingInstance}', 'updateInstance')->name('.instances.update');
        Route::delete('tables/{routingInstance}', 'destroyInstance')->name('.instances.destroy');

        Route::post('groups', 'storeGroup')->name('.groups.store');
        Route::put('groups/{ribGroup}', 'updateGroup')->name('.groups.update');
        Route::delete('groups/{ribGroup}', 'destroyGroup')->name('.groups.destroy');

        // Arayüz yollarının hangi gruba kopyalanacağı: FBF'nin sessiz parçası.
        Route::put('binding', 'bind')->name('.bind');
    });

    Route::controller(SwitchPrefixListController::class)->prefix('switches/{switch}/prefix-lists')->name('switches.prefix-lists')->scopeBindings()->group(function (): void {
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
    Route::controller(SwitchFirewallController::class)->prefix('switches/{switch}/firewall')->name('switches.firewall')->scopeBindings()->group(function (): void {
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
        Route::post('{firewallFilter}/term', 'storeTerm')->name('.terms.store');
        Route::put('{firewallFilter}/term/{term}', 'updateTerm')->name('.terms.update');
        Route::put('{firewallFilter}/order', 'reorderTerms')->name('.terms.order');
        Route::delete('{firewallFilter}/term/{term}', 'destroyTerm')->name('.terms.destroy');
    });

    /*
     * Bağlantı toplama. ae arayüzleri panelde YARATILMAZ: chassis'in
     * device-count kadarını oluşturmasıyla var olurlar, bu yüzden "store" ucu
     * yoktur. {aggregate} parametresi aggregates() ilişkisi üzerinden çözülür;
     * fiziksel bir portun ya da başka switchin uuid'si eşleşmez.
     */
    Route::controller(SwitchLinkAggregationController::class)->prefix('switches/{switch}/aggregation')->name('switches.aggregation')->scopeBindings()->group(function (): void {
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
    Route::controller(SwitchPolicyController::class)->prefix('switches/{switch}/policies')->name('switches.policies')->scopeBindings()->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::delete('{policyStatement}', 'destroy')->name('.destroy');
        Route::post('{policyStatement}/term', 'storeTerm')->name('.terms.store');
        Route::put('{policyStatement}/term/{term}', 'updateTerm')->name('.terms.update');
        Route::put('{policyStatement}/order', 'reorderTerms')->name('.terms.order');

        /*
         * Politikanın KENDİ from/then'i: terimlerden sonra gelen varsayılan
         * davranış ("then reject;"). Terimlerden ayrı bir uçtur ama aynı
         * yazma yolunu kullanır — politika silinip yeniden yazılır.
         */
        Route::put('{policyStatement}/defaults', 'updateDefaults')->name('.defaults');
        Route::delete('{policyStatement}/term/{term}', 'destroyTerm')->name('.terms.destroy');
    });

    /*
     * Cihazın kendi ayarları: adı, saat, isim/zaman/günlük sunucuları ve
     * SNMP community'leri. Hepsi cihaza yazar, hepsi "configure" ister.
     */
    Route::put('switches/{switch}/device-settings', SwitchDeviceSettingsController::class)
        ->name('switches.device-settings');

    /*
     * Cihaz saatini NTP ile HEMEN düzeltir. Yapılandırma değişmediği için
     * yazma kuyruğundan geçmez (bkz. DeviceClockSynchronizer).
     */
    Route::post('switches/{switch}/device-settings/clock', [SwitchDeviceSettingsController::class, 'clock'])
        ->name('switches.device-settings.clock');

    Route::controller(SwitchSnmpCommunityController::class)
        ->prefix('switches/{switch}/snmp-community')
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
        ->prefix('switches/{switch}/device-accounts')
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
        ->prefix('switches/{switch}/spanning-tree')
        ->name('switches.rstp')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::put('kopru', 'bridge')->name('.bridge');
            Route::post('interface', 'store')->name('.store');
            Route::put('interface/{rstpInterface}', 'update')->name('.update');
            Route::delete('interface/{rstpInterface}', 'destroy')->name('.destroy');

            /*
             * Fırtına denetimi aynı ekranda: ikisi de port korumasıdır ve
             * aynı yetkiye bağlıdır (yanlış eşik portu kullanılamaz yapar).
             */
            Route::put('storm', 'stormShutdown')->name('.storm.shutdown');
            Route::post('storm/interface', 'stormStore')->name('.storm.store');
            Route::put('storm/interface/{stormControl}', 'stormUpdate')->name('.storm.update');
            Route::delete('storm/interface/{stormControl}', 'stormDestroy')->name('.storm.destroy');
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
        ->prefix('switches/{switch}/port-mirroring')
        ->name('switches.mirroring')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');

            /*
             * Süren turların aşaması — ekrandaki ilerleme çubuğu bunu yoklar.
             * GET: hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
             */
            Route::get('status', 'progress')->name('.progress');

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
        ->prefix('switches/{switch}/voip')
        ->name('switches.voip')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::post('interface', 'store')->name('.store');
            Route::put('interface/{voipInterface}', 'update')->name('.update');
            Route::delete('interface/{voipInterface}', 'destroy')->name('.destroy');
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
        ->prefix('switches/{switch}/dhcp-snooping')
        ->name('switches.secure-access')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::post('vlan', 'vlanStore')->name('.vlan.store');
            Route::put('vlan/{secureVlan}', 'vlanUpdate')->name('.vlan.update');
            Route::delete('vlan/{secureVlan}', 'vlanDestroy')->name('.vlan.destroy');
            Route::post('interface', 'interfaceStore')->name('.interface.store');
            Route::put('interface/{secureInterface}', 'interfaceUpdate')->name('.interface.update');
            Route::delete('interface/{secureInterface}', 'interfaceDestroy')->name('.interface.destroy');
        });

    Route::controller(SwitchCommunityController::class)->prefix('switches/{switch}/community')->name('switches.communities')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{community}', 'update')->name('.update');
        Route::delete('{community}', 'destroy')->name('.destroy');
    });

    /*
     * policy-options as-path tanımları. Politika terimi "from as-path <ad>"
     * ile bunlara atıfta bulunur; community'de olduğu gibi tanımsız ada atıf
     * commit'i düşürür.
     */
    Route::controller(SwitchAsPathController::class)->prefix('switches/{switch}/as-path')->name('switches.as-paths')->scopeBindings()->group(function (): void {
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
    Route::controller(SwitchBgpController::class)->prefix('switches/{switch}/bgp')->name('switches.bgp')->group(function (): void {
        Route::get('/', 'index');
        Route::put('as-number', 'autonomousSystem')->name('.autonomous-system');
    });

    Route::controller(SwitchBgpGroupController::class)->prefix('switches/{switch}/bgp/groups')->name('switches.bgp.groups')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{bgpGroup}', 'update')->name('.update');
        Route::delete('{bgpGroup}', 'destroy')->name('.destroy');
    });

    Route::controller(SwitchBgpNeighborController::class)->prefix('switches/{switch}/bgp/groups/{bgpGroup}/neighbors')->name('switches.bgp.neighbors')->scopeBindings()->group(function (): void {
        Route::post('/', 'store')->name('.store');
        Route::put('{neighbor}', 'update')->name('.update');
        Route::delete('{neighbor}', 'destroy')->name('.destroy');
    });

    /*
     * Commit geçmişi ve geri alma. scopeBindings: commit üst switch üzerinden
     * çözülür, başka switchin commit'i eşleşmez.
     */
    Route::controller(SwitchCommitController::class)
        ->prefix('switches/{switch}/commits')
        ->name('switches.commits')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', 'index')->name('');
            Route::put('{commit}/diff', 'preview')->name('.preview');
            Route::put('{commit}/rollback', 'rollback')->name('.rollback');
        });

    Route::get('ajax/switches/{switch}/ports/{port}/traffic', PortTrafficController::class)
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
    Route::post('export/connections', ConnectionOutputController::class)->name('export.connections');

    /*
     * Sunucu parolası değiştirme.
     *
     * Sabit yollar resource rotasından ÖNCE: sonra tanımlansaydı "parola" bir
     * sunucu uuid'si sanılırdı.
     */
    Route::controller(ServerPasswordController::class)->group(function (): void {
        Route::get('servers/password/status', 'status')->name('servers.password.progress');
        Route::post('servers/password/bulk', 'bulk')->name('servers.password.bulk');
        Route::post('servers/{server}/password', 'rotate')->name('servers.password');
        Route::post('password-rounds/{rotation}/repair', 'repair')->name('servers.password.repair');
    });

    Route::resource('servers', ServerController::class)
        ->parameters(['servers' => 'server'])
        ->names('servers')
        ->except('show');

    Route::controller(AddressBlockController::class)->prefix('addressing')->name('addressing.')->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('{block}/subnets', 'storeSubnet')->name('subnets.store');
        Route::put('{block}', 'update')->name('update');
        Route::delete('{block}', 'destroy')->name('destroy');
    });

    Route::get('ajax/addressing/{block}/slots', AddressSlotController::class)->name('ajax.addressing.slots');

    Route::get('ajax/switches/{switch}/targets', SwitchTargetController::class)->name('ajax.switches.targets');

    /*
     * Komşudan alınan prefixler. Cihaza gidilir, bu yüzden sayfa yüklenirken
     * değil kullanıcı istediğinde çağrılır. {bgpNeighbor} bgpNeighbors()
     * ilişkisi üzerinden çözülür: başka switchin komşusu eşleşmez.
     */
    Route::get('ajax/switches/{switch}/bgp/{bgpNeighbor}/received', BgpReceivedRouteController::class)
        ->scopeBindings()
        ->name('ajax.bgp.received');

    // Salt okunur genel bakış; yönetim switchin kendi BGP ekranındadır.
    Route::get('bgp', BgpOverviewController::class)->name('bgp.index');

    Route::get('remote-session/{token}', RemoteSessionPageController::class)->name('remote.session');

    Route::prefix('ajax/remote-session')->name('ajax.remote.')->group(function (): void {
        Route::post('connect', [RemoteSessionController::class, 'connect'])->name('connect');
        Route::post('keep-alive', [RemoteSessionController::class, 'keepAlive'])->name('keep-alive');
        Route::post('disconnect', [RemoteSessionController::class, 'disconnect'])->name('disconnect');
    });

    Route::resource('users', UserController::class)
        ->parameters(['users' => 'user'])
        ->names('users')
        ->except('show');

    Route::controller(AnnouncementController::class)->prefix('prefix-announcement')->name('announcements.')->group(function (): void {
        Route::get('/', 'create')->name('create');
        Route::post('preview', 'preview')->name('preview');
        Route::post('send', 'send')->name('send');
    });

    Route::controller(AnnouncementRecipientController::class)
        ->prefix('prefix-announcement/recipients')
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
     *   logs/activity — kim ne yaptı (panelin kendi kaydı)
     *   logs/device-operations — cihaz ne dedi (reddettiği, uyardığı işlemler)
     *
     * İkisi de "logs" altında durur: adres yapısı menüdeki adı yansıtır
     * ve hangisine bakıldığı adresten okunur. Yetki ikisinde de aynı
     * kaynaktan ("logs") gelir.
     */
    Route::get('logs/activity', ActivityLogController::class)->name('logs.index');

    Route::get('logs/device-operations', DeviceOperationController::class)
        ->name('logs.device-operations');

    Route::get('users/{user}/permissions', [UserPermissionController::class, 'show'])
        ->name('users.permissions');

    // Rolün hazır şablonunu yeniden uygular; kişiye özel istisnaları
    // ezdiği için ayrı ve açık bir eylemdir.

    Route::put('users/{user}/permissions', [UserPermissionController::class, 'update'])
        ->name('users.permissions.update');
});

/*
 * Hesabım: herkes yalnızca KENDİ hesabını düzenler.
 *
 * Rol ve yetkiler burada değişmez; onlar yöneticinin ekranında durur.
 */
Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('password', [ProfileController::class, 'password'])->name('password');
});
