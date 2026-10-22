<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordAuthenticationActivity;
use App\Listeners\WriteLogin;
use App\Models\ConsoleSession;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Announcement\Transport\IrrdTransport;
use App\Services\Announcement\Transport\WhoisIrrdTransport;
use App\Services\Api\ApiTokenContext;
use App\Services\Api\ApiTokenGuard;
use App\Services\Api\TokenAbilityGate;
use App\Services\Authorization\DeviceObjectAbilities;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\RolePermissions;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use App\Services\Netconf\Transport\SshSubsystemTransport;
use App\Services\Zabbix\ZabbixItemReader;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Yetki satırları istek ömrü boyunca önbelleklenir; tekil olmalı.
        $this->app->singleton(UserPermissionResolver::class);

        /* Rolün canlı yetkisi de istek boyunca tek yerden okunur. */
        $this->app->singleton(RolePermissions::class);
        $this->app->singleton(PermissionRegistry::class);

        /*
         * Item okuyucu istek boyunca tekildir: toplu okuduğunu hatırlaması
         * ancak böyle işe yarar. Hatırlama isteğe özgüdür, paylaşılan bir
         * önbellek değildir.
         */
        $this->app->singleton(ZabbixItemReader::class);

        // NETCONF taşıması tekil DEĞİLDİR: her oturum kendi bağlantısını açar.
        // Testler bu bağı sahte taşımayla değiştirir; üretim sınıfı testte
        // asla gerçek bağlantı açmamalı (bkz. .ai/rules/services.md).
        $this->app->bind(NetconfTransport::class, SshSubsystemTransport::class);

        // IRR sorgusu da aynı sebeple arayüz üzerinden bağlanır: anons
        // testleri whois.radb.net'e gerçek soket açmamalı.
        $this->app->bind(IrrdTransport::class, WhoisIrrdTransport::class);

        // İsteği yapan jeton istek boyunca tektir; yetki daraltması onu okur.
        $this->app->singleton(ApiTokenContext::class);
    }

    public function boot(): void
    {
        // Eski sınıf adları etkinlik kaydında (subject_type/konu_type) yazılı; zincir özeti de bu
        // dizgiye bağlı. Takma ad eski adı korur, yeni adlı model eski satırları çözer.
        Relation::morphMap([
            'App\Models\KonsolOturumu' => ConsoleSession::class,
        ]);

        Auth::viaRequest('api-token', $this->app->make(ApiTokenGuard::class));

        /*
         * SIRA ÖNEMLİDİR. Jeton daraltması yönetici geçişinden ÖNCE
         * çalışmalı: yöneticinin jetonu da yalnızca kendi listesindeki
         * yetkileri kullanabilsin. Ters sırada, yöneticiye ait bir jeton
         * bütün kontrolleri geçerdi.
         */
        Gate::before(fn (User $user, string $ability, array $arguments = []): ?bool => $this->app
            ->make(TokenAbilityGate::class)
            ->check($user, $ability, $arguments));

        Gate::before($this->adminBypass(...));

        $this->registerDeviceObjectGates();

        /*
         * Envanterde OLMAYAN bir cihaza config yükleme.
         *
         * Kayıt bazlı yetki verilemez — ortada kayıt yoktur — bu yüzden
         * yöneticiye özeldir. Kural controller içinde değil burada durur.
         */
        Gate::define('provisionExternalDevice', fn (User $user): bool => $user->isAdmin());

        /*
         * Rol yönetimi yalnızca yöneticidedir: rol yetkisi verebilen biri
         * kendine her şeyi verebilir. Ayarların bölüm bölüm devredilebilmesi
         * bunu KAPSAMAZ.
         */
        Gate::define('manageRoles', fn (User $user): bool => $user->isAdmin());

        /*
         * API jetonu çıkarmak yalnızca yöneticidedir: jeton sahibi adına
         * konuşur ve başkası adına jeton çıkarmak, o kişinin yetkisini
         * kalıcı bir anahtara dönüştürmektir.
         */
        Gate::define('manageApiTokens', fn (User $user): bool => $user->isAdmin());

        /*
         * Sertifika turu yalnızca yöneticidedir: sunucuda bir işlem başlatıyor
         * ve sonucu aynı sertifikayı paylaşan bütün panelleri etkileyebilir.
         * Ayarların bölüm bölüm devredilmesi bunu kapsamaz.
         */
        Gate::define('manageCertificate', fn (User $user): bool => $user->isAdmin());

        /*
         * Veritabanı taşıması yalnızca yöneticidedir: konteyneri yeniden
         * başlatıyor ve panelin bütün verisini başka bir sunucuya
         * kopyalıyor. Ayarların bölüm bölüm devredilmesi bunu kapsamaz.
         */
        Gate::define('manageDatabase', fn (User $user): bool => $user->isAdmin());

        /*
         * Güncelleme kontrolü yalnızca bakar ama sonucu güncellemeyi başlatacak
         * kişiyi ilgilendirir: yönetici. Ayar bölümleriyle devredilmez.
         */
        Gate::define('manageUpdate', fn (User $user): bool => $user->isAdmin());

        /*
         * Cihaz parolaları ekranı: en az bir switchte parolayı görme ya da
         * değiştirme yetkisi olan girer. Yetki switch başınadır; ekran
         * yalnızca kişinin yetkili olduğu switchleri listeler.
         */
        Gate::define('viewDevicePasswords', function (User $user): bool {
            $resolver = $this->app->make(UserPermissionResolver::class);

            return $resolver->allowsAny($user, 'switches', 'secret')
                || $resolver->allowsAny($user, 'switches', 'rotate');
        });

        /*
         * Hız sınırı JETON başınadır, IP başına değil: aynı IP'den gelen iki
         * jeton birbirini yavaşlatmamalı. Jetonsuz istek adrese göre
         * sınırlanır — kimliksiz istek zaten reddedilecek.
         */
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $this->app->make(ApiTokenContext::class)->get();

            return Limit::perMinute((int) config('api.rate_limit'))
                ->by($token?->uuid ?? $request->ip());
        });

        Event::listen(Login::class, [RecordAuthenticationActivity::class, 'handleLogin']);

        /* Ortak çıkış için oturumun açılış anı (bkz. WriteLogin). */
        Event::listen(Login::class, WriteLogin::class);
        Event::listen(Logout::class, [RecordAuthenticationActivity::class, 'handleLogout']);
        Event::listen(Failed::class, [RecordAuthenticationActivity::class, 'handleFailed']);
        Event::listen(Lockout::class, [RecordAuthenticationActivity::class, 'handleLockout']);

        // Sayfalama kendi tasarım dilimizle basılır; çerçevenin Tailwind
        // şablonu bu projede kullanılmaz.
        Paginator::defaultView('vendor.pagination.default');
        Paginator::defaultSimpleView('vendor.pagination.default');

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
    }

    /**
     * Cihaz nesnelerinin yetenekleri config'ten üretilir.
     *
     * Her nesne (port, VLAN, route, firewall...) kendi kaynağıdır ve
     * kayıtları switchlerdir: yetki switch bazında verilir. Yazma
     * yetenekleri ayrıca NETCONF kimliği ister — yazma yolu olmayan
     * switchte yönetici de yazamaz, düğme de gösterilmez.
     */
    private function registerDeviceObjectGates(): void
    {
        $abilities = $this->app->make(DeviceObjectAbilities::class);

        foreach ($abilities->map() as $ability => [$resource, $action]) {
            Gate::define(
                $ability,
                function (User $user, NetworkSwitch $switch) use ($resource, $action): bool {
                    if (in_array($action, DeviceObjectAbilities::WRITE_ACTIONS, true)
                        && $switch->netconfCredential === null) {
                        return false;
                    }

                    return $user->isAdmin()
                        || $this->app->make(UserPermissionResolver::class)
                            ->allows($user, $resource, $action, $switch->id);
                },
            );
        }
    }

    /**
     * Yönetici tüm yetki kontrollerini geçer.
     *
     * İki istisna kararı policy'ye bırakır:
     *
     * - Kendi hesabını silmek bir izin meselesi değil, değişmez bir kuraldır
     *   (son yönetici silinirse panele kimse giremez).
     * - "connect" ve "configure" yalnızca izin değil, olgusal
     *   koşul da içerir: kayıtlı kimlik bilgisi ve erişilebilir hedef. Bypass
     *   edilirse yönetici, bağlantı kurulamayacak bir hedefte düğme görür ve
     *   tıklayınca hata alır. Aynı şey cihaz nesnelerinin yazma yetenekleri
     *   için de geçerlidir.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function adminBypass(User $user, string $ability, array $arguments = []): ?bool
    {
        $target = $arguments[0] ?? null;

        if ($ability === 'delete' && $target instanceof User && $user->is($target)) {
            return null;
        }

        if (in_array($ability, ['connect', 'configure', 'provision'], true)) {
            return null;
        }

        /*
         * Cihaz nesnesi yetenekleri de bypass edilmez: yazma yolu olmayan
         * switchte yönetici de yazamamalı ve düğme gösterilmemeli. Karar
         * yeteneğin kendi kapanışında verilir.
         */
        if (array_key_exists($ability, $this->app->make(DeviceObjectAbilities::class)->map())) {
            return null;
        }

        return $user->isAdmin() ? true : null;
    }
}
