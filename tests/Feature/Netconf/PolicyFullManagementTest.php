<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\PolicyAction;
use App\Models\NetworkSwitch;
use App\Models\SwitchAsPath;
use App\Models\SwitchCommunity;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Politikanın TAMAMI panelden yönetilebilir.
 *
 * Yalnız prefix-list-filter + accept/reject/community-delete değil, geri
 * kalan koşul ve eylemler de modellenir; hiçbiri politikayı "salt okunur"
 * yapmaz. Hepsi cihazca yazılabilir; test1'de commit check ile ölçüldü. Bu testler
 * üretilen XML'i kilitliyor.
 */
final class PolicyFullManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * EN ÖNEMLİSİ: politikanın kendi "then reject;" satırı.
     *
     * Terimlerden sonra gelir ve bütün BGP süzgeçlerinin standart
     * kapanışıdır: "listedekileri al, GERİSİNİ REDDET".
     */
    public function test_politikanin_varsayilan_eylemi_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'gelen',
                'name' => 'A',
                'action' => 'accept',
                'default_then' => ['action' => 'reject'],
            ])
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        // Varsayılan eylem TERİMLERDEN SONRA gelmeli; sıra Junos'ta anlamlı.
        $this->assertStringContainsString('<term><name>A</name><then><accept/></then></term><then><reject/></then>', $written);
    }

    /**
     * REGRESYON: terim eklemek varsayılan eylemi SİLMEMELİ.
     *
     * Politika silinip yeniden yazıldığı için, envanterdeki varsayılan eylem
     * taşınmazsa politika "gerisini reddet"ten "gerisini de al"a döner.
     */
    public function test_terim_eklemek_varsayilan_eylemi_silmez(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create([
            'name' => 'gelen',
            'default_then' => ['action' => 'reject'],
        ]);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create(['name' => 'A', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.terms.store', [$switch, $policy]), [
                'name' => 'B', 'action' => 'accept',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<then><reject/></then></policy-statement>', $device->payload());
    }

    /**
     * Varsayılan davranış kendi ucundan da düzenlenir; terimler değişmez.
     */
    public function test_varsayilan_davranis_ayri_uctan_guncellenir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'gelen']);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create(['name' => 'A', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.policies.defaults', [$switch, $policy]), [
                'default_match' => ['protocols' => 'direct'],
                'default_then' => ['action' => 'accept'],
            ])
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<term><name>A</name>', $written);
        $this->assertStringContainsString('<from><protocol>direct</protocol></from>', $written);
        $this->assertStringContainsString('<then><accept/></then></policy-statement>', $written);
    }

    /**
     * route-filter: değersiz tür boş eleman, değerli tür metin taşır.
     */
    public function test_route_filter_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'suzgec',
                'name' => 'A',
                'action' => 'accept',
                'match' => [
                    'route_filters' => [
                        ['address' => '10.0.0.0/8', 'match_type' => 'orlonger', 'value' => ''],
                        ['address' => '172.16.0.0/12', 'match_type' => 'prefix-length-range', 'value' => '/24-/32'],
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString(
            '<route-filter><address>10.0.0.0/8</address><orlonger/></route-filter>',
            $written,
        );
        $this->assertStringContainsString(
            '<route-filter><address>172.16.0.0/12</address><prefix-length-range>/24-/32</prefix-length-range></route-filter>',
            $written,
        );
    }

    /**
     * upto/through/prefix-length-range bir DEĞER ister; cihaz değersizini
     * reddediyor, panel önce söylüyor.
     */
    public function test_degersiz_upto_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'A', 'action' => 'accept',
                'match' => ['route_filters' => [['address' => '10.0.0.0/8', 'match_type' => 'upto', 'value' => '']]],
            ])
            ->assertSessionHasErrors('match.route_filters.0.value');

        $this->assertNull($device->connection);
    }

    /**
     * Ailesi uyuşmayan değer: IPv4 adres, yalnızca inet6'ya bakan terimde
     * hiçbir zaman eşleşmez.
     */
    public function test_ailesi_uyusmayan_route_filter_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'A', 'action' => 'accept',
                'match' => [
                    'families' => 'inet6',
                    'route_filters' => [['address' => '10.0.0.0/8', 'match_type' => 'orlonger', 'value' => '']],
                ],
            ])
            ->assertSessionHasErrors('match.route_filters.0.address');

        $this->assertNull($device->connection);
    }

    /**
     * BGP nitelikleri: local-preference, metric, as-path-prepend, community
     * add. Hepsi cihazda ölçüldü (test1, commit check).
     */
    public function test_bgp_nitelikleri_yazilir(): void
    {
        $switch = $this->switch();
        SwitchCommunity::factory()->for($switch, 'switch')->create(['name' => 'topluluk-a']);
        SwitchAsPath::factory()->for($switch, 'switch')->create(['name' => 'musteri1-den']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'nitelik',
                'name' => 'A',
                'action' => 'accept',
                'match' => ['as_paths' => ['musteri1-den'], 'protocols' => 'bgp', 'neighbors' => '198.18.20.1'],
                'then' => [
                    'local_preference' => ['operation' => 'set', 'value' => 200],
                    'metric' => ['operation' => 'add', 'value' => 10],
                    'as_path_prepend' => '65000 65000',
                    'communities' => [['operation' => 'add', 'name' => 'topluluk-a']],
                ],
            ])
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<as-path>musteri1-den</as-path>', $written);
        $this->assertStringContainsString('<protocol>bgp</protocol>', $written);
        $this->assertStringContainsString('<neighbor>198.18.20.1</neighbor>', $written);
        $this->assertStringContainsString(
            '<local-preference><local-preference>200</local-preference></local-preference>',
            $written,
        );
        $this->assertStringContainsString('<metric><add/><metric>10</metric></metric>', $written);
        $this->assertStringContainsString('<as-path-prepend>65000 65000</as-path-prepend>', $written);
        $this->assertStringContainsString(
            '<community><add/><community-name>topluluk-a</community-name></community>',
            $written,
        );
    }

    /**
     * Junos'ta "next" bir ARGÜMAN alır: `then next policy;`.
     */
    public function test_next_policy_argumanla_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'devret', 'name' => 'A', 'action' => 'next-policy',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<then><next>policy</next></then>', $device->payload());
    }

    /**
     * Aynı community'ye tek terimde birden çok işlem uygulanamaz.
     */
    public function test_celisen_community_islemleri_reddedilir(): void
    {
        $switch = $this->switch();
        SwitchCommunity::factory()->for($switch, 'switch')->create(['name' => 'topluluk-a']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'A', 'action' => 'accept',
                'then' => ['communities' => [
                    ['operation' => 'add', 'name' => 'topluluk-a'],
                    ['operation' => 'delete', 'name' => 'topluluk-a'],
                ]],
            ])
            ->assertSessionHasErrors('then.communities');

        $this->assertNull($device->connection);
    }

    /**
     * EN ÖNEMLİSİ (2): panelin göstermediği satır düzenlemede KAYBOLMAZ.
     *
     * Böyle bir politika düzenlenebilir ve o satırlar cihaza olduğu gibi geri
     * yazılır — politikanın kendi altındakiler de, terimin from/then'inin
     * içindekiler de.
     */
    public function test_korunan_ham_satirlar_geri_yazilir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create([
            'name' => 'karmasik',
            'residual_xml' => ['<uydurma-kapsayici>x</uydurma-kapsayici>'],
        ]);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create([
            'name' => 'A',
            'position' => 0,
            'match_conditions' => ['residual' => ['<uydurma-kosul>y</uydurma-kosul>']],
            'then_actions' => ['residual' => ['<uydurma-eylem>z</uydurma-eylem>']],
            'residual_xml' => ['<uydurma-terim-alani>q</uydurma-terim-alani>'],
        ]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.terms.store', [$switch, $policy]), [
                'name' => 'B', 'action' => 'reject',
            ])
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<uydurma-kapsayici>x</uydurma-kapsayici>', $written, 'Politika satırı kayboldu.');
        $this->assertStringContainsString('<uydurma-kosul>y</uydurma-kosul>', $written, 'from satırı kayboldu.');
        $this->assertStringContainsString('<uydurma-eylem>z</uydurma-eylem>', $written, 'then satırı kayboldu.');
        $this->assertStringContainsString('<uydurma-terim-alani>q</uydurma-terim-alani>', $written, 'Terim satırı kayboldu.');
    }

    /**
     * Düzenlenen terimin korunan satırları da taşınır: form onları
     * göstermiyor ama kaydetmek onları silmemeli.
     */
    public function test_terim_duzenlerken_korunan_satirlar_tasinir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'zincir']);
        $term = SwitchPolicyTerm::factory()->for($policy, 'statement')->create([
            'name' => 'A',
            'position' => 0,
            'action' => PolicyAction::Accept,
            'then_actions' => ['residual' => ['<uydurma-eylem>z</uydurma-eylem>']],
        ]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.policies.terms.update', [$switch, $policy, $term]), [
                'name' => 'A', 'action' => 'reject', 'position' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<uydurma-eylem>z</uydurma-eylem>', $device->payload());
    }

    public function test_as_path_tanimi_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.as-paths.store', $switch), [
                'name' => 'musteri1-den', 'path' => '.* 65000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<as-path><name>musteri1-den</name><path>.* 65000</path></as-path>',
            $device->payload(),
        );
    }

    /**
     * Tanıma atıfta bulunan bir terim varsa cihaz commit'i reddeder; panel
     * isteği hiç cihaza götürmez.
     */
    public function test_kullanilan_as_path_silinemez(): void
    {
        $switch = $this->switch();
        $asPath = SwitchAsPath::factory()->for($switch, 'switch')->create(['name' => 'musteri1-den']);
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create();
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create([
            'match_conditions' => ['as_paths' => ['musteri1-den']],
        ]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.as-paths.destroy', [$switch, $asPath]))
            ->assertRedirect();

        $this->assertNull($device->connection);
    }

    /**
     * invert-match tanımın anlamını tersine çevirir; okunmadığı sürece
     * güncellemede sessizce siliniyordu.
     */
    public function test_community_invert_match_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.communities.store', $switch), [
                'name' => 'ters', 'members' => '65000:1', 'invert_match' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<invert-match/>', $device->payload());
    }

    /**
     * Ekran çizilebilmeli: politika artık salt okunur değil, düzenleme yolu
     * açık ve korunan satırlar orada yazıyor.
     */
    public function test_ekran_cizilir_ve_korunan_satirlari_gosterir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create([
            'name' => 'gelen',
            'default_then' => ['action' => 'reject'],
            'residual_xml' => ['<uydurma-kapsayici>x</uydurma-kapsayici>'],
        ]);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create(['name' => 'A', 'position' => 0]);
        SwitchCommunity::factory()->for($switch, 'switch')->create(['name' => 'topluluk-a', 'invert_match' => true]);
        SwitchAsPath::factory()->for($switch, 'switch')->create(['name' => 'musteri1-den', 'path' => '.* 65000']);

        $this->actingAs($this->operator())
            ->get(route('switches.policies', $switch))
            ->assertOk()
            ->assertSee('Varsayılan davranış')
            ->assertSee('korunurlar', false)
            ->assertSee('uydurma-kapsayici')
            ->assertSee('musteri1-den')
            ->assertDontSee('Salt okunurdur');
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

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

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
