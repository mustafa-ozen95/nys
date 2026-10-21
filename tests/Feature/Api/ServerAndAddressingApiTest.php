<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AddressBlock;
use App\Models\ApiToken;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sunucu ve adresleme uçları.
 *
 * NEDEN EKLENDİLER: jeton ekranı bu iki kaynak için yetki vermeye izin
 * veriyordu ama arkalarında hiçbir uç yoktu. API tutmadığı bir söz
 * veriyordu.
 *
 * İKİ ŞEY BİRDEN SINANIR: ucun çalıştığı VE jetonun sahibini aşamadığı.
 * İkincisi olmadan ilki bir şey ifade etmez.
 */
class ServerAndAddressingApiTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN = 'sinama-jetonu-0123456789abcdef';

    /** @param array<string, array<int, string>> $abilities */
    private function jeton(array $abilities, ?User $owner = null): ApiToken
    {
        return ApiToken::factory()->withPlain(self::PLAIN)->create([
            'user_id' => ($owner ?? User::factory()->admin()->create())->id,
            'abilities' => $abilities,
            'allowed_ips' => [],
        ]);
    }

    /** @return array<string, string> */
    private function baslik(): array
    {
        return ['Authorization' => 'Bearer '.self::PLAIN];
    }

    #[Test]
    public function sunucu_listesi_doner_ve_parola_icermez(): void
    {
        $this->jeton(['servers' => ['view']]);

        $sunucu = Server::factory()->create([
            'short_name' => 'WIN-01',
            'ip_address' => '198.18.20.80',
            'connection_username' => 'yonetici',
            'connection_password' => 'gizli-parola',
        ]);

        $yanit = $this->getJson('/api/v1/servers', $this->baslik())
            ->assertOk()
            ->assertJsonPath('data.0.short_name', 'WIN-01')
            ->assertJsonPath('data.0.ip_address', '198.18.20.80')
            ->assertJsonPath('data.0.id', $sunucu->uuid);

        /*
         * PAROLA VE KULLANICI ADI HİÇBİR YERDE GEÇMEMELİ. İkisi birlikte
         * sunucuya giriş demek; jeton sızarsa sunucu erişimi de sızardı.
         */
        $govde = $yanit->getContent();

        $this->assertStringNotContainsString('gizli-parola', $govde);
        $this->assertStringNotContainsString('yonetici', $govde);
    }

    #[Test]
    public function sunucu_yetkisi_olmayan_jeton_reddedilir(): void
    {
        /* Sahibi yönetici ama jetonun listesinde sunucu YOK. */
        $this->jeton(['switches' => ['view']]);

        Server::factory()->create();

        $this->getJson('/api/v1/servers', $this->baslik())->assertForbidden();
    }

    #[Test]
    public function adresleme_bloklari_atanabilirligi_soyler(): void
    {
        $this->jeton(['addressing' => ['view']]);

        AddressBlock::factory()->create([
            'network' => '10.9.0.0',
            'prefix_length' => 24,
            'connection_type' => null,
        ]);

        $this->getJson('/api/v1/addressing/blocks', $this->baslik())
            ->assertOk()
            ->assertJsonPath('data.0.cidr', '10.9.0.0/24')
            ->assertJsonPath('data.0.assignable', true);
    }

    /**
     * ROUTE EDİLMİŞ BLOK TÜKENMİŞTİR.
     *
     * Panelde bu blok artık hiçbir listede seçilemiyor. Dışarıdan bakan bir
     * istemci bunu bilmeden adres seçerse, panelin tam da önlemeye çalıştığı
     * çakışma dışarıda üretilir.
     */
    #[Test]
    public function route_edilmis_blok_atanamaz_gorunur(): void
    {
        $this->jeton(['addressing' => ['view']]);

        AddressBlock::factory()->create([
            'network' => '10.9.1.0',
            'prefix_length' => 29,
            'connection_type' => 'vlan',
        ]);

        $this->getJson('/api/v1/addressing/blocks', $this->baslik())
            ->assertOk()
            ->assertJsonPath('data.0.assignable', false);
    }

    #[Test]
    public function blok_icindeki_adresler_kullanilan_ve_bosu_ayirir(): void
    {
        $this->jeton(['addressing' => ['view']]);

        $blok = AddressBlock::factory()->create([
            'network' => '10.9.2.0',
            'prefix_length' => 29,
            'connection_type' => null,
        ]);

        $yanit = $this->getJson("/api/v1/addressing/blocks/{$blok->uuid}/hosts", $this->baslik())
            ->assertOk()
            ->assertJsonPath('data.cidr', '10.9.2.0/29')
            ->assertJsonPath('data.limited', false);

        $veri = $yanit->json('data');

        $this->assertIsArray($veri['addresses']);
        $this->assertSame($veri['total'] - $veri['used'], $veri['free']);
    }

    #[Test]
    public function adresleme_yetkisi_olmayan_jeton_reddedilir(): void
    {
        $this->jeton(['switches' => ['view']]);

        AddressBlock::factory()->create();

        $this->getJson('/api/v1/addressing/blocks', $this->baslik())->assertForbidden();
    }

    #[Test]
    public function switchin_route_tablosu_doner(): void
    {
        $this->jeton(['switch_routes' => ['view']]);

        $switch = NetworkSwitch::factory()->create();
        SwitchRoute::factory()->create([
            'switch_id' => $switch->id,
            'destination' => '0.0.0.0',
            'prefix_length' => 0,
            'next_hop' => '198.18.20.1',
        ]);

        $this->getJson("/api/v1/switches/{$switch->uuid}/routes", $this->baslik())
            ->assertOk()
            ->assertJsonPath('data.0.destination', '0.0.0.0/0')
            ->assertJsonPath('data.0.next_hop', '198.18.20.1');
    }

    /**
     * MD5 OTURUM ANAHTARI SIZMAMALI.
     *
     * Model onu `$hidden` tutuyor ama kaynak sınıfı alanları tek tek
     * yazıyor; bu sınama, yarın "hepsini dök" diye kısayola sapıldığında
     * kırılsın diye var.
     */
    #[Test]
    public function bgp_komsulari_doner_ve_oturum_anahtari_sizmaz(): void
    {
        $this->jeton(['switch_bgp' => ['view']]);

        $switch = NetworkSwitch::factory()->create();
        $grup = BgpGroup::factory()->create([
            'switch_id' => $switch->id,
            'name' => 'ust-katman',
            'authentication_key' => 'cok-gizli-md5-anahtari',
        ]);

        BgpNeighbor::factory()->create([
            'switch_id' => $switch->id,
            'bgp_group_id' => $grup->id,
            'address' => '10.0.0.1',
            'authentication_key' => 'komsuya-ozel-anahtar',
        ]);

        $yanit = $this->getJson("/api/v1/switches/{$switch->uuid}/bgp-neighbors", $this->baslik())
            ->assertOk()
            ->assertJsonPath('data.0.address', '10.0.0.1')
            ->assertJsonPath('data.0.group', 'ust-katman');

        $govde = $yanit->getContent();

        $this->assertStringNotContainsString('cok-gizli-md5-anahtari', $govde);
        $this->assertStringNotContainsString('komsuya-ozel-anahtar', $govde);
    }
}
