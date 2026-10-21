<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\RouteFlag;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Yolun ne yaptığını OKUMA tarafı.
 *
 * Junos "bu route düşürür" diye ayrı bir alan tutmaz: next-hop değerinin
 * kendisi Discard / Reject / Receive olabilir. Yazma tarafı bu sözlüğü zaten
 * biliyordu (SwitchRouteRequest::nextHops); okuma tarafı bir süre FORMUN
 * BLADE'İNDE hesaplıyordu. İki uç ayrı düşerse form cihazdakinden başka bir
 * şey gösterir — kullanıcı "yönlendir" görüp kaydeder, düşüren bir yol yazar.
 */
final class RouteActionTest extends TestCase
{
    use RefreshDatabase;

    public static function eylemler(): array
    {
        return [
            'discard' => ['Discard', 'discard', []],
            'reject' => ['Reject', 'reject', []],
            'receive' => ['Receive', 'receive', []],
            'küçük harfle de tanınır' => ['discard', 'discard', []],
            'adres' => ['198.18.8.37', 'next-hop', ['198.18.8.37']],
            'boş' => [null, 'discard', []],
        ];
    }

    /**
     * @param  list<string>  $beklenenAtlamalar
     */
    #[DataProvider('eylemler')]
    public function test_next_hop_degeri_eylemi_soyler(
        ?string $nextHop,
        string $beklenenEylem,
        array $beklenenAtlamalar,
    ): void {
        $route = $this->route(['next_hop' => $nextHop, 'next_hops' => $nextHop === null ? [] : [$nextHop]]);

        $this->assertSame($beklenenEylem, $route->action);
        $this->assertSame($beklenenAtlamalar, $route->editable_next_hops);
    }

    /**
     * ECMP: birden çok atlama olduğu gibi gelir.
     */
    public function test_coklu_atlama_korunur(): void
    {
        $route = $this->route([
            'next_hop' => '198.18.8.37',
            'next_hops' => ['198.18.8.37', '198.18.8.41'],
        ]);

        $this->assertSame('next-hop', $route->action);
        $this->assertSame(['198.18.8.37', '198.18.8.41'], $route->editable_next_hops);
    }

    /**
     * Tanınmayan bayrak ekrana çıkmaz.
     *
     * Cihaz bizim sözlüğümüzde olmayan bir bayrak döndürebilir; rozet
     * basmaya kalkmak ölümcül hataydı, elemeyi Blade yapıyordu.
     */
    public function test_taninmayan_bayrak_elenir(): void
    {
        $route = $this->route(['flags' => ['retain', 'uydurma-bayrak', 'resolve']]);

        $this->assertSame(
            [RouteFlag::Retain, RouteFlag::Resolve],
            $route->known_flags,
        );
    }

    public function test_bayraksiz_route_bos_liste_verir(): void
    {
        $this->assertSame([], $this->route(['flags' => null])->known_flags);
    }

    /** @param array<string, mixed> $nitelikler */
    private function route(array $nitelikler): SwitchRoute
    {
        return SwitchRoute::factory()
            ->for(NetworkSwitch::factory(), 'switch')
            ->create($nitelikler);
    }
}
