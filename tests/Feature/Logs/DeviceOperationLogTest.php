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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->yetkiDagitimiAc();
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

        $kayit = DeviceOperation::query()->latest('id')->first();

        $this->assertNotNull($kayit, 'Cihazın reddi hiçbir yere yazılmadı.');
        $this->assertSame('failed', $kayit->status);
        $this->assertSame('write', $kayit->operation);
        $this->assertSame('load', $kayit->stage, 'Hangi adımda düştüğü kaydedilmeli.');
        $this->assertSame('rpc', $kayit->kind);
        $this->assertStringContainsString('syntax error', (string) $kayit->device_message);
        $this->assertSame($switch->id, $kayit->switch_id);

        // Gönderilen belge de saklanır: "ne gönderdik" cevapsız kalmamalı.
        $this->assertStringContainsString('kamera', (string) $kayit->document);
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

        $this->actingAs($this->denetci())
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

    /**
     * Adres "kayitlar/cihaz-islemleri" idi; ekran, onda ARANAN şeyin adıyla
     * yeniden adlandırıldı. Eski adres ölü bırakılmadı: yer imleri çalışmaya devam
     * eder.
     */
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function eskiAdresler(): array
    {
        return [
            'cihaz islemleri' => ['/kayitlar/cihaz-islemleri', 'logs.device-operations'],
            'kısa hata adresi' => ['/hata-kayitlari', 'logs.device-operations'],

            /*
             * "/kayitlar" ETKİNLİK kayıtlarıydı ve oraya gider: eski bir yer
             * imini başka bir ekrana açmak, sessizce yanlış sayfayı
             * göstermek olurdu.
             */
            'eski kayıtlar' => ['/kayitlar', 'logs.index'],
        ];
    }

    #[DataProvider('eskiAdresler')]
    public function test_eski_adres_dogru_ekrana_yonlendirir(string $eski, string $hedef): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($eski)
            ->assertRedirect(route($hedef));
    }

    public function test_yeni_adres_acilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/kayitlar/hata-kayitlari')
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
        $yanit = $this->actingAs(User::factory()->admin()->create())
            ->get(route('overview'))
            ->assertOk();

        $yanit->assertSee('Kayıtlar');
        $yanit->assertSee(route('logs.device-operations'), false);
    }

    /**
     * Etkinlik kaydı menüden düşmedi: iki ekran birbirine bağlı ve ikisi de
     * ulaşılabilir kalmalı.
     */
    public function test_iki_ekran_birbirine_bagli(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)
            ->get(route('logs.device-operations'))
            ->assertOk()
            ->assertSee(route('logs.index'), false);

        $this->actingAs($yonetici)
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
    private function denetci(): User
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
        $gorunur = NetworkSwitch::factory()->create(['hostname' => 'GORUNUR-SW']);
        $gizli = NetworkSwitch::factory()->create(['hostname' => 'GIZLI-SW']);

        $okuyucu = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($okuyucu, 'logs', ['view']);
        app(UserPermissionResolver::class)->syncResource($okuyucu, 'switches', [], ['view' => [$gorunur->id]]);

        foreach ([[$gorunur, 'GORUNUR ISLEM'], [$gizli, 'GIZLI ISLEM']] as [$cihaz, $ozet]) {
            DeviceOperation::query()->create([
                'switch_id' => $cihaz->id,
                'target' => $cihaz->hostname,
                'operation' => 'write',
                'stage' => 'commit',
                'status' => 'ok',
                'summary' => $ozet,
            ]);
        }

        $this->actingAs($okuyucu->fresh())
            ->get(route('logs.device-operations'))
            ->assertOk()
            ->assertSee('GORUNUR ISLEM')
            ->assertDontSee('GIZLI ISLEM')
            ->assertDontSee('GIZLI-SW');
    }
}
