<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\IpFamily;
use App\Models\AddressBlock;
use App\Models\User;
use App\Services\Addressing\AddressCandidateService;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adresleme modülü IPv6'yı da yönetir.
 *
 * 128 bit adres tamsayıya sığmadığı için modül baştan sona IPv4'e kilitliydi:
 * ip2long, max:32, sayısal aralık. Aralık artık paketlenmiş bayt (inet_pton)
 * ve aynı kod iki aile için de çalışıyor.
 */
final class Ipv6AddressingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ipv6_blok_eklenebilir(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('addressing.store'), [
                'network' => '2001:db8::',
                'prefix_length' => 32,
                'description' => 'IPv6 havuzu',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $blok = AddressBlock::query()->firstWhere('network', '2001:db8::');

        $this->assertNotNull($blok);
        $this->assertSame(IpFamily::Inet6, $blok->family);
        $this->assertSame(inet_pton('2001:db8::'), $blok->range_start);
        $this->assertSame(inet_pton('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'), $blok->range_end);
    }

    /**
     * /32 sınırı IPv4'ün sınırıydı; IPv6'da /128'e kadar gidilebilir.
     */
    public function test_ipv6_blok_32_den_dar_olabilir(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('addressing.store'), [
                'network' => '2001:db8::',
                'prefix_length' => 64,
                'description' => 'Müşteri /64',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(AddressBlock::query()->firstWhere('prefix_length', 64));
    }

    /**
     * Ayrılmış aralıklar IPv6'da da korunur; yoksa link-local bir blok
     * kaydedilebilir.
     */
    public function test_ipv6_ayrilmis_aralik_reddedilir(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('addressing.store'), [
                'network' => 'fe80::',
                'prefix_length' => 64,
                'description' => 'link-local',
            ])
            ->assertSessionHasErrors('network');
    }

    public function test_cakisan_ipv6_blok_reddedilir(): void
    {
        AddressBlock::factory()->network('2001:db8::/32')->create();

        $this->actingAs($this->yonetici())
            ->post(route('addressing.store'), [
                'network' => '2001:db8:1::',
                'prefix_length' => 48,
                'description' => 'çakışan',
            ])
            ->assertSessionHasErrors('network');
    }

    /**
     * Aile farkı çakışma değildir: aynı baytlara benzeyen iki farklı ailenin
     * bloğu yan yana durabilir.
     */
    public function test_ipv4_ve_ipv6_blok_cakismaz(): void
    {
        AddressBlock::factory()->network('10.0.0.0/8')->create();

        $this->actingAs($this->yonetici())
            ->post(route('addressing.store'), [
                'network' => '2001:db8::',
                'prefix_length' => 32,
                'description' => 'ayrı aile',
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Bir /64'te 2^64 adres var: hepsi listelenemez. Aday üretimi sınırlı
     * sayıda adres önerir, kullanıcı gerisini elle yazar.
     */
    public function test_ipv6_adres_adaylari_sinirli_uretilir(): void
    {
        $blok = AddressBlock::factory()->network('2001:db8::/64')->create(['is_subnetted' => false]);

        $adresler = app(AddressCandidateService::class)->usable($blok);

        $this->assertNotEmpty($adresler);
        $this->assertLessThanOrEqual(AddressCandidateService::BLOCK_LIMIT, count($adresler));

        // İlk kullanılabilir adres ağ adresi DEĞİLDİR (subnet-router anycast).
        $this->assertSame('2001:db8::1', $adresler[0]);
    }

    private function yonetici(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'addressing', ['view', 'create', 'update', 'delete']);
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);

        return $user->fresh();
    }
}
