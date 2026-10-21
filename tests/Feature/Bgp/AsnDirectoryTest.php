<?php

declare(strict_types=1);

namespace Tests\Feature\Bgp;

use App\Models\NetworkSwitch;
use App\Services\Bgp\AsnDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anons ekranının ASN listesi.
 *
 * Kaynak switchlerin cihazdan okunan kendi AS numaralarıdır. Liste bir
 * KOLAYLIKTIR: anons müşteri ASN'si için de yapılabildiğinden alan listeye
 * kilitli değildir (bkz. AnonsAsnTest).
 */
final class AsnDirectoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "65000" gibi sayısal bir dize PHP'de dizi anahtarı olurken int'e döner.
     * default() bunu dize olarak vermezse tip sözleşmesi kırılır ve anons
     * ekranı 500 verir.
     */
    public function test_sayisal_as_numarasi_dize_olarak_doner(): void
    {
        NetworkSwitch::factory()->create(['autonomous_system' => '65000']);

        $directory = app(AsnDirectory::class);

        $this->assertSame('AS65000', $directory->default());
        $this->assertContains('AS65000', $directory->options());
    }

    public function test_as_numarasi_olmayan_switchler_listelenmez(): void
    {
        NetworkSwitch::factory()->create(['autonomous_system' => null]);
        NetworkSwitch::factory()->create(['autonomous_system' => '65001']);

        $this->assertSame(['AS65001'], array_values(app(AsnDirectory::class)->options()));
    }

    /**
     * Cihazdan "65000", yapılandırmadan "AS64496" gelebiliyor. Liste tek
     * yazım taşır; aksi hâlde aynı numara iki satır olurdu ve seçili değer
     * listede bulunamazdı.
     */
    public function test_liste_tek_yazim_tasir(): void
    {
        NetworkSwitch::factory()->create(['autonomous_system' => '65000']);
        NetworkSwitch::factory()->create(['autonomous_system' => 'AS65000']);
        NetworkSwitch::factory()->create(['autonomous_system' => 'as65001']);

        $this->assertSame(['AS65000', 'AS65001'], array_values(app(AsnDirectory::class)->options()));
    }

    /**
     * Hiçbir switchte AS numarası yoksa yapılandırmadaki varsayılana düşülür;
     * anons ekranı boş listeyle açılmamalı.
     */
    public function test_hicbir_switchte_as_yoksa_varsayilana_dusulur(): void
    {
        config(['announcements.default_asn' => 'AS64496']);

        $this->assertSame('AS64496', app(AsnDirectory::class)->default());
    }
}
