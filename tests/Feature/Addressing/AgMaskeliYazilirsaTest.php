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
final class AgMaskeliYazilirsaTest extends TestCase
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
        $yanit = $this->actingAs($this->operator())->post(route('addressing.store'), [
            'network' => '10.9.9.0/24',
            'prefix_length' => 24,
        ]);

        $yanit->assertSessionHasErrors('network');

        $mesaj = session('errors')->getBag('default')->first('network');

        /* Kural ve DÜZELTMESİ birlikte söylenmeli. */
        $this->assertStringContainsString('yalnızca ağ adresi', $mesaj);
        $this->assertStringContainsString('10.9.9.0', $mesaj);
        $this->assertStringContainsString('/24', $mesaj);

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

        $govde = $this->actingAs($this->operator())
            ->get(route('addressing.index'))
            ->assertOk()
            ->getContent();

        $pencere = mb_substr($govde, (int) mb_strpos($govde, 'id="blok-ekle"'));

        $this->assertStringContainsString('field__error', mb_substr($pencere, 0, 4000));
    }
}
