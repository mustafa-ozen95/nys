<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Jobs\SyncSwitchNetconfJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Prefix listenin TAMAMI panelden yönetilebilir.
 *
 * Yalnız statik girdiler değil, apply-path ile doldurulan liste de
 * modellenir. Modellenmeseydi kullanıcı ifadeyi ne görebilir ne de
 * düzeltebilirdi; üstelik böyle bir liste ekranda BOŞ görünür ve "hiçbir
 * trafik çıkamaz" uyarısı olmayan bir tehlikeyi haber verirdi.
 *
 * Bu testler üç şeyi kilitler: ifade cihaza yazılır, liste silinip yeniden
 * yazılmaz (yani panelin görmediği hiçbir satır kaybolmaz) ve IPv6 girdi
 * kabul edilir.
 */
final class PrefixListFullManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_path_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'radius']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), [
                'apply_paths' => ['system radius-server <*>'],
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        /*
         * apply-path bir listedir: Junos'ta liste değiştirmek "replace" ile
         * çalışmaz, merge üstüne ekler. Önce silinir, sonra yazılır; ikisi
         * TEK commit'te.
         */
        $this->assertStringContainsString('<apply-path delete="delete"/>', $payload);
        $this->assertStringContainsString('<apply-path>system radius-server &lt;*&gt;</apply-path>', $payload);
    }

    /**
     * Ekleme değil DEĞİŞTİRME: eski ifade silinmezse cihazda ikisi birden
     * kalır ve liste beklenenden geniş dolar.
     */
    public function test_ifade_degistirildiginde_eskisi_silinir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'radius',
            'apply_paths' => ['system radius-server <*>'],
        ]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), [
                'apply_paths' => ['system tacplus-server <*>'],
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<apply-path delete="delete"/>', $payload);
        $this->assertStringContainsString('<apply-path>system tacplus-server &lt;*&gt;</apply-path>', $payload);
        $this->assertStringNotContainsString('radius-server', $payload);
    }

    public function test_ifadelerin_hepsi_kaldirilabilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'radius',
            'apply_paths' => ['system radius-server <*>'],
        ]);
        SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '10.0.0.0', 'prefix_length' => 8]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), ['apply_paths' => []])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<apply-path delete="delete"/>', $payload);
        $this->assertStringNotContainsString('<apply-path>', $payload);
    }

    /**
     * Tekrarlanan alanda son satır silinmez, BOŞALTILIR (route-form.js).
     * Boş satır doğrulamaya girseydi "ifadeyi kaldır" işlemi "bu alan gerekli"
     * hatasıyla döner ve liste hiç boşaltılamazdı.
     */
    public function test_bos_satir_gonderilirse_ifade_kaldirilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'radius',
            'apply_paths' => ['system radius-server <*>'],
        ]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), ['apply_paths' => ['']])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<apply-path delete="delete"/>', $payload);
        $this->assertStringNotContainsString('<apply-path>', $payload);
    }

    /**
     * EN ÖNEMLİSİ: liste SİLİNİP YENİDEN YAZILMAZ.
     *
     * Kapsayıcı yeniden kurulsaydı panelin tanımadığı satırlar (apply-groups)
     * ve panelin henüz eşitlemediği girdiler o anda kaybolurdu — kapatmaya
     * çalıştığımız hatanın ta kendisi.
     */
    public function test_ayar_yazarken_liste_silinip_yeniden_yazilmaz(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'radius',
            'residual_xml' => ['<apply-groups>ortak</apply-groups>'],
        ]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), [
                'apply_paths' => ['system radius-server <*>'],
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringNotContainsString('<prefix-list delete="delete"', $payload);
        $this->assertStringNotContainsString('replace="replace"', $payload);
        $this->assertStringNotContainsString('prefix-list-item delete', $payload);
    }

    /**
     * Cihazın sözdizimi kuralı formda önce söylenir; commit check'e kadar
     * gitmek kullanıcıya bir şey anlatmaz.
     */
    public function test_kapanmamis_joker_reddedilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'radius']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), [
                'apply_paths' => ['system radius-server <*'],
            ])
            ->assertSessionHasErrors('apply_paths');

        $this->assertNull($device->connection);
    }

    /**
     * Çıkış bekçisi listeyi boşaltmak, o uplinkten geçen bütün trafiği
     * düşürmektir. Statik girdisi olmayan dinamik bekçi listesinin son
     * ifadesi ONAYSIZ kaldırılamaz.
     */
    public function test_bos_kalacak_bekci_listesi_onaysiz_bosaltilmaz(): void
    {
        $switch = $this->switch();
        $list = $this->guardList($switch);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), ['apply_paths' => []])
            ->assertSessionHasErrors('apply_paths');

        $this->assertNull($device->connection);
    }

    /**
     * Ama YASAK da değildir: cihaz kabul ediyor, panelin işi yönetmeyi mümkün
     * kılmak. Kullanıcı ne olacağını okuyup onaylarsa istek cihaza gider.
     */
    public function test_onaylanirsa_bekci_listesi_bosaltilabilir(): void
    {
        $switch = $this->switch();
        $list = $this->guardList($switch);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), [
                'apply_paths' => [],
                'acknowledge_empty' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<apply-path delete="delete"/>', $device->payload());
    }

    /**
     * Statik prefixi olan bekçi listesi boşalmaz; onay da sorulmaz.
     */
    public function test_statik_prefixi_olan_bekci_listesinde_onay_istenmez(): void
    {
        $switch = $this->switch();
        $list = $this->guardList($switch);
        SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '192.0.2.0', 'prefix_length' => 24]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), ['apply_paths' => []])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<apply-path delete="delete"/>', $device->payload());
    }

    /**
     * Cihaz IPv6 girdiyi kabul ediyor (test1, commit check). Panelin
     * doğrulaması ip2long'a bağlı olsaydı burada sessizce düşerdi.
     */
    public function test_ipv6_prefix_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'v6']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '2001:db8::/32',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<prefix-list-item><name>2001:db8::/32</name></prefix-list-item>',
            $device->payload(),
        );
    }

    /**
     * IPv6 adresinin tek bir yazımı yoktur. Hizalama metin karşılaştırmasıyla
     * yapılsaydı geçerli bir ağ "maskeye hizalı değil" diye reddedilirdi.
     */
    public function test_ipv6_farkli_yazimi_kabul_edilir_ve_kanoniklesir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'v6']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '2001:0DB8:0000::/32',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<prefix-list-item><name>2001:db8::/32</name></prefix-list-item>',
            $device->payload(),
        );
    }

    public function test_ayni_ipv6_prefix_baska_yazimla_iki_kez_eklenemez(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'v6']);
        SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '2001:db8::', 'prefix_length' => 32]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '2001:0db8:0000::/32',
            ])
            ->assertSessionHasErrors('cidr');

        $this->assertNull($device->connection);
    }

    public function test_hizasiz_ipv6_ag_reddedilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'v6']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '2001:db8::5/32',
            ])
            ->assertSessionHasErrors('cidr');

        $this->assertNull($device->connection);
    }

    /**
     * Cihazdan okunan dinamik liste artık YÖNETİLEBİLİR: ifade envantere
     * girer, "salt okunur" işareti düşmez.
     */
    public function test_dinamik_liste_envantere_yonetilebilir_yazilir(): void
    {
        $switch = $this->switch();

        $this->app->instance(NetconfTransport::class, new FakeJunosDevice(
            policyOptions: $this->policyOptions(),
        ));

        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $list = $switch->prefixLists()->where('name', 'radius')->sole();

        $this->assertSame(['system radius-server <*>'], $list->apply_paths);
        $this->assertSame(['<apply-groups>ortak</apply-groups>'], $list->residual_xml);
        $this->assertTrue($list->is_manageable, 'Liste artık salt okunur ilan edilmiyor.');
        $this->assertTrue($list->is_dynamic);
        $this->assertSame(['apply-groups'], $list->preserved_lines);
    }

    /**
     * apply-path cihaza YAZAR: görüntüleme yetkisi yetmez.
     */
    public function test_goruntuleme_yetkisi_apply_path_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'radius']);
        $device = $this->fakeDevice();

        $izleyici = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($izleyici, 'switches', ['view']);
        $this->grantDeviceView($izleyici);

        $this->actingAs($izleyici->fresh())
            ->put(route('switches.prefix-lists.update', [$switch, $list]), [
                'apply_paths' => ['system radius-server <*>'],
            ])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * Ekran "salt okunur" demek yerine ifadenin KENDİSİNİ gösterir ve boş
     * liste tehlikesini dinamik listede iddia ETMEZ: girdiler cihazda üretilir,
     * yapılandırmada yazılı değildir.
     */
    public function test_dinamik_liste_ekranda_ifadeyi_gosterir(): void
    {
        $switch = $this->switch();
        SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'radius',
            'apply_paths' => ['system radius-server <*>'],
            'egress_interfaces' => ['ge-0/0/0'],
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.prefix-lists', $switch))
            ->assertOk()
            ->assertSee('system radius-server <*>')
            ->assertSee('Dinamik doldurma')
            ->assertDontSee('Bu liste BOŞ');
    }

    /**
     * Panelin göstermediği satırlar ekranda SAYILIR; kullanıcı listenin
     * tamamını görmediğini bilmeli.
     */
    public function test_korunan_satirlar_ekranda_sayilir(): void
    {
        $switch = $this->switch();
        SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'karma',
            'residual_xml' => ['<apply-groups>ortak</apply-groups>'],
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.prefix-lists', $switch))
            ->assertOk()
            ->assertSee('panelin göstermediği 1 satır')
            ->assertSee('apply-groups');
    }

    private function policyOptions(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<policy-options><prefix-list><name>radius</name>'
            .'<apply-path>system radius-server &lt;*&gt;</apply-path>'
            .'<apply-groups>ortak</apply-groups>'
            .'</prefix-list></policy-options>'
            .'</configuration></rpc-reply>'."\n]]>]]>";
    }

    /**
     * Uplink çıkışını koruyan, yalnızca dinamik doldurulan liste.
     */
    private function guardList(NetworkSwitch $switch): SwitchPrefixList
    {
        return SwitchPrefixList::factory()->for($switch, 'switch')->create([
            'name' => 'koru',
            'apply_paths' => ['system radius-server <*>'],
            'egress_interfaces' => ['ge-0/0/0'],
        ]);
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.20.195',
            'last_sync_at' => null,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit policy-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
