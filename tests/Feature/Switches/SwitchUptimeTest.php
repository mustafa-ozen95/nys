<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cihazların çalışma süresi.
 *
 * Değer Zabbix'ten gelir (SyncSwitchStatusJob) ve tek tek switch açmadan
 * görülebilmeli; listede bir sütunu vardır.
 */
final class SwitchUptimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['result' => []])]);
    }

    public function test_liste_calisma_suresini_gosterir(): void
    {
        NetworkSwitch::factory()->create([
            'hostname' => 'sw-test-9',
            // 2 gün 3 saat.
            'uptime_seconds' => 183_600,
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.index'))
            ->assertOk()
            ->assertSee('Çalışma süresi')
            ->assertSee('2 g 3 sa');
    }

    /**
     * Zabbix eşleşmemiş cihazda süre bilinmez; sıfır yazmak yanlış bilgidir.
     */
    public function test_veri_yoksa_tire_gosterilir(): void
    {
        NetworkSwitch::factory()->create(['hostname' => 'sw-test-8', 'uptime_seconds' => null]);

        $this->assertSame('—', NetworkSwitch::query()->firstWhere('hostname', 'sw-test-8')->uptime_for_humans);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }
}
