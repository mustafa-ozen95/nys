<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ağ adresi alanına maske yazılması.
 *
 * Ağ alanına "10.9.9.0/24" yazıldığında kural ekranda söylenir. Genel mesaj
 * ("geçerli bir IP adresi olmalıdır") ne yapılacağını söylemez; pencere
 * kapalı dönerse mesaj hiç görünmez.
 */
final class NetworkMaskedWrittenTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'addressing', ['view', 'create']);

        return $user->fresh();
    }

    public function test_maske_ag_alanina_yazilirsa_ne_yapilacagi_soylenir(): void
    {
        $response = $this->actingAs($this->operator())->post(route('addressing.store'), [
            'network' => '10.9.9.0/24',
            'prefix_length' => 24,
        ]);

        $response->assertSessionHasErrors('network');

        $message = session('errors')->getBag('default')->first('network');

        /* Kural ve DÜZELTMESİ birlikte söylenmeli. */
        $this->assertStringContainsString('yalnızca ağ adresi', $message);
        $this->assertStringContainsString('10.9.9.0', $message);
        $this->assertStringContainsString('/24', $message);

        $this->assertDatabaseCount('address_blocks', 0);
    }

    /** Maskesiz yazılan doğru adres geçer; kural yalnızca eğik çizgiye bakar. */
    public function test_maskesiz_adres_kabul_edilir(): void
    {
        $this->actingAs($this->operator())->post(route('addressing.store'), [
            'network' => '10.9.9.0',
            'prefix_length' => 24,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('address_blocks', ['network' => '10.9.9.0', 'prefix_length' => 24]);
    }

    /**
     * HATA EKRANDA GÖRÜNÜR: pencere hata varken açık gelmeli.
     *
     * Mesaj alanın altında yazılıyor (form/field.blade.php) ama pencere
     * kapalıysa kimse göremez. Açan kural betikte ve geneldir; burada
     * kilitlenen şey mesajın pencerenin İÇİNDE basıldığıdır.
     */
    public function test_hata_pencerenin_icinde_basilir(): void
    {
        $this->actingAs($this->operator())->post(route('addressing.store'), [
            'network' => '10.9.9.0/24',
            'prefix_length' => 24,
        ]);

        $body = $this->actingAs($this->operator())
            ->get(route('addressing.index'))
            ->assertOk()
            ->getContent();

        $window = mb_substr($body, (int) mb_strpos($body, 'id="block-add"'));

        $this->assertStringContainsString('field__error', mb_substr($window, 0, 4000));
    }
}
