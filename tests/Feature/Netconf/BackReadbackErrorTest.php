<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\SwitchWriteStatus;
use App\Jobs\ApplySwitchWriteJob;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\SwitchWriteJob;
use App\Services\Activity\ActivityChain;
use App\Services\Netconf\ConfigurationWriter;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\NetconfTarget;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Yazmayı yapıp geri okumada patlayan sınama yazıcısı.
 */
final class OkumasiDusenYazici
{
    public function __construct(private ConfigurationWriter $writer) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    public function yaz(NetworkSwitch $switch): array
    {
        return $this->writer->apply(
            $switch,
            '<configuration><system/></configuration>',
            'deneme',
            function (NetconfSession $session): void {
                throw new \RuntimeException('simulated readback failure');
            },
        );
    }
}

/**
 * Commit kalıcı olduktan sonra düşen geri okuma yazmayı "başarısız" saymamalı:
 * cihazda yaşayan bir değişiklik etkinlik zincirine girmeden kalırdı.
 */
final class BackReadbackErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_geri_okuma_dusse_de_is_tamamlanir_ve_zincire_girer(): void
    {
        $switch = $this->switch();
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        $kayit = SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'sınama yazması',
            'status' => SwitchWriteStatus::Queued,
        ]);

        (new ApplySwitchWriteJob($kayit, $switch, OkumasiDusenYazici::class, 'yaz'))->handle();

        $kayit->refresh();
        $this->assertSame(SwitchWriteStatus::Completed, $kayit->status);
        $this->assertStringContainsString(ConfigurationWriter::READBACK_WARNING, (string) $kayit->message);
        $this->assertStringContainsString('<confirmed/>', implode('', $device->written));

        $this->assertSame(1, ActivityLog::query()->where('action', 'configured')->count());
        $this->assertTrue(app(ActivityChain::class)->verify()['ok']);
    }

    public function test_geri_alinamayan_yazmada_da_ayni(): void
    {
        $switch = $this->switch();
        $this->app->instance(NetconfTransport::class, new FakeJunosDevice);

        $sonuc = app(ConfigurationWriter::class)->applyOn(
            NetconfTarget::fromSwitch($switch),
            '<configuration><system/></configuration>',
            'deneme',
            function (NetconfSession $session): void {
                throw new \RuntimeException('okuma düştü');
            },
            geriAlinabilir: false,
        );

        $this->assertTrue($sonuc['committed']);
        $this->assertStringContainsString(ConfigurationWriter::READBACK_WARNING, implode(' ', $sonuc['warnings']));
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'jn32-test', 'management_ip' => '198.18.20.195']);
        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
