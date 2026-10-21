<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\ActivityChannel;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Etkinlik kaydının değiştirilemezliği.
 *
 * Amaç kaydı gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ kanıtlayabilmektir: bir satır
 * sonradan düzeltilir ya da silinirse zincir kırılır ve doğrulama kırılmanın
 * yerini söyler.
 */
final class ActivityChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_satirlar_birbirine_baglanir(): void
    {
        $chain = app(ActivityChain::class);

        $ilk = $chain->append($this->row('birinci'));
        $ikinci = $chain->append($this->row('ikinci'));

        $this->assertNull($ilk->previous_hash);
        $this->assertNotNull($ilk->hash);
        $this->assertSame($ilk->hash, $ikinci->previous_hash);
        $this->assertTrue($chain->verify()['ok']);
    }

    public function test_degistirilen_satir_yakalanir(): void
    {
        $chain = app(ActivityChain::class);

        $chain->append($this->row('birinci'));
        $hedef = $chain->append($this->row('ikinci'));
        $chain->append($this->row('ucuncu'));

        /*
         * Zincirin etrafından dolaşılır: doğrudan SQL ile, model olayları
         * çalışmadan. Saldırganın yapacağı şey tam olarak budur.
         */
        DB::table('activity_logs')->where('id', $hedef->id)->update(['description' => 'aslinda boyle degildi']);

        $sonuc = $chain->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($hedef->id, $sonuc['broken_id']);
    }

    public function test_silinen_satir_yakalanir(): void
    {
        $chain = app(ActivityChain::class);

        $chain->append($this->row('birinci'));
        $silinecek = $chain->append($this->row('ikinci'));
        $sonraki = $chain->append($this->row('ucuncu'));

        DB::table('activity_logs')->where('id', $silinecek->id)->delete();

        $sonuc = $chain->verify();

        // Silinen satırın özeti sonrakinin içindeydi; kırılma orada görünür.
        $this->assertFalse($sonuc['ok']);
        $this->assertSame($sonraki->id, $sonuc['broken_id']);
    }

    public function test_zincirden_once_yazilmis_satirlar_kirik_sayilmaz(): void
    {
        // Zincir kurulmadan önce yazılmış bir satır: özeti yok.
        ActivityLog::create($this->row('eski'));

        $chain = app(ActivityChain::class);
        $chain->append($this->row('yeni'));

        $sonuc = $chain->verify();

        $this->assertTrue($sonuc['ok']);
        $this->assertSame(1, $sonuc['checked']);
        $this->assertSame(1, $sonuc['unprotected']);
    }

    public function test_budama_zinciri_kirmaz(): void
    {
        $chain = app(ActivityChain::class);

        $this->travel(-400)->days();
        $chain->append($this->row('cok eski'));
        $chain->append($this->row('yine eski'));
        $this->travelBack();

        $chain->append($this->row('guncel'));

        config(['maintenance.activity_retention_days' => 365]);
        $this->artisan('network:prune')->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->count());

        /*
         * Kalan satırın "önceki"si artık veritabanında değil; budama silinen
         * son satırın özetini çapa olarak bıraktığı için zincir yine
         * doğrulanır. Bu olmadan her budama kaydı kırık gösterirdi.
         */
        $this->assertTrue($chain->verify()['ok']);
    }

    public function test_her_istek_kayda_gecer(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('overview'))->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'channel' => ActivityChannel::Access->value,
            'action' => 'get',
        ]);

        // Erişim kaydı da zincire girer.
        $this->assertTrue(app(ActivityChain::class)->verify()['ok']);
    }

    /**
     * Erişim kayıtları AYRI zincirdedir ve ayrı süreyle budanır.
     *
     * Tek zincirde bu mümkün değildi: sayfa açma kayıtları denetim
     * kayıtlarının arasına giriyordu ve ortadan satır silmek zinciri
     * kırıyordu. Ayrı zincirde erişim kendi başından budanır, denetim
     * zinciri hiç etkilenmez.
     */
    public function test_erisim_kayitlari_ayri_sureyle_budanir(): void
    {
        $chain = app(ActivityChain::class);

        $this->travel(-200)->days();
        $chain->append($this->row('eski denetim'));
        $chain->append($this->accessRow('eski erisim'));
        $this->travelBack();

        $chain->append($this->row('guncel denetim'));
        $chain->append($this->accessRow('guncel erisim'));

        config([
            'maintenance.activity_retention_days' => 365,
            'maintenance.access_retention_days' => 30,
        ]);

        $this->artisan('network:prune')->assertSuccessful();

        // Denetim satırlarının ikisi de duruyor; erişimin yalnızca güncel olanı.
        $this->assertSame(2, ActivityLog::query()->where('chain', ActivityChain::AUDIT)->count());
        $this->assertSame(1, ActivityLog::query()->where('chain', ActivityChain::ACCESS)->count());

        // İki zincir de doğrulanır: budama hiçbirini kırmadı.
        $this->assertTrue($chain->verify()['ok']);
    }

    /**
     * Erişim kaydı denetim zincirinin ucunu OYNATMAZ: iki zincir birbirinden
     * bağımsız ilerler, yoksa erişim budaması denetimi kırardı.
     */
    public function test_iki_zincir_birbirinden_bagimsiz_ilerler(): void
    {
        $chain = app(ActivityChain::class);

        $ilk = $chain->append($this->row('denetim bir'));
        $chain->append($this->accessRow('arada erisim'));
        $ikinci = $chain->append($this->row('denetim iki'));

        $this->assertSame($ilk->hash, $ikinci->previous_hash);
        $this->assertSame(ActivityChain::AUDIT, $ikinci->chain);
    }

    /**
     * Son satırları silmek ileri yönlü zincirde görünmezdi. Son nokta
     * veritabanının dışında (önbellekte) durur.
     */
    public function test_sondan_silinen_satirlar_yakalanir(): void
    {
        $chain = app(ActivityChain::class);

        $chain->append($this->row('birinci'));
        $chain->append($this->row('ikinci'));
        $son = $chain->append($this->row('ucuncu'));

        $this->assertTrue($chain->verify()['ok']);

        DB::table('activity_logs')->where('id', $son->id)->delete();

        $sonuc = $chain->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($son->id, $sonuc['broken_id']);
    }

    /**
     * Son noktası hiç yazılmamış bir zincirde ilk doğrulama mevcut satırları
     * olduğu gibi onaylar ve noktayı oradan kurar.
     */
    public function test_son_nokta_yoksa_ilk_dogrulama_onu_kurar(): void
    {
        $chain = app(ActivityChain::class);

        $chain->append($this->row('birinci'));
        $son = $chain->append($this->row('ikinci'));

        // Son nokta hiç yazılmamış.
        Cache::flush();
        $this->assertTrue($chain->verify()['ok']);

        DB::table('activity_logs')->where('id', $son->id)->delete();
        $this->assertFalse($chain->verify()['ok']);
    }

    public function test_geri_alinan_islemdeki_satir_sondan_silinmis_sayilmaz(): void
    {
        $chain = app(ActivityChain::class);

        $chain->append($this->row('kalici'));

        try {
            DB::transaction(function () use ($chain): void {
                $chain->append($this->row('geri alinacak'));

                throw new \RuntimeException('iptal');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($chain->verify()['ok']);
    }

    public function test_tamamen_budanan_zincir_sondan_silinmis_sayilmaz(): void
    {
        $chain = app(ActivityChain::class);

        $this->travel(-400)->days();
        $chain->append($this->accessRow('eski erisim'));
        $this->travelBack();

        config(['maintenance.access_retention_days' => 30]);
        $this->artisan('network:prune')->assertSuccessful();

        $this->assertSame(0, ActivityLog::query()->count());
        $this->assertTrue($chain->verify()['ok']);
    }

    /**
     * Kırık satırdan sonra durmak, sonraki gerçek değişikliği gizlerdi.
     */
    public function test_dogrulama_kirigin_otesine_gecer(): void
    {
        $chain = app(ActivityChain::class);

        $a = $chain->append($this->row('a'));
        $chain->append($this->row('b'));
        $c = $chain->append($this->row('c'));

        DB::table('activity_logs')->where('id', $a->id)->update(['description' => 'degisti-1']);
        DB::table('activity_logs')->where('id', $c->id)->update(['description' => 'degisti-2']);

        $sonuc = $chain->verify();

        $this->assertSame($a->id, $sonuc['broken_id']);
        $this->assertSame([$a->id, $c->id], $sonuc['broken_ids']);
    }

    /**
     * Kullanıcı silmek, özetli satırların user_id'sini DB'ye NULL
     * yaptırmamalı; zincir kırılmamalı ve kim yaptı bilgisi kalmalı.
     */
    public function test_kullanici_silmek_zinciri_kirmaz(): void
    {
        $user = User::factory()->create();
        $chain = app(ActivityChain::class);

        $satir = $chain->append(['user_id' => $user->id] + $this->row('kullanicinin isi'));
        $chain->append($this->row('baska is'));

        $user->delete();

        $this->assertSame($user->id, $satir->fresh()->user_id);
        $this->assertTrue($chain->verify()['ok']);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/private/zincir-muhru.json'));

        parent::tearDown();
    }

    /** Mühür: bilinen kırık kabul edilir, satır onarılmaz. */
    public function test_muhurlenen_eski_kirik_bilinen_sayilir(): void
    {
        $chain = app(ActivityChain::class);

        $chain->append($this->row('a'));
        $b = $chain->append($this->row('b'));
        $chain->append($this->row('c'));
        DB::table('activity_logs')->where('id', $b->id)->update(['description' => 'eski kirik']);

        $this->assertFalse($chain->verify()['ok']);

        $chain->seal('test');
        $sonuc = $chain->verify();

        $this->assertTrue($sonuc['ok']);
        $this->assertSame(1, $sonuc['acknowledged']);
        $this->assertSame('eski kirik', DB::table('activity_logs')->where('id', $b->id)->value('description'));
    }

    public function test_muhurlu_satir_sonradan_degisirse_yeniden_kirik_olur(): void
    {
        $chain = app(ActivityChain::class);

        $b = $chain->append($this->row('b'));
        DB::table('activity_logs')->where('id', $b->id)->update(['description' => 'eski kirik']);
        $chain->seal('test');

        DB::table('activity_logs')->where('id', $b->id)->update(['description' => 'mühürden sonra oynandı']);

        $sonuc = $chain->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($b->id, $sonuc['broken_id']);
        $this->assertSame(0, $sonuc['acknowledged']);
    }

    public function test_muhurden_sonraki_yeni_kirik_kabul_edilmez(): void
    {
        $chain = app(ActivityChain::class);

        $a = $chain->append($this->row('a'));
        DB::table('activity_logs')->where('id', $a->id)->update(['description' => 'eski kirik']);
        $chain->seal('test');

        $yeni = $chain->append($this->row('yeni'));
        DB::table('activity_logs')->where('id', $yeni->id)->update(['description' => 'yeni oyun']);

        $sonuc = $chain->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame([$yeni->id], $sonuc['broken_ids']);
        $this->assertSame(1, $sonuc['acknowledged']);
    }

    public function test_imzasi_bozulan_muhur_yok_sayilir(): void
    {
        $chain = app(ActivityChain::class);

        $a = $chain->append($this->row('a'));
        DB::table('activity_logs')->where('id', $a->id)->update(['description' => 'eski kirik']);
        $chain->seal('test');

        $yol = storage_path('app/private/zincir-muhru.json');
        file_put_contents($yol, str_replace('"note":"test"', '"note":"elle"', (string) file_get_contents($yol)));

        $sonuc = $chain->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($a->id, $sonuc['broken_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function accessRow(string $description): array
    {
        return ['channel' => ActivityChannel::Access] + $this->row($description);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $description): array
    {
        return [
            'user_id' => null,
            'channel' => ActivityChannel::Crud,
            'action' => 'created',
            'description' => $description,
            'ip_address' => '10.0.0.1',
        ];
    }
}
