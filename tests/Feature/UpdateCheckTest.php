<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Update\UpdateCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Güncelleme kontrolü: GitHub'a ağa çıkmadan, sahte yanıtlarla.
 *
 * Asıl kurallar: karşılaştırma son BAŞARILI derlemeyle yapılır, yön ölçülür
 * (ileride olan "güncelleme" sayılmaz), jeton sonuçta ve iletilerde geçmez,
 * jeton yoksa açık depoya jetonsuz sorulur. Çalışan sürüm, derlemeye commit
 * vermeyen platformlarda bağlamdaki .git'ten okunur (docker/version-read.sh).
 */
class UpdateCheckTest extends TestCase
{
    private const TOKEN = 'github_pat_SINAMA_JETONU_123';

    private const RUNNING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const LAST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/guncelleme-'.bin2hex(random_bytes(4));
        config([
            'update.repo' => 'mustafa-ozen95/ornek',
            'update.token' => self::TOKEN,
            'update.version' => self::RUNNING,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/state.json');
        @rmdir($this->directory);

        parent::tearDown();
    }

    private function audit(): UpdateCheck
    {
        return new UpdateCheck($this->directory);
    }

    /**
     * @param  array<string, mixed>|null  $karsilastirma  compare yanıtı; null ise istenmemeli
     */
    private function fake(string $last = self::LAST, ?array $comparison = null, int $code = 200, string $message = 'Bad credentials'): void
    {
        Http::fake([
            'api.github.com/repos/mustafa-ozen95/ornek/actions/workflows/image.yml/runs*' => Http::response(
                $code === 200 ? ['workflow_runs' => [['head_sha' => $last, 'updated_at' => '2026-10-20T10:00:00Z']]] : ['message' => $message],
                $code,
            ),
            'api.github.com/repos/mustafa-ozen95/ornek/compare/*' => Http::response($comparison ?? ['status' => 'identical']),
        ]);
    }

    /** @return array<string, mixed> */
    private function commit(string $sha, string $message, string $date): array
    {
        return ['sha' => $sha, 'commit' => ['message' => $message, 'committer' => ['date' => $date]]];
    }

    public function test_jeton_yoksa_acik_depoya_jetonsuz_sorulur(): void
    {
        config(['update.token' => '']);
        $this->fake(last: self::RUNNING);

        $result = $this->audit()->check('sınama');

        $this->assertSame('guncel', $result['durum']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => ! $r->hasHeader('Authorization'));
    }

    public function test_jetonsuz_sorulan_ozel_ya_da_olmayan_depo_anlasilir_hata_verir(): void
    {
        config(['update.token' => '']);
        $this->fake(code: 404, message: 'Not Found');

        $result = $this->audit()->check('sınama');

        $this->assertSame('hata', $result['durum']);
        $this->assertStringContainsString('404', $result['aciklama']);
        $this->assertStringContainsString('UPDATE_TOKEN', $result['aciklama']);
    }

    /** Ayrı ref dosyası, packed-refs, bozuk HEAD ve hiç .git olmaması. */
    public function test_calisan_surum_derleme_baglamindaki_git_ten_okunur(): void
    {
        $root = sys_get_temp_dir().'/surum-'.bin2hex(random_bytes(4));
        $read = fn (): string => trim(Process::run(['sh', base_path('docker/version-read.sh'), $root])->throw()->output());

        try {
            $this->assertSame('', $read());

            mkdir($root.'/.git/refs/heads', 0o700, true);
            file_put_contents($root.'/.git/HEAD', "ref: refs/heads/main\n");
            file_put_contents($root.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n".self::LAST." refs/heads/main\n");
            $this->assertSame(self::LAST, $read());

            file_put_contents($root.'/.git/refs/heads/main', self::RUNNING."\n");
            $this->assertSame(self::RUNNING, $read());

            file_put_contents($root.'/.git/HEAD', "bozuk\n");
            $this->assertSame('', $read());
        } finally {
            Process::run(['rm', '-rf', $root]);
        }
    }

    public function test_son_basarili_derlemeyle_ayniysa_guncel(): void
    {
        $this->fake(last: self::RUNNING);

        $result = $this->audit()->check('sınama');

        $this->assertSame('guncel', $result['durum']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && str_contains($r->url(), 'branch=main')
            && str_contains($r->url(), 'status=success'));
    }

    public function test_geride_ise_yeni_surum_ve_commitler_yeniden_eskiye(): void
    {
        $this->fake(comparison: [
            'status' => 'ahead', 'ahead_by' => 2, 'behind_by' => 0,
            'commits' => [
                $this->commit('c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1', "Eski düzeltme\n\nayrıntı", '2026-10-19T08:00:00Z'),
                $this->commit(self::LAST, 'Yeni özellik', '2026-10-20T09:00:00Z'),
            ],
        ]);

        $audit = $this->audit();
        $result = $audit->check('sınama');

        $this->assertSame('yeni', $result['durum']);
        $this->assertSame(2, $result['geride']);
        $this->assertSame(['Yeni özellik', 'Eski düzeltme'], array_column($result['commitler'], 'baslik'));
        $this->assertTrue($audit->hasNew());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/compare/'.self::RUNNING.'...'.self::LAST));

        // Panel güncellendi: eski sonuç yeni sürümü anlatmıyor, uyarı susar.
        config(['update.version' => self::LAST]);
        $this->assertFalse($audit->hasNew());
    }

    public function test_ileride_ise_guncelleme_denmez(): void
    {
        $this->fake(comparison: ['status' => 'behind', 'ahead_by' => 0, 'behind_by' => 3, 'commits' => []]);

        $audit = $this->audit();
        $result = $audit->check('sınama');

        $this->assertSame('ileride', $result['durum']);
        $this->assertStringContainsString('geri almak', $result['aciklama']);
        $this->assertFalse($audit->hasNew());
    }

    public function test_ayrisik_dal(): void
    {
        $this->fake(comparison: ['status' => 'diverged', 'ahead_by' => 1, 'behind_by' => 2, 'commits' => []]);

        $this->assertSame('ayrisik', $this->audit()->check('sınama')['durum']);
    }

    public function test_calisan_surum_bilinmiyorsa_karsilastirilmaz(): void
    {
        config(['update.version' => '']);
        $this->fake();

        $result = $this->audit()->check('sınama');

        $this->assertSame('bilinmiyor', $result['durum']);
        $this->assertSame(self::LAST, $result['son']);
        Http::assertSentCount(1);
    }

    public function test_reddedilen_jeton_hata_olur_ve_jeton_yazilmaz(): void
    {
        $this->fake(code: 401);

        $result = $this->audit()->check('sınama');

        $this->assertSame('hata', $result['durum']);
        $this->assertStringContainsString('401', $result['aciklama']);
        $this->assertStringNotContainsString(self::TOKEN, (string) file_get_contents($this->directory.'/state.json'));
    }

    public function test_erisim_yoksa_hata_ve_son_sonuc_okunur(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $audit = $this->audit();
        $audit->check('sınama');

        $last = $audit->last();
        $this->assertSame('hata', $last['durum']);
        $this->assertStringContainsString('erişilemedi', $last['aciklama']);
        $this->assertSame('sınama', $last['tetikleyen']);
        $this->assertSame(self::RUNNING, $last['calisan']);
    }

    public function test_bozuk_sonuc_dosyasi_yok_sayilir(): void
    {
        @mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/state.json', '{"durum":"bilmem"}');

        $this->assertNull($this->audit()->last());
        $this->assertFalse($this->audit()->hasNew());
    }
}
