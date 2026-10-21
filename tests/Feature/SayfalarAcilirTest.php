<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Duman testi: yetkili bir kullanıcı her sayfayı 200 ile açabilmeli.
 *
 * Eksik import, yanlış tip ipucu, view'da tanımsız değişken gibi ancak
 * çalışma anında ortaya çıkan hatalar burada yakalanır. Sayfa içeriğini
 * ayrıntılı doğrulamak bu testin işi değil.
 */
class SayfalarAcilirTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Portlar sayfası anlık trafiği Zabbix'ten okur. Testte gerçek istek
     * gitmemeli; boş sonuç yeterli, sayfanın envanter kısmı etkilenmiyor.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['result' => []])]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sayfalar(): array
    {
        return [
            'genel bakış' => ['/'],
            'switchler' => ['/switchler'],
            'adresleme' => ['/adresleme'],
            'bgp' => ['/bgp'],
            'prefix anonsu' => ['/prefix-anonsu'],
            'anons alıcıları' => ['/prefix-anonsu/alicilar'],
            'sunucular' => ['/sunucular'],
            'kullanıcılar' => ['/kullanicilar'],
            'kullanıcı ekle' => ['/kullanicilar/create'],
            'etkinlik kayıtları' => ['/kayitlar/etkinlik-kayitlari'],
            'hata kayıtları' => ['/kayitlar/hata-kayitlari'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function switchSekmeleri(): array
    {
        return [
            'genel bakış' => [''],
            'portlar' => ['/portlar'],
            'bağlantı toplama' => ['/toplama'],
            'vlanlar' => ['/vlanlar'],
            'ip adresleri' => ['/ip-adresleri'],
            'routelar' => ['/routelar'],
            'prefix listeler' => ['/prefix-listeler'],
            'firewall' => ['/firewall'],
            'politikalar' => ['/politikalar'],
            'bgp' => ['/bgp'],
            'commitler' => ['/commitler'],
        ];
    }

    #[DataProvider('sayfalar')]
    public function test_sayfa_acilir(string $yol): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get($yol)
            ->assertOk();
    }

    #[DataProvider('switchSekmeleri')]
    public function test_switch_sekmesi_acilir(string $sekme): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/switchler/{$switch->uuid}{$sekme}")
            ->assertOk();
    }

    public function test_kullanici_duzenleme_ve_yetki_sayfalari_acilir(): void
    {
        $admin = User::factory()->admin()->create();
        $hedef = User::factory()->create();

        $this->actingAs($admin)->get("/kullanicilar/{$hedef->uuid}/edit")->assertOk();
        $this->actingAs($admin)->get("/kullanicilar/{$hedef->uuid}/yetkiler")->assertOk();
    }

    /**
     * Sunucu EKLEME de ayrı bir sayfa değil.
     */
    public function test_sunucu_ekleme_adresi_listeye_yonlendirir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/sunucular/create')
            ->assertRedirect(route('servers.index', ['ekle' => 1]));
    }

    public function test_sunucu_ekleme_penceresi_listede_acilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('servers.index', ['ekle' => 1]))
            ->assertOk()
            ->assertSee('Yeni sunucu')
            ->assertSee('Sunucuyu ekle');
    }

    /**
     * Sunucu düzenleme AYRI BİR SAYFA değil, listedeki penceredir.
     *
     * Eski adres ölü bırakılmadı: listeye, seçili sunucuyla yönlendirir ve
     * pencere orada açılır. Yer imleri çalışmaya devam eder.
     */
    public function test_sunucu_duzenleme_adresi_listeye_yonlendirir(): void
    {
        $server = Server::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/sunucular/{$server->uuid}/edit")
            ->assertRedirect(route('servers.index', ['server' => $server->uuid, 'duzenle' => 1]));
    }

    public function test_sunucu_duzenleme_penceresi_listede_acilir(): void
    {
        $server = Server::factory()->create(['short_name' => 'web01']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('servers.index', ['server' => $server->uuid, 'duzenle' => 1]))
            ->assertOk()
            ->assertSee('web01 ayarları')
            ->assertSee('Değişiklikleri kaydet');
    }
}
