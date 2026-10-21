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
final class BlokYerlesimiTest extends TestCase
{
    use RefreshDatabase;

    private function blok(string $ag, int $maske, array $ustune = []): AddressBlock
    {
        $cidr = Cidr::make($ag, $maske);

        return AddressBlock::factory()->create([
            'network' => $cidr->network,
            'prefix_length' => $maske,
            'range_start' => $cidr->addressStart(),
            'range_end' => $cidr->addressEnd(),
            'family' => IpFamily::Inet,
            ...$ustune,
        ]);
    }

    public function test_alt_aglar_ve_aralarindaki_bosluklar_sirayla_listelenir(): void
    {
        $ust = $this->blok('10.0.0.0', 24, ['is_subnetted' => true]);

        /* Ortadan bir /29 verilmiş; öncesi ve sonrası boş. */
        $this->blok('10.0.0.8', 29, ['parent_id' => $ust->id]);

        $satirlar = app(AddressLayoutService::class)->network($ust->fresh());

        $this->assertSame(['bosluk', 'alt-ag', 'bosluk'], array_column($satirlar, 'tur'));

        /* Baştaki boşluk: 10.0.0.0 – 10.0.0.7 */
        $this->assertSame(['10.0.0.0/29'], $satirlar[0]['parcalar']);
        $this->assertSame('10.0.0.8/29', $satirlar[1]['cidr']);

        /*
         * Sondaki boşluk düzgün bir CIDR DEĞİL: 10.0.0.16'dan 255'e kadar.
         * Kullanıcı oraya alt ağ açacak, hangi maskelerin sığdığını görmeli.
         */
        $this->assertSame(
            ['10.0.0.16/28', '10.0.0.32/27', '10.0.0.64/26', '10.0.0.128/25'],
            $satirlar[2]['parcalar'],
        );
    }

    public function test_bolunmemis_blokta_adresi_kimin_tuttugu_yazar(): void
    {
        $blok = $this->blok('10.0.0.8', 29);

        Server::factory()->create(['short_name' => 'WEB-01', 'ip_address' => '10.0.0.10']);

        $sonuc = app(AddressLayoutService::class)->hosts($blok);

        $this->assertFalse($sonuc['sinirli']);
        $this->assertSame(6, $sonuc['toplam']);
        $this->assertSame(1, $sonuc['dolu']);

        $tutulan = collect($sonuc['adresler'])->firstWhere('adres', '10.0.0.10');

        $this->assertSame('WEB-01', $tutulan['sahip']);
        $this->assertSame('sunucu', $tutulan['tur']);

        /* Ötekiler boşta: "sahip" yok. */
        $this->assertNull(collect($sonuc['adresler'])->firstWhere('adres', '10.0.0.11')['sahip']);
    }

    /** Geniş blokta adresler tek tek basılmaz; ekran da tarayıcı da kilitlenirdi. */
    public function test_genis_blokta_host_listesi_kisitlanir(): void
    {
        $sonuc = app(AddressLayoutService::class)->hosts($this->blok('10.1.0.0', 16));

        $this->assertTrue($sonuc['sinirli']);
        $this->assertSame([], $sonuc['adresler']);
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
        $erisim = NetworkSwitch::factory()->create(['hostname' => 'ERISIM-1', 'role' => SwitchRole::Access]);
        $omurga = NetworkSwitch::factory()->create(['hostname' => 'OMURGA-1', 'role' => SwitchRole::Core]);
        $vlan = Vlan::factory()->create(['switch_id' => $erisim->id]);

        $atanmis = $this->blok('10.0.0.8', 29, [
            'connection_type' => AddressConnectionType::Vlan,
            'access_switch_id' => $erisim->id,
            'vlan_id' => $vlan->id,
        ]);

        $bos = $this->blok('10.0.1.0', 29);

        $secenekler = app(AddressCandidateService::class);

        /* Başka bir omurgada: yalnızca boş blok. */
        $this->assertSame(['10.0.1.0/29'], array_keys($secenekler->blockOptions(switch: $omurga)));

        /* Sahibinin kendi ekranında ikisi de: orada düzenlenebilmeli. */
        $this->assertEqualsCanonicalizing(
            ['10.0.0.8/29', '10.0.1.0/29'],
            array_keys($secenekler->blockOptions(switch: $erisim)),
        );

        /* Switch verilmeden sorulursa atanmış olan yine listede yok. */
        $this->assertSame(['10.0.1.0/29'], array_keys($secenekler->blockOptions()));

        $this->assertNotNull($atanmis->connection_type);
        $this->assertNull($bos->connection_type);
    }

    /** Bölünmüş blok bir havuzdur: adres taşımaz, atanacak olan alt ağlarıdır. */
    public function test_bolunmus_blok_atanabilir_listede_yoktur(): void
    {
        $this->blok('10.0.0.0', 24, ['is_subnetted' => true]);

        $this->assertSame([], array_keys(app(AddressCandidateService::class)->blockOptions()));
    }
}
