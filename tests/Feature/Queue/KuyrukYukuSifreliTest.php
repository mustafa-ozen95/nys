<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\SwitchWriteStatus;
use App\Jobs\ApplySwitchWriteJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchWriteJob;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sınama için cihaz yazıcısı: aldığı argümanı kaydeder.
 */
final class SirTasiyanYazici
{
    /** @var list<mixed>|null */
    public static ?array $gelen = null;

    /**
     * @return array{diff: string, committed: bool}
     */
    public function yaz(NetworkSwitch $switch, mixed ...$argumanlar): array
    {
        self::$gelen = array_values($argumanlar);

        return ['diff' => '', 'committed' => false];
    }
}

/**
 * Kuyruktaki cihaz yazması parola, community ve BGP anahtarı taşır; yük
 * veritabanında düz yazı olarak durmamalı. Şifrelemeden önce kuyruğa girmiş
 * düz yüklü işler ise çalışmaya devam etmeli.
 */
final class KuyrukYukuSifreliTest extends TestCase
{
    use RefreshDatabase;

    private const SIR = 'COK-GIZLI-KUYRUK-PAROLASI';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);
        SirTasiyanYazici::$gelen = null;
    }

    public function test_kuyruk_yuku_sifrelidir_ve_calisir(): void
    {
        $this->isiKuyrugaAt();

        $yuk = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString(self::SIR, $yuk);
        $this->assertStringNotContainsString(self::SIR, (string) json_decode($yuk, true)['data']['command']);

        $this->calistir();

        $this->assertSame([self::SIR], SirTasiyanYazici::$gelen);
    }

    /**
     * Şifrelemeden ÖNCE kuyruğa girmiş iş (düz "O:" yükü) hâlâ çalışır.
     */
    public function test_duz_yuklu_eski_is_calismaya_devam_eder(): void
    {
        $this->isiKuyrugaAt();

        $satir = DB::table('jobs')->first();
        $yuk = json_decode($satir->payload, true);
        $yuk['data']['command'] = app(Encrypter::class)->decrypt($yuk['data']['command']);
        $this->assertStringStartsWith('O:', $yuk['data']['command']);
        DB::table('jobs')->where('id', $satir->id)->update(['payload' => json_encode($yuk)]);

        $this->calistir();

        $this->assertSame([self::SIR], SirTasiyanYazici::$gelen);
    }

    private function isiKuyrugaAt(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $kayit = SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'sınama',
            'status' => SwitchWriteStatus::Queued,
        ]);

        ApplySwitchWriteJob::dispatch($kayit, $switch, SirTasiyanYazici::class, 'yaz', [self::SIR]);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    private function calistir(): void
    {
        Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => config('netconf.write_queue'),
            '--tries' => 1,
        ]);
    }
}
