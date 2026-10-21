<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Middleware\RequireActionReason;
use App\Models\User;
use App\Services\Announcement\Transport\IrrdTransport;
use App\Services\Authorization\DeviceObjectAbilities;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeIrrdTransport;

abstract class TestCase extends BaseTestCase
{
    /**
     * Gerekçe zorunluluğu bu testte de geçerli olsun mu.
     *
     * Varsayılan KAPALIDIR. Kural her yazma ucunu ilgilendiren yatay bir
     * kuraldır; her teste gerekçe eklemek testleri okunmaz yapar ve
     * hiçbirini daha güvenilir kılmaz. Kuralın kendisi
     * ActionReasonTest içinde, açıkça açılarak sınanır.
     */
    protected bool $requiresActionReason = false;

    /**
     * YETKİ DAĞITIMINI AÇAR — üretimde panelin yönetimi yalnızca yöneticide.
     *
     * Ayarlar, kullanıcılar ve etkinlik kaydı yönetici olmayana verilemez
     * (bkz. config/permissions.php · yonetim_yalnizca_yonetici). Düzenek
     * silinmedi, kapatıldı: rol şablonu, kişisel izin/yasak ve yetki
     * ekranları yerinde duruyor.
     *
     * Kapalı diye SINANMAZSA sessizce çürür ve yeniden açıldığı gün kimse
     * güvenemez. Bu yüzden düzeneği sınayan sınıflar ayarı açıyor; kapalı
     * hâlin kendisi YonetimYalnizcaYoneticiTest'te sınanıyor.
     */
    protected function yetkiDagitimiAc(): void
    {
        config(['permissions.yonetim_yalnizca_yonetici' => false]);
    }

    /**
     * Aynı sınamada kullanıcı değiştirilebilir (önce yetkisiz, sonra yönetici).
     *
     * Panel oturumdaki parola özetini kullanıcınınkiyle karşılaştırır
     * (AuthenticateSession); sınama tek oturum nesnesini yeniden kullandığı
     * için önceki kullanıcının özeti kalırsa yeni kullanıcı "parolası değişmiş"
     * sayılıp atılırdı. Gerçekte her kullanıcının kendi oturumu vardır.
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        $this->app['session.store']->forget('password_hash_'.($guard ?? config('auth.defaults.guard')));

        return parent::actingAs($user, $guard);
    }

    /**
     * Anons yetki denetiminin IRR yanıtlarını hazırlayan sahte taşıma.
     */
    protected FakeIrrdTransport $irrd;

    protected function setUp(): void
    {
        parent::setUp();

        // Testler dış dünyaya çıkmaz: sahtelenmemiş her istek hata verir.
        Http::preventStrayRequests();

        /*
         * IRR sorgusu HTTP değil, 43. porta ham sokettir; preventStrayRequests
         * onu görmez. Sahte taşıma varsayılan olarak bağlanır ve hazırlanmamış
         * bir prefix sorulduğunda patlar.
         */
        $this->irrd = new FakeIrrdTransport;
        $this->app->instance(IrrdTransport::class, $this->irrd);

        if (! $this->requiresActionReason) {
            $this->withoutMiddleware(RequireActionReason::class);
        }
    }

    /**
     * Bütün cihaz nesnelerini GÖREBİLEN kullanıcı.
     *
     * Port, VLAN, route, firewall gibi her nesnenin kendi görüntüleme
     * yetkisi var; ekranların çoğu "switchi görüyor" varsayımıyla yazıldı ve
     * bu yardımcı o varsayımı tek satırda kurar.
     */
    protected function grantDeviceView(User $user): void
    {
        $this->grantDeviceObjects($user, ['view']);
    }

    /**
     * Bütün cihaz nesnelerine YAZABİLEN kullanıcı.
     */
    protected function grantDeviceWrite(User $user): void
    {
        $this->grantDeviceObjects($user, ['view', 'create', 'update', 'delete']);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grantDeviceObjects(User $user, array $actions): void
    {
        $abilities = app(DeviceObjectAbilities::class);
        $registry = app(PermissionRegistry::class);
        $resolver = app(UserPermissionResolver::class);

        foreach ($abilities->resources() as $resource) {
            // Kaynağın desteklemediği aksiyon verilmez (portta "oluştur" yok).
            $resolver->syncResource(
                $user,
                $resource,
                array_values(array_intersect($actions, $registry->actionsFor($resource))),
            );
        }
    }

    /**
     * item.get yanıtı: istenen her host için fixture item'ları, hostid ile
     * damgalanmış hâlde.
     *
     * Gerçek API de böyle davranır — tek çağrıda birden çok host istenebilir
     * ve her satır hangi hosta ait olduğunu söyler. Panelin toplu okuması
     * buna dayanır.
     *
     * @param  list<string>  $hostIds
     * @return array<string, mixed>
     */
    protected function zabbixItems(array $hostIds, string $fixture): array
    {
        $items = [];

        foreach ($hostIds as $hostId) {
            foreach ($this->zabbixFixture($fixture)['result'] as $item) {
                $items[] = ['hostid' => $hostId, ...$item];
            }
        }

        return ['jsonrpc' => '2.0', 'result' => $items, 'id' => 1];
    }

    /**
     * Zabbix yanıt örneğini dosyadan okur.
     *
     * @return array<string, mixed>
     */
    protected function zabbixFixture(string $name): array
    {
        return json_decode(
            file_get_contents(__DIR__."/Fixtures/zabbix/{$name}.json"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
