<?php

declare(strict_types=1);

namespace Tests\Feature\Logs;

use App\Enums\CredentialProtocol;
use App\Models\DeviceOperation;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;
use App\Services\Netconf\VlanConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihaz bir işlemi reddettiğinde İZ KALMALI.
 *
 * Yalnız bir toast yetmez: sayfa kapanınca toast gider. Ne gönderdiğimiz,
 * cihazın tam olarak ne dediği, hangi adımda düştüğü kayıtta saklanır.
 */
final class DeviceOperationLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /* Bu sınıf yetki düzeneğini sınıyor; üretimde kapalı (bkz. TestCase). */
        $this->permissionDistributionOpen();
    }

    public function test_cihazin_reddettigi_yazma_kayda_gecer(): void
    {
        $switch = $this->switch();

        // Cihaz yüklemeyi reddediyor.
        $this->app->instance(NetconfTransport::class, new FakeJunosDevice(
            loadError: 'syntax error',
        ));

        try {
            app(VlanConfigurator::class)->create($switch, 'kamera', 30, null, [], null);
        } catch (NetconfException) {
            // Beklenen: cihaz reddetti.
        }

        $record = DeviceOperation::query()->latest('id')->first();

        $this->assertNotNull($record, 'Cihazın reddi hiçbir yere yazılmadı.');
        $this->assertSame('failed', $record->status);
        $this->assertSame('write', $record->operation);
        $this->assertSame('load', $record->stage, 'Hangi adımda düştüğü kaydedilmeli.');
        $this->assertSame('rpc', $record->kind);
        $this->assertStringContainsString('syntax error', (string) $record->device_message);
        $this->assertSame($switch->id, $record->switch_id);

        // Gönderilen belge de saklanır: "ne gönderdik" cevapsız kalmamalı.
        $this->assertStringContainsString('kamera', (string) $record->document);
    }

    /**
     * Her rpc-error'un kendi alanları korunur. Tek dizeye indirildiğinde
     * sorunu bulan bilgi (hangi satır, hangi süreç) kayboluyordu.
     */
    public function test_hatanin_yeri_ve_sureci_saklanir(): void
    {
        $switch = $this->switch();

        $this->app->instance(NetconfTransport::class, new FakeJunosDevice(
            loadError: 'statement not found',
        ));

        try {
            app(VlanConfigurator::class)->create($switch, 'kamera', 30, null, [], null);
        } catch (NetconfException) {
            //
        }

        $errors = DeviceOperation::query()->latest('id')->first()?->errors;

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('message', $errors[0]);
        $this->assertArrayHasKey('severity', $errors[0]);
        $this->assertArrayHasKey('path', $errors[0]);
        $this->assertArrayHasKey('daemon', $errors[0]);
    }

    public function test_ekran_yalnizca_yetkiliye_acilir(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('logs.device-operations'))
            ->assertForbidden();
    }

    public function test_ekran_kayitlari_listeler(): void
    {
        $switch = $this->switch();

        DeviceOperation::query()->create([
            'switch_id' => $switch->id,
            'target' => (string) $switch->hostname,
            'operation' => 'write',
            'stage' => 'check',
            'status' => 'failed',
            'kind' => 'rpc',
            'summary' => 'vlan eklendi: kamera',
            'device_message' => 'Access interface has more than one vlan member',
        ]);

        $this->actingAs($this->auditor())
            ->get(route('logs.device-operations'))
            ->assertOk()
            ->assertSee('Access interface has more than one vlan member')
            ->assertSee('Başarısız');
    }

    /**
     * Kayıt yazmak asıl işi BOZMAMALI: kayıt tablosu yazılamıyorsa bile
     * cihazın hatası kullanıcıya ulaşmalı.
     */
    public function test_kayit_yazilamasa_da_hata_yukari_cikar(): void
    {
        $switch = $this->switch();

        $this->app->instance(NetconfTransport::class, new FakeJunosDevice(loadError: 'syntax error'));

        Schema::drop('device_operations');

        $this->expectException(NetconfException::class);

        app(VlanConfigurator::class)->create($switch, 'kamera', 30, null, [], null);
    }

    public function test_yeni_adres_acilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/logs/device-operations')
            ->assertOk()
            ->assertSee('Hata kayıtları');
    }

    /**
     * Menü HATA kayıtlarına açılır: en çok aranan ekran o. Etiket açtığı
     * şeyi söylemeli — "Kayıtlar" deyip hata ekranını açmak adı yalan
     * yapardı.
     */
    public function test_ust_menu_kayitlar_adiyla_hata_ekranina_gider(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('overview'))
            ->assertOk();

        $response->assertSee('Kayıtlar');
        $response->assertSee(route('logs.device-operations'), false);
    }

    /**
     * Etkinlik kaydı menüden düşmedi: iki ekran birbirine bağlı ve ikisi de
     * ulaşılabilir kalmalı.
     */
    public function test_iki_ekran_birbirine_bagli(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('logs.device-operations'))
            ->assertOk()
            ->assertSee(route('logs.index'), false);

        $this->actingAs($admin)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee(route('logs.device-operations'), false);
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    /**
     * Kaydın TAMAMINI gören denetçi.
     *
     * Ekranın listeleme davranışı bununla ölçülür; kimin hangi cihazın
     * kaydını görebildiği ayrı bir sorudur ve kendi testi aşağıda.
     */
    private function auditor(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * GÖREMEDİĞİ CİHAZIN İŞLEM KAYDI DA GÖRÜNMEZ.
     *
     * Süzülmediğinde `logs.view` yetkisi olan biri, göremediği switchlerin
     * yazma geçmişini ve süzgeçte bütün envanterin adlarını görüyordu.
     */
    public function test_goremedigi_cihazin_islemi_gorunmez(): void
    {
        $visible = NetworkSwitch::factory()->create(['hostname' => 'GORUNUR-SW']);
        $hidden = NetworkSwitch::factory()->create(['hostname' => 'GIZLI-SW']);

        $reader = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($reader, 'logs', ['view']);
        app(UserPermissionResolver::class)->syncResource($reader, 'switches', [], ['view' => [$visible->id]]);

        foreach ([[$visible, 'GORUNUR ISLEM'], [$hidden, 'GIZLI ISLEM']] as [$device, $summary]) {
            DeviceOperation::query()->create([
                'switch_id' => $device->id,
                'target' => $device->hostname,
                'operation' => 'write',
                'stage' => 'commit',
                'status' => 'ok',
                'summary' => $summary,
            ]);
        }

        $this->actingAs($reader->fresh())
            ->get(route('logs.device-operations'))
            ->assertOk()
            ->assertSee('GORUNUR ISLEM')
            ->assertDontSee('GIZLI ISLEM')
            ->assertDontSee('GIZLI-SW');
    }
}
