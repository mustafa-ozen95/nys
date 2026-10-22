<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\IpFamily;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\Vlan;
use App\Services\Addressing\AddressCandidateService;
use App\Services\Addressing\AddressLayoutService;
use App\Support\Net\Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bir bloğun içinde ne kullanılıyor, ne boşta.
 *
 * AMAÇ ÇAKIŞMAYI ÖNLEMEK. Liste tanımlı alt ağların arasındaki boşlukları da
 * gösterir; göstermeseydi "bu /24'ün neresi boş" sorusu elle hesaplanır ve
 * aynı aralık ikinci kez verilebilirdi.
 */
final class BlockPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function block(string $network, int $mask, array $onto = []): AddressBlock
    {
        $cidr = Cidr::make($network, $mask);

        return AddressBlock::factory()->create([
            'network' => $cidr->network,
            'prefix_length' => $mask,
            'range_start' => $cidr->addressStart(),
            'range_end' => $cidr->addressEnd(),
            'family' => IpFamily::Inet,
            ...$onto,
        ]);
    }

    public function test_alt_aglar_ve_aralarindaki_bosluklar_sirayla_listelenir(): void
    {
        $upper = $this->block('10.0.0.0', 24, ['is_subnetted' => true]);

        /* Ortadan bir /29 verilmiş; öncesi ve sonrası boş. */
        $this->block('10.0.0.8', 29, ['parent_id' => $upper->id]);

        $rows = app(AddressLayoutService::class)->network($upper->fresh());

        $this->assertSame(['bosluk', 'alt-ag', 'bosluk'], array_column($rows, 'tur'));

        /* Baştaki boşluk: 10.0.0.0 – 10.0.0.7 */
        $this->assertSame(['10.0.0.0/29'], $rows[0]['parcalar']);
        $this->assertSame('10.0.0.8/29', $rows[1]['cidr']);

        /*
         * Sondaki boşluk düzgün bir CIDR DEĞİL: 10.0.0.16'dan 255'e kadar.
         * Kullanıcı oraya alt ağ açacak, hangi maskelerin sığdığını görmeli.
         */
        $this->assertSame(
            ['10.0.0.16/28', '10.0.0.32/27', '10.0.0.64/26', '10.0.0.128/25'],
            $rows[2]['parcalar'],
        );
    }

    public function test_bolunmemis_blokta_adresi_kimin_tuttugu_yazar(): void
    {
        $block = $this->block('10.0.0.8', 29);

        Server::factory()->create(['short_name' => 'WEB-01', 'ip_address' => '10.0.0.10']);

        $result = app(AddressLayoutService::class)->hosts($block);

        $this->assertFalse($result['sinirli']);
        $this->assertSame(6, $result['toplam']);
        $this->assertSame(1, $result['dolu']);

        $kept = collect($result['adresler'])->firstWhere('adres', '10.0.0.10');

        $this->assertSame('WEB-01', $kept['sahip']);
        $this->assertSame('sunucu', $kept['tur']);

        /* Ötekiler boşta: "sahip" yok. */
        $this->assertNull(collect($result['adresler'])->firstWhere('adres', '10.0.0.11')['sahip']);
    }

    /** Geniş blokta adresler tek tek basılmaz; ekran da tarayıcı da kilitlenirdi. */
    public function test_genis_blokta_host_listesi_kisitlanir(): void
    {
        $result = app(AddressLayoutService::class)->hosts($this->block('10.1.0.0', 16));

        $this->assertTrue($result['sinirli']);
        $this->assertSame([], $result['adresler']);
    }

    /**
     * ATANMIŞ BLOK BAŞKA YERE ATANAMAZ.
     *
     * Bir /29 erişim switchine route edilip VLAN'ına bağlandığında o aralık
     * orada kullanılıyordur; başka bir cihazın listesinde görünmesi, aynı ağı
     * iki yerden route etmenin yoludur.
     */
    public function test_atanmis_blok_baska_switchin_listesinde_gorunmez(): void
    {
        $access = NetworkSwitch::factory()->create(['hostname' => 'ERISIM-1', 'role' => SwitchRole::Access]);
        $backbone = NetworkSwitch::factory()->create(['hostname' => 'OMURGA-1', 'role' => SwitchRole::Core]);
        $vlan = Vlan::factory()->create(['switch_id' => $access->id]);

        $assigned = $this->block('10.0.0.8', 29, [
            'connection_type' => AddressConnectionType::Vlan,
            'access_switch_id' => $access->id,
            'vlan_id' => $vlan->id,
        ]);

        $empty = $this->block('10.0.1.0', 29);

        $options = app(AddressCandidateService::class);

        /* Başka bir omurgada: yalnızca boş blok. */
        $this->assertSame(['10.0.1.0/29'], array_keys($options->blockOptions(switch: $backbone)));

        /* Sahibinin kendi ekranında ikisi de: orada düzenlenebilmeli. */
        $this->assertEqualsCanonicalizing(
            ['10.0.0.8/29', '10.0.1.0/29'],
            array_keys($options->blockOptions(switch: $access)),
        );

        /* Switch verilmeden sorulursa atanmış olan yine listede yok. */
        $this->assertSame(['10.0.1.0/29'], array_keys($options->blockOptions()));

        $this->assertNotNull($assigned->connection_type);
        $this->assertNull($empty->connection_type);
    }

    /** Bölünmüş blok bir havuzdur: adres taşımaz, atanacak olan alt ağlarıdır. */
    public function test_bolunmus_blok_atanabilir_listede_yoktur(): void
    {
        $this->block('10.0.0.0', 24, ['is_subnetted' => true]);

        $this->assertSame([], array_keys(app(AddressCandidateService::class)->blockOptions()));
    }
}
