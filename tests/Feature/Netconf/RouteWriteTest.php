<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\RouteProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoute;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Route ekranı artık cihaza yazar: ekleme, değiştirme ve silme NETCONF ile
 * commit edilir, ardından tablo cihazdan geri okunur.
 */
final class RouteWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_statik_route_cihaza_yazilir_ve_tablo_geri_okunur(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.routes.store', $switch), [
                'cidr' => '192.168.77.0/24',
                'action' => 'next-hop',
                'next_hops' => ['198.18.8.37'],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $written = implode("\n", $transport->written);
        $this->assertStringContainsString('<routing-options><static>', $written);
        $this->assertStringContainsString('<name>192.168.77.0/24</name>', $written);
        $this->assertStringContainsString('<next-hop>198.18.8.37</next-hop>', $written);

        // Envanter cihazdan geri okunanla dolar, formdaki değerle değil.
        $this->assertDatabaseHas('switch_routes', [
            'switch_id' => $switch->id,
            'destination' => '192.168.77.0',
            'prefix_length' => 24,
            'protocol' => RouteProtocol::Static->value,
        ]);
    }

    /**
     * Yolun ne yapacağı AÇIKÇA seçilir.
     *
     * Sonraki atlamayı boş bırakmak sessizce discard demek OLMAMALI: öyle
     * olsaydı kullanıcı alanı temizlediğinde route bir blackhole'a döner ve
     * bunu hiçbir yerde görmezdi.
     */
    public function test_discard_acikca_secilir(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.routes.store', $switch), [
                'cidr' => '192.168.77.0/24',
                'action' => 'discard',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<discard/>', implode("\n", $transport->written));
    }

    /**
     * "Reject" cihazın desteklediği ama panelin üretemediği bir seçenekti;
     * reject'li bir route düzenlenince sessizce discard'a dönüyordu.
     */
    public function test_reject_yazilabilir(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.routes.store', $switch), [
                'cidr' => '192.168.78.0/24',
                'action' => 'reject',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<reject/>', implode("\n", $transport->written));
    }

    /**
     * Eylem seçilmeden gönderilemez; sessiz bir varsayılan yok.
     */
    public function test_eylem_secilmeden_gonderilemez(): void
    {
        $switch = $this->switch();
        $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.routes.store', $switch), [
                'cidr' => '192.168.79.0/24',
            ])
            ->assertSessionHasErrors('action');
    }

    public function test_silme_cihazdan_siler(): void
    {
        $switch = $this->switch();
        $route = $switch->routes()->create([
            'destination' => '192.168.77.0',
            'prefix_length' => 24,
            'next_hops' => ['198.18.8.37'],
            'protocol' => RouteProtocol::Static,
            'family' => 'inet',
        ]);

        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.routes.destroy', [$switch, $route]))
            ->assertRedirect();

        $this->assertStringContainsString(
            '<route delete="delete"><name>192.168.77.0/24</name></route>',
            implode("\n", $transport->written),
        );
    }

    /**
     * Direct, Local, OSPF ve BGP yolları yapılandırmada yoktur; silinmeye
     * çalışılırsa cihaz reddeder, istek oraya kadar gitmemeli.
     */
    public function test_ogrenilen_yol_degistirilemez(): void
    {
        $switch = $this->switch();
        $route = $switch->routes()->create([
            'destination' => '198.18.20.0',
            'prefix_length' => 24,
            'next_hop' => 'me0.0',
            'protocol' => RouteProtocol::Direct,
            'family' => 'inet',
        ]);

        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.routes.destroy', [$switch, $route]))
            ->assertForbidden();

        $this->assertNull($transport->connection);
        $this->assertSame(1, SwitchRoute::count());
    }

    /**
     * Envanteri düzenleyen "update" cihaza yazmaya yetmez.
     */
    public function test_duzenleme_yetkisi_cihaza_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'update']))
            ->post(route('switches.routes.store', $switch), [
                'cidr' => '192.168.77.0/24', 'action' => 'next-hop', 'next_hops' => ['198.18.8.37'],
            ])
            ->assertForbidden();

        $this->assertNull($transport->connection);
    }

    public function test_hedef_ag_adresi_olmali(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->writeFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.routes.store', $switch), [
                'cidr' => '198.18.21.7/24',
            ])
            ->assertSessionHasErrors('cidr');

        $this->assertNull($transport->connection);
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

    /**
     * @param  list<string>  $actions
     */
    private function operator(array $actions): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', $actions);

        /*
         * Cihaz nesnelerinin (port, VLAN, route...) kendi yetkileri var;
         * switch yetkisi onları kapsamıyor. "configure" cihazın kendi
         * ayarlarıdır ve testin niyeti "cihaza yazabilen kullanıcı"dır;
         * "update" ise envanter kaydını düzenlemektir ve cihaza yazdırmaz.
         */
        in_array('configure', $actions, true)
            ? $this->grantDeviceWrite($user)
            : $this->grantDeviceView($user);

        return $user->fresh();
    }

    /**
     * Oturum 1: hello, kilit, aday temiz mi, load, fark, check,
     *           commit confirmed, kilidi bırak.
     * Oturum 2: hello, kilit, commit, kilidi bırak, route tablosu +
     *           statik config.
     *
     * @return list<string>
     */
    private function writeFlow(): array
    {
        return [
            $this->hello(),
            $this->ok(),                 // lock-configuration
            $this->diff(''),             // aday temiz mi (yükleme ÖNCESİ)
            $this->ok(),                 // load-configuration
            $this->diff("[edit routing-options static]\n+   route 192.168.77.0/24 next-hop 198.18.8.37;"),
            $this->ok(),                 // commit confirmed
            $this->ok(),                 // unlock-configuration
            $this->hello(),              // onay oturumu
            $this->ok(),                 // lock-configuration
            $this->ok(),                 // commit
            $this->ok(),                 // unlock-configuration
            $this->routeTable(),
            $this->staticConfig(),
        ];
    }

    /**
     * Yapılandırmadaki statik route'lar; tabloya kurulmamış olanlar da
     * buradan gelir.
     */
    private function staticConfig(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<routing-options><static>'
            .'<route><name>192.168.77.0/24</name><next-hop>198.18.8.37</next-hop></route>'
            .'</static></routing-options></configuration></rpc-reply>'."\n]]>]]>";
    }

    /**
     * @param  list<string>  $responses
     */
    private function fakeTransport(array $responses): FakeNetconfTransport
    {
        $transport = new FakeNetconfTransport($responses);
        $this->app->instance(NetconfTransport::class, $transport);

        return $transport;
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }

    private function ok(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><ok/></rpc-reply>'."\n]]>]]>";
    }

    private function diff(string $text): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration-information>'
            .'<configuration-output>'.htmlspecialchars($text, ENT_XML1).'</configuration-output>'
            .'</configuration-information></rpc-reply>'."\n]]>]]>";
    }

    private function routeTable(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><route-information>'
            .'<route-table><table-name>inet.0</table-name>'
            .'<rt><rt-destination>192.168.77.0/24</rt-destination>'
            .'<rt-entry><protocol-name>Static</protocol-name><nh><to>198.18.8.37</to></nh></rt-entry></rt>'
            .'</route-table></route-information></rpc-reply>'."\n]]>]]>";
    }
}
