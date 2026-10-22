<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Models\ConsoleSession;
use App\Models\NetworkSwitch;
use App\Models\SwitchCredential;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KAPANIŞ HABERİ HER ZAMAN GELMEZ.
 *
 * Kullanıcı düğmeye basarsa, pencereyi kapatırsa ya da sekmeden çıkarsa
 * tarayıcı haber veriyor. Ama tarayıcı ÇÖKERSE, makine uykuya girerse ya da
 * ağ koparsa kimse haber veremez; o zaman kayıtta "açıldı" satırı olup
 * "kapandı" satırı olmazsa bir oturumun ne zaman bittiği hiç yazmaz.
 *
 * Bu testler izin her iki yoldan da kapandığını kanıtlar.
 */
final class ConsoleTraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/api/tokens' => Http::response(['authToken' => 'sahte', 'dataSource' => 'json'])]);

        $this->mock(TtydManager::class, function ($mock): void {
            $mock->shouldReceive('start')->andReturnUsing(function ($target, string $token): array {
                app(RemoteSessionStore::class)->reservePort(7501, $token);

                return ['port' => 7501, 'path' => '/ssh-proxy/7501/'];
            });

            $mock->shouldReceive('stop')->andReturnNull();
        });
    }

    public function test_acilan_oturum_ize_yazilir(): void
    {
        [$user, $switch] = $this->prepare();
        $token = $this->open($user, $switch);

        $trace = ConsoleSession::query()->where('token', $token)->firstOrFail();

        $this->assertSame($user->id, $trace->user_id);
        $this->assertSame($switch->id, $trace->subject_id);
        $this->assertSame(7501, $trace->port);
        $this->assertNull($trace->closed_at);
    }

    public function test_kullanici_kapatinca_iz_kapanir(): void
    {
        [$user, $switch] = $this->prepare();
        $token = $this->open($user, $switch);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $token])
            ->assertOk();

        $trace = ConsoleSession::query()->where('token', $token)->firstOrFail();

        $this->assertNotNull($trace->closed_at);
        $this->assertSame('kullanici', $trace->closed_reason);
    }

    public function test_canli_tutma_izin_sesini_tazeler(): void
    {
        [$user, $switch] = $this->prepare();
        $token = $this->open($user, $switch);

        ConsoleSession::query()->where('token', $token)->update(['last_seen_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.keep-alive'), ['token' => $token])
            ->assertJsonPath('alive', true);

        $trace = ConsoleSession::query()->where('token', $token)->firstOrFail();

        $this->assertTrue($trace->last_seen_at->greaterThan(now()->subMinute()));
    }

    /**
     * TARAYICI ÇÖKERSE: kimse haber vermez, izi zamanlayıcı kapatır.
     */
    public function test_sesi_kesilen_oturum_zamanlayiciyla_kapanir(): void
    {
        [$user, $switch] = $this->prepare();
        $token = $this->open($user, $switch);

        /* Canlı tutma kesileli çok oldu: tarayıcı gitmiş. */
        ConsoleSession::query()->where('token', $token)->update(['last_seen_at' => now()->subMinutes(30)]);

        $this->artisan('console:prune')->assertSuccessful();

        $trace = ConsoleSession::query()->where('token', $token)->firstOrFail();

        $this->assertNotNull($trace->closed_at);
        $this->assertSame('dustu', $trace->closed_reason);

        /* Kayıt da düşer ve DÜŞEN kişinin adına yazılır. */
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'session_closed',
            'user_id' => $user->id,
        ]);
    }

    /** Süren oturuma dokunulmaz: bir saniyelik kesinti oturumu düşürmemeli. */
    public function test_suren_oturum_kapatilmaz(): void
    {
        [$user, $switch] = $this->prepare();
        $token = $this->open($user, $switch);

        $this->artisan('console:prune')->assertSuccessful();

        $this->assertNull(ConsoleSession::query()->where('token', $token)->value('closed_at'));
    }

    /** @return array{0: User, 1: NetworkSwitch} */
    private function prepare(): array
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'connect']);

        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'gizli']);

        return [$user->fresh(), $switch];
    }

    private function open(User $user, NetworkSwitch $switch): string
    {
        return $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->json('token');
    }
}
