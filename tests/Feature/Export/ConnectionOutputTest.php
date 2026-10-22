<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

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
final class ConnectionOutputTest extends TestCase
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

    private function user(array $server = [], array $switch = []): User
    {
        $user = User::factory()->create();
        $resolver = app(UserPermissionResolver::class);

        $resolver->syncResource($user, 'servers', $server);
        $resolver->syncResource($user, 'switches', $switch);

        return $user->fresh();
    }

    private function server(array $onto = []): Server
    {
        return Server::factory()->create([
            'short_name' => 'LNX-09',
            'ip_address' => '198.18.20.9',
            'connection_protocol' => 'ssh',
            'connection_port' => 22,
            'connection_username' => 'root',
            'connection_password' => 'SUNUCU-PAROLASI',
            ...$onto,
        ]);
    }

    /** Dosyanın içindeki sayfayı düz XML'e çevirir. */
    private function page(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx-');
        file_put_contents($path, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Üretilen dosya bir zip değil.');

        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_cikti_yetkisi_olmayan_indiremez(): void
    {
        /* Görme ve bağlanma yetkisi var ama 'export' yok. */
        $user = $this->user(['view', 'connect']);
        $this->server();

        $this->actingAs($user)
            ->post(route('export.connections'), [
                'kapsam' => 'sunucular',
                'reason' => 'denetim',
            ])
            ->assertForbidden();
    }

    public function test_gerekcesiz_cikti_alinamaz(): void
    {
        $user = $this->user(['view', 'export']);
        $this->server();

        $this->actingAs($user)
            ->post(route('export.connections'), ['kapsam' => 'sunucular'])
            ->assertSessionHasErrors('reason');
    }

    public function test_cikti_baglanti_bilgilerini_tasir(): void
    {
        $user = $this->user(['view', 'export']);
        $this->server();

        $response = $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'yil sonu denetimi',
        ]);

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        /* Açık parola listesi hiçbir yerde önbelleğe düşmemeli. */
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $page = $this->page((string) $response->getContent());

        /*
         * ÇIKTININ İŞE YARAMASI: dosyayı eline alan biri aynı bağlantıyı
         * kurabilmeli. Eksik bir sütun, çıktının işe yaramaması demek.
         */
        foreach (['LNX-09', '198.18.20.9', 'SSH', '22', 'root', 'SUNUCU-PAROLASI'] as $expected) {
            $this->assertStringContainsString($expected, $page, "Çıktıda '{$expected}' yok.");
        }
    }

    public function test_goremedigi_sunucu_dosyaya_girmez(): void
    {
        $visible = $this->server(['short_name' => 'GORUNUR', 'connection_password' => 'GORUNUR-PAROLA']);
        $this->server(['short_name' => 'GIZLI', 'ip_address' => '198.18.20.10', 'connection_password' => 'GIZLI-PAROLA']);

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource(
            $user, 'servers', [], ['view' => [$visible->id], 'export' => [$visible->id]],
        );

        $response = $this->actingAs($user->fresh())->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'denetim',
        ]);

        $page = $this->page((string) $response->getContent());

        $this->assertStringContainsString('GORUNUR-PAROLA', $page);
        $this->assertStringNotContainsString('GIZLI-PAROLA', $page);
    }

    public function test_switchin_her_kimligi_ayri_satir(): void
    {
        $user = $this->user([], ['view', 'export']);

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

        $response = $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'switchler',
            'reason' => 'denetim',
        ]);

        $page = $this->page((string) $response->getContent());

        /*
         * İKİSİ DE YAZILIR: her kimlik ayrı bir bağlantı yoludur ve birini
         * seçip ötekini atmak, çıktıyı eline alanın kurabileceği bağlantıyı
         * eksiltmek olurdu.
         */
        $this->assertStringContainsString('NETCONF-PAROLA', $page);
        $this->assertStringContainsString('SNMP-TOPLULUGU', $page);
        $this->assertStringContainsString('830', $page);
    }

    public function test_her_kayit_icin_denetim_satiri_yazilir(): void
    {
        $user = $this->user(['view', 'export']);
        $this->server(['short_name' => 'BIR']);
        $this->server(['short_name' => 'IKI', 'ip_address' => '198.18.20.11']);

        $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'yil sonu denetimi',
        ])->assertOk();

        $rows = ActivityLog::query()->where('action', 'exported')->get();

        /*
         * Özet tek satır DEĞİL: denetimin sorduğu soru "hangi parola dışarı
         * çıktı" ve özet satır onu cevaplamaz.
         */
        $this->assertCount(2, $rows);
        $this->assertSame('yil sonu denetimi', $rows->first()->reason);
    }

    public function test_parolasiz_kayitlar_sebebiyle_sayilir(): void
    {
        $user = $this->user(['view', 'export']);
        $this->server(['short_name' => 'DOLU']);
        $this->server(['short_name' => 'BOS', 'ip_address' => '198.18.20.12', 'connection_password' => null]);

        $response = $this->actingAs($user)->post(route('export.connections'), [
            'kapsam' => 'sunucular',
            'reason' => 'denetim',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            '1 kaydın parolası yok',
            (string) $response->headers->get('X-Nys-Ozet'),
        );
    }
}
