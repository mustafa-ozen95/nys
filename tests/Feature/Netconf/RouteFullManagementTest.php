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
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Statik route'un TAMAMI panelden yönetilebilir.
 *
 * Yalnız hedef + tek atlama değil, geri kalan alanlar da yazılır; hiçbiri
 * route'u "salt okunur" yapmaz. Hepsi cihazca yazılabilir; test1'de commit
 * check ile ölçüldü. Bu testler yazılan XML'i kilitliyor.
 */
final class RouteFullManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_ecmp_yazilir(): void
    {
        $device = $this->device();

        $this->routeAdd('10.9.0.0/16', [
            'action' => 'next-hop',
            'next_hops' => ['198.18.8.37', '198.18.8.38'],
        ])->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<next-hop>198.18.8.37</next-hop>', $payload);
        $this->assertStringContainsString('<next-hop>198.18.8.38</next-hop>', $payload);
    }

    public function test_qualified_next_hop_kendi_tercihiyle_yazilir(): void
    {
        $device = $this->device();

        $this->routeAdd('10.9.1.0/24', [
            'action' => 'next-hop',
            'next_hops' => ['198.18.8.37'],
            'qualified_next_hops' => [['name' => '198.18.8.38', 'preference' => 250, 'metric' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<qualified-next-hop><name>198.18.8.38</name><preference>250</preference><metric>5</metric></qualified-next-hop>',
            $device->payload(),
        );
    }

    public function test_preference_metric_tag_ve_secenekler_yazilir(): void
    {
        $device = $this->device();

        $this->routeAdd('10.9.2.0/24', [
            'action' => 'next-hop',
            'next_hops' => ['198.18.8.37'],
            'preference' => 120,
            'metric' => 9,
            'tag' => 4242,
            'flags' => ['retain', 'no-readvertise'],
        ])->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<preference>120</preference>', $payload);
        $this->assertStringContainsString('<metric>9</metric>', $payload);
        $this->assertStringContainsString('<tag>4242</tag>', $payload);
        $this->assertStringContainsString('<retain/>', $payload);
        $this->assertStringContainsString('<no-readvertise/>', $payload);
    }

    /**
     * IPv6 statik route cihazda "rib inet6.0" altındadır. RIB kullanıcıya
     * sorulmaz, hedefin ailesinden türetilir.
     */
    public function test_ipv6_route_inet6_ribine_yazilir(): void
    {
        $device = $this->device();

        $this->routeAdd('2001:db8:1::/48', [
            'action' => 'next-hop',
            'next_hops' => ['2001:db8::1'],
        ])->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<rib><name>inet6.0</name>', $payload);
        $this->assertStringContainsString('<name>2001:db8:1::/48</name>', $payload);
    }

    /**
     * Hedefle atlamanın ailesi aynı olmalı; cihaz zaten reddeder.
     */
    public function test_ipv6_hedefe_ipv4_atlama_verilemez(): void
    {
        $device = $this->device();

        $this->routeAdd('2001:db8:2::/48', [
            'action' => 'next-hop',
            'next_hops' => ['198.18.8.37'],
        ])->assertSessionHasErrors('next_hops');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz "Must not specify resolve with retain" diyor (test1'de ölçüldü);
     * panel bunu önce ve anlaşılır söyler.
     */
    public function test_cakisan_secenekler_reddedilir(): void
    {
        $device = $this->device();

        $this->routeAdd('10.9.3.0/24', [
            'action' => 'next-hop',
            'next_hops' => ['198.18.8.37'],
            'flags' => ['retain', 'resolve'],
        ])->assertSessionHasErrors('flags');

        $this->assertNull($device->connection);
    }

    /**
     * EN ÖNEMLİSİ: panelin göstermediği satır düzenlemede KAYBOLMAZ.
     *
     * Böyle bir route düzenlenebilir ve o satır cihaza olduğu gibi geri
     * yazılır.
     */
    public function test_korunan_ham_satir_geri_yazilir(): void
    {
        $switch = $this->switch();

        $route = SwitchRoute::factory()->for($switch, 'switch')->create([
            'destination' => '10.9.4.0',
            'prefix_length' => 24,
            'next_hop' => '198.18.8.37',
            'next_hops' => ['198.18.8.37'],
            'protocol' => RouteProtocol::Static,
            'residual_xml' => ['<uydurma-alan>x</uydurma-alan>'],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.routes.update', [$switch, $route]), [
                'cidr' => '10.9.4.0/24',
                'action' => 'next-hop',
                'next_hops' => ['10.0.0.99'],
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<next-hop>10.0.0.99</next-hop>', $payload);
        $this->assertStringContainsString('<uydurma-alan>x</uydurma-alan>', $payload, 'Korunan satır geri yazılmadı.');
    }

    private function routeAdd(string $cidr, array $payload): TestResponse
    {
        return $this->actingAs($this->operator())
            ->post(route('switches.routes.store', $this->switch()), ['cidr' => $cidr] + $payload);
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit routing-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(): NetworkSwitch
    {
        return once(function (): NetworkSwitch {
            $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

            $switch->credentials()->create([
                'protocol' => CredentialProtocol::Netconf,
                'username' => 'admin',
                'password' => 'gizli',
                'port' => 830,
            ]);

            return $switch->refresh();
        });
    }

    private function operator(): User
    {
        return once(function (): User {
            $user = User::factory()->create();
            app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
            $this->grantDeviceWrite($user);

            return $user->fresh();
        });
    }
}
