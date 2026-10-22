<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\PolicyAction;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommunity;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Models\SwitchPrefixList;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Politika ve community yazma.
 *
 * Beklenen XML ve kısıtların hepsi jn32-test üzerinde denendi.
 */
final class PolicyWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_politika_ilk_terimiyle_yazilir(): void
    {
        $switch = $this->switch();
        SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'MUSTERI']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'prefixes-from-musteri1',
                'name' => 'A',
                'match_prefix_lists' => ['MUSTERI'],
                'match_type' => 'orlonger',
                'action' => 'accept',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<policy-statement><name>prefixes-from-musteri1</name>', $written);
        // Junos bu alanı ALT ÇİZGİYLE yazar.
        $this->assertStringContainsString('<list_name>MUSTERI</list_name>', $written);
        $this->assertStringContainsString('<orlonger/>', $written);
        $this->assertStringContainsString('<then><accept/></then>', $written);
    }

    /**
     * Eşleşme türü Junos'ta zorunludur; türsüz filtre reddedilir.
     */
    public function test_prefix_list_secilince_eslesme_turu_zorunlu(): void
    {
        $switch = $this->switch();
        SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'MUSTERI']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'A',
                'match_prefix_lists' => ['MUSTERI'], 'action' => 'accept',
            ])
            ->assertSessionHasErrors('match_type');

        $this->assertNull($device->connection);
    }

    public function test_prefix_list_yokken_eslesme_turu_verilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'A',
                'match_type' => 'orlonger', 'action' => 'accept',
            ])
            ->assertSessionHasErrors('match_type');

        $this->assertNull($device->connection);
    }

    /**
     * Sonucu olmayan terim cihazda hiçbir şey yapmaz.
     */
    public function test_sonucu_olmayan_terim_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), ['statement_name' => 'p', 'name' => 'A'])
            ->assertSessionHasErrors('action');

        $this->assertNull($device->connection);
    }

    /**
     * Tanımsız community'ye atıf commit'i düşürür:
     * "topluluk-a community referenced (in term clear) but not defined".
     */
    public function test_cihazda_olmayan_community_secilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'clear', 'community_delete' => 'topluluk-a',
            ])
            ->assertSessionHasErrors('community_delete');

        $this->assertNull($device->connection);
    }

    public function test_tanimli_community_silme_yazilir(): void
    {
        $switch = $this->switch();
        SwitchCommunity::factory()->for($switch, 'switch')->create(['name' => 'topluluk-a']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.store', $switch), [
                'statement_name' => 'p', 'name' => 'clear', 'community_delete' => 'topluluk-a',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<community><delete/><community-name>topluluk-a</community-name></community>',
            $device->payload(),
        );
    }

    /**
     * Terim SIRASI anlamlıdır ve merge yeni terimi hep sona ekler; bu yüzden
     * politika silinip bütün terimleriyle yeniden yazılır.
     */
    public function test_terim_eklerken_politika_silinip_yeniden_yazilir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'zincir']);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create(['name' => 'A', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.terms.store', [$switch, $policy]), [
                'name' => 'B', 'action' => 'reject',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<policy-statement delete="delete"><name>zincir</name>', $written);
        // Eski terim korunur ve yenisi ARKASINA eklenir.
        $this->assertStringContainsString('<term><name>A</name>', $written);
        $this->assertStringContainsString('<term><name>B</name>', $written);
        $this->assertTrue(strpos($written, '<name>A</name>') < strpos($written, '<name>B</name>'));
    }

    /**
     * Terimsiz politika cihazda sessizce yok sayılır; son terim silinemez.
     */
    public function test_son_terim_silinemez(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create();
        $term = SwitchPolicyTerm::factory()->for($policy, 'statement')->create();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.policies.terms.destroy', [$switch, $policy, $term]))
            ->assertStatus(422);

        $this->assertNull($device->connection);
    }

    /**
     * Panelin temsil edemediği politika buradan değiştirilemez.
     */
    public function test_salt_okunur_politikaya_terim_eklenemez(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->unmanageable()->create();
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.policies.terms.store', [$switch, $policy]), ['name' => 'B', 'action' => 'reject'])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    public function test_community_uyeleriyle_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.communities.store', $switch), [
                'name' => 'coklu',
                'members' => "65000:100\n65000:200",
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<members>65000:100</members>', $written);
        $this->assertStringContainsString('<members>65000:200</members>', $written);
    }

    public function test_gecersiz_community_uyesi_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.communities.store', $switch), ['name' => 'x', 'members' => 'olmaz!!'])
            ->assertSessionHasErrors('members.0');

        $this->assertNull($device->connection);
    }

    /**
     * Üye çıkarmak merge ile olmaz: community silinip yeniden yazılır.
     */
    public function test_community_guncellemesi_once_siler(): void
    {
        $switch = $this->switch();
        $community = SwitchCommunity::factory()->for($switch, 'switch')
            ->create(['name' => 'topluluk-a', 'members' => ['65000:1', '65000:2']]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.communities.update', [$switch, $community]), [
                'name' => 'topluluk-a', 'members' => '65000:1',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<community delete="delete"><name>topluluk-a</name>', $device->payload());
    }

    /**
     * Terim yerinde güncellenir; adı değişse bile ikizlenmez.
     */
    public function test_politika_terimi_guncellenir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'zincir']);
        $term = SwitchPolicyTerm::factory()->for($policy, 'statement')
            ->create(['name' => 'A', 'position' => 0, 'action' => PolicyAction::Accept]);
        SwitchPolicyTerm::factory()->for($policy, 'statement')
            ->create(['name' => 'B', 'position' => 1, 'action' => PolicyAction::Reject]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.policies.terms.update', [$switch, $policy, $term]), [
                'name' => 'A', 'action' => 'reject', 'position' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertSame(1, substr_count($written, '<name>A</name>'));
        $this->assertTrue(strpos($written, '<name>A</name>') < strpos($written, '<name>B</name>'));
    }

    /**
     * "Her şeyi reddet" teriminin önüne geçmenin tek yolu sırayı
     * değiştirmektir.
     */
    public function test_politika_terim_sirasi_degistirilebilir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create();
        SwitchPolicyTerm::factory()->for($policy, 'statement')
            ->create(['name' => 'reddet', 'position' => 0, 'action' => PolicyAction::Reject]);
        $accept = SwitchPolicyTerm::factory()->for($policy, 'statement')
            ->create(['name' => 'kabul', 'position' => 1, 'action' => PolicyAction::Accept]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.policies.terms.update', [$switch, $policy, $accept]), [
                'name' => 'kabul', 'action' => 'accept', 'position' => 0,
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertTrue(strpos($written, '<name>kabul</name>') < strpos($written, '<name>reddet</name>'));
    }

    public function test_politika_terimleri_verilen_siraya_dizilir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create();
        $first = SwitchPolicyTerm::factory()->for($policy, 'statement')
            ->create(['name' => 'reddet', 'position' => 0, 'action' => PolicyAction::Reject]);
        $second = SwitchPolicyTerm::factory()->for($policy, 'statement')
            ->create(['name' => 'kabul', 'position' => 1, 'action' => PolicyAction::Accept]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.policies.terms.order', [$switch, $policy]), [
                'terms' => [$second->uuid, $first->uuid],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertTrue(strpos($written, '<name>kabul</name>') < strpos($written, '<name>reddet</name>'));
    }

    public function test_politikada_eksik_siralama_reddedilir(): void
    {
        $switch = $this->switch();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create();
        $first = SwitchPolicyTerm::factory()->for($policy, 'statement')->create(['position' => 0]);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create(['position' => 1]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.policies.terms.order', [$switch, $policy]), ['terms' => [$first->uuid]])
            ->assertStatus(422);

        $this->assertNull($device->connection);
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
