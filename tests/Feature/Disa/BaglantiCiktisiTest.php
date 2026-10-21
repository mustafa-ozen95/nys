<?php

declare(strict_types=1);

namespace Tests\Feature\Disa;

use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchCredential;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Bağlantı bilgilerinin Excel çıktısı.
 *
 * Parolayı panelin DIŞINA çıkaran tek uç burasıdır ve kuralları tek tek
 * kilitlenir: ayrı yetki, satır başına yetki, zorunlu gerekçe ve kayıt başına
 * denetim satırı. Ayrıca ÇIKTININ İŞE YARAMASI: dosyayı eline alan biri aynı
 * bağlantıyı kurabilmeli, yani adres, port, kullanıcı ve parola orada olmalı.
 */
final class BaglantiCiktisiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gerekçe zorunluluğu BU TESTTE açıktır.
     *
     * Taban sınıf onu varsayılan olarak kapatıyor (her teste gerekçe eklemek
     * testleri okunmaz yapardı). Burada açık: parolayı panelin
     * dışına çıkaran bir uçta gerekçenin gerçekten zorunlu olduğu, o ucun
     * kendi testinde kanıtlanmalı.
     */
    protected bool $requiresActionReason = true;

    private function kullanici(array $sunucu = [], array $switch = []): User
    {
        $user = User::factory()->create();
        $cozucu = app(UserPermissionResolver::class);

        $cozucu->syncResource($user, 'servers', $sunucu);
        $cozucu->syncResource($user, 'switches', $switch);

        return $user->fresh();
    }

    private function sunucu(array $ustune = []): Server
    {
        return Server::factory()->create([
            'short_name' => 'LNX-09',
            'ip_address' => '198.18.20.9',
            'connection_protocol' => 'ssh',
            'connection_port' => 22,
            'connection_username' => 'root',
            'connection_password' => 'SUNUCU-PAROLASI',
            ...$ustune,
        ]);
    }

    /** Dosyanın içindeki sayfayı düz XML'e çevirir. */
    private function sayfa(string $icerik): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'test-xlsx-');
        file_put_contents($yol, $icerik);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($yol) === true, 'Üretilen dosya bir zip değil.');

        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($yol);

        return $xml;
    }

    public function test_cikti_yetkisi_olmayan_indiremez(): void
    {
        /* Görme ve bağlanma yetkisi var ama 'export' yok. */
        $user = $this->kullanici(['view', 'connect']);
        $this->sunucu();

        $this->actingAs($user)
            ->post(route('export.connections'), [
                'kapsam' => 'sunucular',
                'reason' => 'denetim',
            ])
            ->assertForbidden();
    }

    public function test_gerekcesiz_cikti_alinamaz(): void
    {
        $user = $this->kullanici(['view', 'export']);
        $this->sunucu();

        $this->actingAs($user)
            ->post(route('export.connections'), ['kapsam' => 'sunucular'])
            ->assertSessionHasErrors('reason');
    }

    public function test_cikti_baglanti_bilgilerini_tasir(): void
    {
        $user = $this->kullanici(['view', 'export']);
        $this->sunucu();

        $yanit = $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'yil sonu denetimi',
        ]);

        $yanit->assertOk();
        $yanit->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        /* Açık parola listesi hiçbir yerde önbelleğe düşmemeli. */
        $this->assertStringContainsString('no-store', (string) $yanit->headers->get('Cache-Control'));

        $sayfa = $this->sayfa((string) $yanit->getContent());

        /*
         * ÇIKTININ İŞE YARAMASI: dosyayı eline alan biri aynı bağlantıyı
         * kurabilmeli. Eksik bir sütun, çıktının işe yaramaması demek.
         */
        foreach (['LNX-09', '198.18.20.9', 'SSH', '22', 'root', 'SUNUCU-PAROLASI'] as $beklenen) {
            $this->assertStringContainsString($beklenen, $sayfa, "Çıktıda '{$beklenen}' yok.");
        }
    }

    public function test_goremedigi_sunucu_dosyaya_girmez(): void
    {
        $gorunur = $this->sunucu(['short_name' => 'GORUNUR', 'connection_password' => 'GORUNUR-PAROLA']);
        $this->sunucu(['short_name' => 'GIZLI', 'ip_address' => '198.18.20.10', 'connection_password' => 'GIZLI-PAROLA']);

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource(
            $user, 'servers', [], ['view' => [$gorunur->id], 'export' => [$gorunur->id]],
        );

        $yanit = $this->actingAs($user->fresh())->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'denetim',
        ]);

        $sayfa = $this->sayfa((string) $yanit->getContent());

        $this->assertStringContainsString('GORUNUR-PAROLA', $sayfa);
        $this->assertStringNotContainsString('GIZLI-PAROLA', $sayfa);
    }

    public function test_switchin_her_kimligi_ayri_satir(): void
    {
        $user = $this->kullanici([], ['view', 'export']);

        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'SW-01',
            'management_ip' => '198.18.20.20',
        ]);

        SwitchCredential::factory()->create([
            'switch_id' => $switch->id,
            'protocol' => 'netconf',
            'username' => 'netconf-kullanici',
            'password' => 'NETCONF-PAROLA',
            'port' => 830,
        ]);

        SwitchCredential::factory()->create([
            'switch_id' => $switch->id,
            'protocol' => 'snmp_v2c',
            'username' => null,
            'password' => null,
            'community' => 'SNMP-TOPLULUGU',
            'port' => 161,
        ]);

        $yanit = $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'switchler',
            'reason' => 'denetim',
        ]);

        $sayfa = $this->sayfa((string) $yanit->getContent());

        /*
         * İKİSİ DE YAZILIR: her kimlik ayrı bir bağlantı yoludur ve birini
         * seçip ötekini atmak, çıktıyı eline alanın kurabileceği bağlantıyı
         * eksiltmek olurdu.
         */
        $this->assertStringContainsString('NETCONF-PAROLA', $sayfa);
        $this->assertStringContainsString('SNMP-TOPLULUGU', $sayfa);
        $this->assertStringContainsString('830', $sayfa);
    }

    public function test_her_kayit_icin_denetim_satiri_yazilir(): void
    {
        $user = $this->kullanici(['view', 'export']);
        $this->sunucu(['short_name' => 'BIR']);
        $this->sunucu(['short_name' => 'IKI', 'ip_address' => '198.18.20.11']);

        $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'yil sonu denetimi',
        ])->assertOk();

        $satirlar = ActivityLog::query()->where('action', 'exported')->get();

        /*
         * Özet tek satır DEĞİL: denetimin sorduğu soru "hangi parola dışarı
         * çıktı" ve özet satır onu cevaplamaz.
         */
        $this->assertCount(2, $satirlar);
        $this->assertSame('yil sonu denetimi', $satirlar->first()->reason);
    }

    public function test_parolasiz_kayitlar_sebebiyle_sayilir(): void
    {
        $user = $this->kullanici(['view', 'export']);
        $this->sunucu(['short_name' => 'DOLU']);
        $this->sunucu(['short_name' => 'BOS', 'ip_address' => '198.18.20.12', 'connection_password' => null]);

        $yanit = $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'denetim',
        ]);

        $yanit->assertOk();
        $this->assertStringContainsString(
            '1 kaydın parolası yok',
            (string) $yanit->headers->get('X-Nys-Ozet'),
        );
    }
}
