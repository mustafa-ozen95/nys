<?php

declare(strict_types=1);

namespace Tests\Feature\Bgp;

use App\Enums\CredentialProtocol;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Komşu formunun ayrıntı bölümü.
 *
 * BGP günlük iştir ama ayrıntısı günlük değildir: komşu eklenir, açıklaması
 * yazılır, gerektiğinde devre dışı bırakılır. Hold-time, multihop, MD5
 * anahtarı ve politikalar çoğu oturumda varsayılanda kalır.
 *
 * Bu yüzden ayrıntılar KATLANIR, GİZLENMEZ. Aradaki fark hayatidir: bu
 * formda alanın isteğe girmemesi cihazdaki değerin SİLİNMESİ demektir
 * (BgpNeighborRequest::deviceAttributes eksik alanı null/false sayar).
 * Katlanan alan DOM'da kalır, gönderilir, hiçbir şey kaybolmaz.
 */
final class NeighborFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_ayrintilar_katli_gelir_ama_alanlar_formda_durur(): void
    {
        $switch = $this->switch();
        BgpNeighbor::factory()
            ->for(BgpGroup::factory()->for($switch, 'switch'), 'group')
            ->create(['address' => '198.18.8.38', 'hold_time' => null, 'multihop' => false]);

        $output = $this->actingAs($this->operator())
            ->get(route('switches.bgp', $switch))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<details class="fieldgroup"\s+open/', $output);

        /* KRİTİK: katlamak kaldırmak değildir; alan gönderilmeye devam eder. */
        $this->assertStringContainsString('name="hold_time"', $output);
        $this->assertStringContainsString('name="multihop"', $output);
        $this->assertStringContainsString('name="authentication_key"', $output);
    }

    /**
     * Dolu bir ayrıntı katlı kalmaz.
     *
     * Aksi hâlde ekran cihazdaki gerçeği saklardı: hold-time'ı elle
     * ayarlanmış bir oturum, forma bakan kişiye varsayılandaymış gibi
     * görünürdü.
     */
    public function test_ayarlanmis_ayrinti_grubu_acik_getirir(): void
    {
        $switch = $this->switch();
        BgpNeighbor::factory()
            ->for(BgpGroup::factory()->for($switch, 'switch'), 'group')
            ->create(['address' => '198.18.8.38', 'hold_time' => 30]);

        $output = $this->actingAs($this->operator())
            ->get(route('switches.bgp', $switch))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<details class="fieldgroup"\s+open\s*>/', $output);
    }

    private function operator(): User
    {
        $user = User::factory()->create();

        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switches',
            ['view', 'create', 'update', 'configure'],
        );

        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'jn32-test', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
