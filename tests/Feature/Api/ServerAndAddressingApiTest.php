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
    private function token(array $abilities, ?User $owner = null): ApiToken
    {
        return ApiToken::factory()->withPlain(self::PLAIN)->create([
            'user_id' => ($owner ?? User::factory()->admin()->create())->id,
            'abilities' => $abilities,
            'allowed_ips' => [],
        ]);
    }

    /** @return array<string, string> */
    private function header(): array
    {
        return ['Authorization' => 'Bearer '.self::PLAIN];
    }

    #[Test]
    public function sunucu_listesi_doner_ve_parola_icermez(): void
    {
        $this->token(['servers' => ['view']]);

        $server = Server::factory()->create([
            'short_name' => 'WIN-01',
            'ip_address' => '198.18.20.80',
            'connection_username' => 'yonetici',
            'connection_password' => 'gizli-parola',
        ]);

        $response = $this->getJson('/api/v1/servers', $this->header())
            ->assertOk()
            ->assertJsonPath('data.0.short_name', 'WIN-01')
            ->assertJsonPath('data.0.ip_address', '198.18.20.80')
            ->assertJsonPath('data.0.id', $server->uuid);

        /*
         * PAROLA VE KULLANICI ADI HİÇBİR YERDE GEÇMEMELİ. İkisi birlikte
         * sunucuya giriş demek; jeton sızarsa sunucu erişimi de sızardı.
         */
        $body = $response->getContent();

        $this->assertStringNotContainsString('gizli-parola', $body);
        $this->assertStringNotContainsString('yonetici', $body);
    }

    #[Test]
    public function sunucu_yetkisi_olmayan_jeton_reddedilir(): void
    {
        /* Sahibi yönetici ama jetonun listesinde sunucu YOK. */
        $this->token(['switches' => ['view']]);

        Server::factory()->create();

        $this->getJson('/api/v1/servers', $this->header())->assertForbidden();
    }

    #[Test]
    public function adresleme_bloklari_atanabilirligi_soyler(): void
    {
        $this->token(['addressing' => ['view']]);

        AddressBlock::factory()->create([
            'network' => '10.9.0.0',
            'prefix_length' => 24,
            'connection_type' => null,
        ]);

        $this->getJson('/api/v1/addressing/blocks', $this->header())
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
        $this->token(['addressing' => ['view']]);

        AddressBlock::factory()->create([
            'network' => '10.9.1.0',
            'prefix_length' => 29,
            'connection_type' => 'vlan',
        ]);

        $this->getJson('/api/v1/addressing/blocks', $this->header())
            ->assertOk()
            ->assertJsonPath('data.0.assignable', false);
    }

    #[Test]
    public function blok_icindeki_adresler_kullanilan_ve_bosu_ayirir(): void
    {
        $this->token(['addressing' => ['view']]);

        $block = AddressBlock::factory()->create([
            'network' => '10.9.2.0',
            'prefix_length' => 29,
            'connection_type' => null,
        ]);

        $response = $this->getJson("/api/v1/addressing/blocks/{$block->uuid}/hosts", $this->header())
            ->assertOk()
            ->assertJsonPath('data.cidr', '10.9.2.0/29')
            ->assertJsonPath('data.limited', false);

        $veri = $response->json('data');

        $this->assertIsArray($veri['addresses']);
        $this->assertSame($veri['total'] - $veri['used'], $veri['free']);
    }

    #[Test]
    public function adresleme_yetkisi_olmayan_jeton_reddedilir(): void
    {
        $this->token(['switches' => ['view']]);

        AddressBlock::factory()->create();

        $this->getJson('/api/v1/addressing/blocks', $this->header())->assertForbidden();
    }

    #[Test]
    public function switchin_route_tablosu_doner(): void
    {
        $this->token(['switch_routes' => ['view']]);

        $switch = NetworkSwitch::factory()->create();
        SwitchRoute::factory()->create([
            'switch_id' => $switch->id,
            'destination' => '0.0.0.0',
            'prefix_length' => 0,
            'next_hop' => '198.18.20.1',
        ]);

        $this->getJson("/api/v1/switches/{$switch->uuid}/routes", $this->header())
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
        $this->token(['switch_bgp' => ['view']]);

        $switch = NetworkSwitch::factory()->create();
        $group = BgpGroup::factory()->create([
            'switch_id' => $switch->id,
            'name' => 'ust-katman',
            'authentication_key' => 'cok-gizli-md5-anahtari',
        ]);

        BgpNeighbor::factory()->create([
            'switch_id' => $switch->id,
            'bgp_group_id' => $group->id,
            'address' => '10.0.0.1',
            'authentication_key' => 'komsuya-ozel-anahtar',
        ]);

        $response = $this->getJson("/api/v1/switches/{$switch->uuid}/bgp-neighbors", $this->header())
            ->assertOk()
            ->assertJsonPath('data.0.address', '10.0.0.1')
            ->assertJsonPath('data.0.group', 'ust-katman');

        $body = $response->getContent();

        $this->assertStringNotContainsString('cok-gizli-md5-anahtari', $body);
        $this->assertStringNotContainsString('komsuya-ozel-anahtar', $body);
    }
}
