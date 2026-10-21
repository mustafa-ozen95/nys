<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchPortSample;
use App\Services\Netconf\Transport\NetconfTransport;
use App\Services\Switching\SwitchTrafficService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Anlık trafik İSTEK İÇİNDE CİHAZA SORULMAZ.
 *
 * İzleme sistemi o switch için veri döndürmediğinde çözümleyici cihaza geri
 * düşseydi her switch için birkaç saniyelik bir NETCONF oturumu açılır ve
 * ana sayfa switch sayısıyla doğrusal yavaşlardı. Örnekleri zaten eşitleme
 * işi topluyor.
 */
final class TrafficReadbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1])]);
        Cache::flush();
    }

    public function test_anlik_trafik_kendi_orneklerimizden_okunur(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, 'ge-0/0/5', 505);

        SwitchPortSample::create([
            'switch_port_id' => $port->id,
            'sampled_at' => now()->subMinute(),
            'in_bps' => 1_500_000,
            'out_bps' => 250_000,
        ]);

        $device = $this->device();

        $oranlar = app(SwitchTrafficService::class)->current($switch);

        $this->assertSame(
            [505 => ['in' => 1500000.0, 'out' => 250000.0]],
            $oranlar,
        );

        $this->assertNull($device->connection, 'Trafik okumak için cihaza bağlanıldı.');
    }

    /**
     * ESKİ bir örnek "anlık" değildir. Yanlış bir sayı, sayı olmamasından
     * kötüdür; sütun boş kalır.
     */
    public function test_eski_ornek_anlik_sayilmaz(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, 'ge-0/0/5', 505);

        SwitchPortSample::create([
            'switch_port_id' => $port->id,
            'sampled_at' => now()->subSeconds((int) config('measurement.sample_freshness') + 60),
            'in_bps' => 999,
            'out_bps' => 999,
        ]);

        $this->assertSame([], app(SwitchTrafficService::class)->current($switch));
    }

    /**
     * Aynı port için birden çok örnek varsa SONUNCUSU geçerlidir.
     */
    public function test_son_ornek_gecerlidir(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, 'ge-0/0/5', 505);

        foreach ([[100, 5], [900, 45]] as $sira => [$in, $out]) {
            SwitchPortSample::create([
                'switch_port_id' => $port->id,
                'sampled_at' => now()->subMinutes(2 - $sira),
                'in_bps' => $in,
                'out_bps' => $out,
            ]);
        }

        $this->assertSame(
            [505 => ['in' => 900.0, 'out' => 45.0]],
            app(SwitchTrafficService::class)->current($switch),
        );
    }

    /**
     * BAŞKA switchin örneği sızmaz.
     */
    public function test_baska_switchin_ornegi_sizmaz(): void
    {
        $switch = $this->switch();
        $digeri = $this->switch('198.18.20.196');

        SwitchPortSample::create([
            'switch_port_id' => $this->port($digeri, 'ge-0/0/1', 501)->id,
            'sampled_at' => now(),
            'in_bps' => 42,
            'out_bps' => 42,
        ]);

        $this->assertSame([], app(SwitchTrafficService::class)->current($switch));
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function port(NetworkSwitch $switch, string $name, int $ifIndex): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'if_index' => $ifIndex,
        ]);
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
