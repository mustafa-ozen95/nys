<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Jobs\SyncSwitchNetconfJob;
use App\Models\NetworkSwitch;
use App\Services\Netconf\ConfigurationWriter;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\SwitchWriteLock;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Aynı switche eşzamanlı yazmanın engellenmesi.
 *
 * Junos'ta bekleyen bir "commit confirmed" varken başkasının attığı commit
 * bekleyeni de onaylar; yani ikinci kullanıcı, birincinin doğrulanmamış
 * değişikliğini kalıcı hâle getirir ve cihazın kendini geri döndürme
 * mekanizması tam o anda devre dışı kalır. Bu testler o kapının kapalı
 * kaldığını kilitler.
 */
final class SwitchWriteLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_yazma_surerken_ikinci_yazma_reddedilir(): void
    {
        $switch = $this->switch();
        $this->app->instance(NetconfTransport::class, new FakeJunosDevice);

        $lock = app(SwitchWriteLock::class);
        $writer = app(ConfigurationWriter::class);

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessage('başka bir değişiklik sürüyor');

        // Dışarıdaki kilit tutulurken içeriden yazma denenir.
        $lock->run($switch, function () use ($writer, $switch): void {
            $writer->apply($switch, '<configuration><system/></configuration>', 'deneme');
        });
    }

    public function test_yazma_surerken_esitleme_turu_atlanir(): void
    {
        $switch = $this->switch(['last_sync_at' => null]);
        $this->app->instance(NetconfTransport::class, new FakeJunosDevice);

        app(SwitchWriteLock::class)->run($switch, function () use ($switch): void {
            SyncSwitchNetconfJob::dispatchSync($switch->id);
        });

        /*
         * Tur atlandığı için hiçbir şey okunmadı: eşitleme damgası boş kalır.
         * Yarım bir yapılandırmayı envantere yazmaktansa beklemek doğrudur.
         */
        $this->assertNull($switch->fresh()->last_sync_at);
        $this->assertSame(0, $switch->ports()->count());
    }

    public function test_kilit_biraktiktan_sonra_yazma_calisir(): void
    {
        $switch = $this->switch();
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        app(SwitchWriteLock::class)->run($switch, fn () => null);

        $result = app(ConfigurationWriter::class)
            ->apply($switch, '<configuration><system/></configuration>', 'deneme');

        $this->assertTrue($result['committed']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function switch(array $attributes = []): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'jn32-test',
            'management_ip' => '198.18.20.195',
            ...$attributes,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
