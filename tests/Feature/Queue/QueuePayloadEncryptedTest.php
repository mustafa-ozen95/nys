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
final class SecretCarryingWriter
{
    /** @var list<mixed>|null */
    public static ?array $incoming = null;

    /**
     * @return array{diff: string, committed: bool}
     */
    public function write(NetworkSwitch $switch, mixed ...$arguments): array
    {
        self::$incoming = array_values($arguments);

        return ['diff' => '', 'committed' => false];
    }
}

/**
 * Kuyruktaki cihaz yazması parola, community ve BGP anahtarı taşır; yük
 * veritabanında düz yazı olarak durmamalı. Şifrelemeden önce kuyruğa girmiş
 * düz yüklü işler ise çalışmaya devam etmeli.
 */
final class QueuePayloadEncryptedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'COK-GIZLI-KUYRUK-PAROLASI';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);
        SecretCarryingWriter::$incoming = null;
    }

    public function test_kuyruk_yuku_sifrelidir_ve_calisir(): void
    {
        $this->pushJobToQueue();

        $load = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString(self::SECRET, $load);
        $this->assertStringNotContainsString(self::SECRET, (string) json_decode($load, true)['data']['command']);

        $this->execute();

        $this->assertSame([self::SECRET], SecretCarryingWriter::$incoming);
    }

    /**
     * Şifrelemeden ÖNCE kuyruğa girmiş iş (düz "O:" yükü) hâlâ çalışır.
     */
    public function test_duz_yuklu_eski_is_calismaya_devam_eder(): void
    {
        $this->pushJobToQueue();

        $row = DB::table('jobs')->first();
        $load = json_decode($row->payload, true);
        $load['data']['command'] = app(Encrypter::class)->decrypt($load['data']['command']);
        $this->assertStringStartsWith('O:', $load['data']['command']);
        DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($load)]);

        $this->execute();

        $this->assertSame([self::SECRET], SecretCarryingWriter::$incoming);
    }

    private function pushJobToQueue(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $record = SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'sınama',
            'status' => SwitchWriteStatus::Queued,
        ]);

        ApplySwitchWriteJob::dispatch($record, $switch, SecretCarryingWriter::class, 'write', [self::SECRET]);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    private function execute(): void
    {
        Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => config('netconf.write_queue'),
            '--tries' => 1,
        ]);
    }
}
