<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API jetonlarının yönetimi.
 *
 * Jeton bir kimlik bilgisidir: sahibi adına konuşur. Bu yüzden çıkarmak
 * yalnızca yöneticidedir ve sır YALNIZCA bir kez gösterilir.
 */
final class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_jeton_cikarilir_ve_sir_bir_kez_gosterilir(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'izleme-sistemi',
                'user' => $owner->uuid,
                'lifetime_days' => 30,
                'allowed_ips' => "198.18.20.0/24\n192.168.1.10",
                'abilities' => ['switches' => ['view'], 'switch_ports' => ['view']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $token = ApiToken::query()->firstWhere('name', 'izleme-sistemi');

        $this->assertNotNull($token);
        $this->assertSame($owner->id, $token->user_id);
        $this->assertSame(['switches' => ['view'], 'switch_ports' => ['view']], $token->abilityMap());
        $this->assertSame(['198.18.20.0/24', '192.168.1.10'], $token->allowed_ips);
        $this->assertNotNull($token->expires_at);

        // Sır oturumda tek seferlik durur ve özetiyle eşleşir.
        $plain = session('token_plain');
        $this->assertNotNull($plain);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        /*
         * Sır yönlendirilen sayfada BİR KEZ görünür (oturumda tek seferlik
         * durur), sayfa yenilendiğinde bir daha görünmez.
         */
        $this->get(route('settings.api.show', $token))->assertOk()->assertSee($plain);
        $this->get(route('settings.api.show', $token))->assertOk()->assertDontSee($plain);
    }

    /**
     * Jetonun kendisi SAKLANMAZ; yalnızca özeti tutulur.
     */
    public function test_jeton_duz_metin_saklanmaz(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'deneme',
                'user' => $owner->uuid,
                'abilities' => ['switches' => ['view']],
            ]);

        $plain = session('token_plain');

        $this->assertSame(0, ApiToken::query()->where('prefix', $plain)->count());
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $plain]);
    }

    /**
     * Tanımsız kaynak/aksiyon jetona GİRMEZ.
     */
    public function test_tanimsiz_yetki_atilir(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'deneme',
                'user' => $owner->uuid,
                'abilities' => [
                    'switches' => ['view', 'uydurma'],
                    'olmayan_kaynak' => ['view'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['switches' => ['view']],
            ApiToken::query()->firstWhere('name', 'deneme')->abilityMap(),
        );
    }

    /**
     * Sıfır "süresiz" demektir.
     *
     * Alan önceden dolu geldiği için temizlemek yerine 0 yazmak daha doğal;
     * 0'ı reddetmek "süresiz yapamıyorum" gibi görünüyordu.
     */
    public function test_sifir_omur_suresiz_demektir(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'suresiz',
                'user' => $owner->uuid,
                'lifetime_days' => 0,
                'abilities' => ['switches' => ['view']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $token = ApiToken::query()->firstWhere('name', 'suresiz');

        $this->assertNull($token->expires_at);
        $this->assertTrue($token->isUsable());
    }

    public function test_bos_omur_de_suresiz_demektir(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'bos-omur',
                'user' => $owner->uuid,
                'lifetime_days' => '',
                'abilities' => ['switches' => ['view']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(ApiToken::query()->firstWhere('name', 'bos-omur')->expires_at);
    }

    /**
     * Süresi olan bir jeton 0 ile süresiz yapılabilmeli.
     */
    public function test_sureli_jeton_sifirla_suresiz_yapilir(): void
    {
        $token = ApiToken::factory()->create(['expires_at' => now()->addDays(30)]);

        $this->actingAs($this->admin())
            ->put(route('settings.api.update', $token), [
                'name' => $token->name,
                'lifetime_days' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($token->fresh()->expires_at);
    }

    public function test_negatif_omur_reddedilir(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'deneme',
                'user' => $owner->uuid,
                'lifetime_days' => -1,
            ])
            ->assertSessionHasErrors('lifetime_days');
    }

    public function test_gecersiz_adres_reddedilir(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('settings.api.store'), [
                'name' => 'deneme',
                'user' => $owner->uuid,
                'allowed_ips' => "198.18.20.0/24\nbu-bir-adres-degil",
            ])
            ->assertSessionHasErrors('allowed_ips.1');
    }

    /**
     * Sahibi sonradan değişmez: aynı sır başka birinin yetkilerine açılırdı.
     */
    public function test_sahibi_sonradan_degistirilemez(): void
    {
        $token = ApiToken::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('settings.api.update', $token), [
                'name' => $token->name,
                'user' => $other->uuid,
            ])
            ->assertSessionHasErrors('user');

        $this->assertNotSame($other->id, $token->fresh()->user_id);
    }

    public function test_yeni_sir_uretilir_ve_eskisi_gecersiz_olur(): void
    {
        $token = ApiToken::factory()->create();
        $old = $token->token_hash;

        $this->actingAs($this->admin())
            ->put(route('settings.api.rotate', $token))
            ->assertRedirect();

        $this->assertNotSame($old, $token->fresh()->token_hash);
        $this->assertSame(hash('sha256', session('token_plain')), $token->fresh()->token_hash);
    }

    /**
     * İptal SİLMEZ: kimin hangi jetonla ne yaptığı kayıtta durur.
     */
    public function test_iptal_kaydi_birakir(): void
    {
        $token = ApiToken::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('settings.api.revoke', $token))
            ->assertRedirect();

        $token = $token->fresh();

        $this->assertNotNull($token->revoked_at);
        $this->assertFalse($token->isUsable());
    }

    public function test_silinen_jeton_kalmaz(): void
    {
        $token = ApiToken::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('settings.api.destroy', $token))
            ->assertRedirect(route('settings.api'));

        $this->assertModelMissing($token);
    }

    /**
     * Jeton çıkarmak yalnızca yöneticidedir: başkası adına jeton çıkarmak,
     * o kişinin yetkisini kalıcı bir anahtara dönüştürmektir.
     */
    public function test_yonetici_olmayan_giremez(): void
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'users', ['view', 'create', 'update']);
        $user = $user->fresh();

        $token = ApiToken::factory()->create();

        $this->actingAs($user)->get(route('settings.api'))->assertForbidden();
        $this->actingAs($user)->post(route('settings.api.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('settings.api.rotate', $token))->assertForbidden();
        $this->actingAs($user)->delete(route('settings.api.destroy', $token))->assertForbidden();
    }

    public function test_ekran_jetonlari_listeler(): void
    {
        ApiToken::factory()->create(['name' => 'izleme-sistemi']);

        $this->actingAs($this->admin())
            ->get(route('settings.api'))
            ->assertOk()
            ->assertSee('izleme-sistemi')
            ->assertSee('API jetonları');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
