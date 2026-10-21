<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Services\Netconf\AnalyzerSynchronizer;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\SecureAccessPortSynchronizer;
use App\Services\Netconf\StormControlSynchronizer;
use App\Services\Netconf\Transport\NetconfTransport;
use App\Services\Netconf\VoipSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Okuma süzgeci LEHÇEYE göre kurulur.
 *
 * Junos, olmayan bir kök istendiğinde onu boş döndürmez: bütün isteği
 * reddeder ("syntax error, expecting </configuration>"). Bir okuyucu ELS
 * cihazından "ethernet-switching-options" isterse cihaz hiç eşitlenmez —
 * ekranda eski veri durur, hata yalnızca cihaz işlem kaydında görünür.
 *
 * Bu testler o kökün ELS isteklerine bir daha girmemesini kilitler. Sahte
 * cihaz her şeye cevap verdiği için suite bunu KENDİLİĞİNDEN görmüyor;
 * kontrol edilen şey gönderilen RPC'nin kendisidir.
 */
final class OkumaSuzgeciTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: class-string, 1: string}>
     */
    public static function esitleyiciler(): array
    {
        return [
            'fırtına denetimi' => [StormControlSynchronizer::class, 'storm-control'],
            'VoIP' => [VoipSynchronizer::class, 'voip'],
            'port aynalama' => [AnalyzerSynchronizer::class, 'analyzer'],
            'güvenli erişim' => [SecureAccessPortSynchronizer::class, 'secure-access'],
        ];
    }

    /**
     * @param  class-string  $esitleyici
     */
    #[DataProvider('esitleyiciler')]
    public function test_els_cihazdan_olmayan_kok_istenmez(string $esitleyici, string $etiket): void
    {
        $switch = $this->switch(JunosSchemaProfile::Els);
        $device = $this->device();

        app(NetconfClient::class)->run(
            $switch,
            fn (NetconfSession $session) => app($esitleyici)->sync($switch, $session),
        );

        $this->assertStringNotContainsString(
            '<ethernet-switching-options/>',
            implode('', $device->written),
            "{$etiket}: ELS cihazdan olmayan kök istendi; cihaz bütün eşitlemeyi reddeder.",
        );
    }

    /**
     * @param  class-string  $esitleyici
     */
    #[DataProvider('esitleyiciler')]
    public function test_els_oncesi_cihazdan_kok_istenmeye_devam_eder(string $esitleyici, string $etiket): void
    {
        $switch = $this->switch(JunosSchemaProfile::PreEls);
        $device = $this->device();

        app(NetconfClient::class)->run(
            $switch,
            fn (NetconfSession $session) => app($esitleyici)->sync($switch, $session),
        );

        $this->assertStringContainsString(
            '<ethernet-switching-options/>',
            implode('', $device->written),
            "{$etiket}: ELS öncesinde ayar ORADA duruyor; kök istenmezse envanter boş kalır.",
        );
    }

    /**
     * Şeması BİLİNMEYEN cihaz ELS öncesi sayılır: daraltma bir varsayımla
     * yapılmaz, aksi hâlde ayarlar sessizce okunmaz olurdu.
     */
    public function test_semasi_bilinmeyen_cihaz_els_oncesi_sayilir(): void
    {
        $switch = $this->switch(null);
        $device = $this->device();

        app(NetconfClient::class)->run(
            $switch,
            fn (NetconfSession $session) => app(StormControlSynchronizer::class)->sync($switch, $session),
        );

        $this->assertStringContainsString('<ethernet-switching-options/>', implode('', $device->written));
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(?JunosSchemaProfile $profile): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.20.195',
            'schema_profile' => $profile,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
