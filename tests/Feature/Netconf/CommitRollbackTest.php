<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommit;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Commit geçmişinden geri alma.
 *
 * Geri alma panelin en geniş etkili yazma yoludur: cihazın TAMAMI eski
 * hâline döner. Bu yüzden iki adımdır (önce fark, sonra commit) ve hedef
 * commit KİMLİĞİYLE seçilir — Junos'ta rollback numarası her yeni commit'te
 * kayar ve ekrandaki numara ile cihazdaki commit ayrışabilir.
 */
final class CommitRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_fark_onizlemesi_commit_atmaz(): void
    {
        $switch = $this->switch();
        $commit = $this->commit($switch, sequence: 1, minutesAgo: 60);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.commits.preview', [$switch, $commit]))
            ->assertRedirect()
            ->assertSessionHas('rollbackPreview');

        $yazilan = $device->payload();

        $this->assertStringContainsString('<load-configuration rollback="1"/>', $yazilan);
        $this->assertStringContainsString('compare="rollback"', $yazilan);

        // Önizleme commit ATMAZ: aday yapılandırma oturumla birlikte atılır.
        $this->assertStringNotContainsString('<commit-configuration>', $yazilan);
    }

    public function test_geri_alma_guvenli_zincirden_gecer(): void
    {
        $switch = $this->switch();
        $commit = $this->commit($switch, sequence: 1, minutesAgo: 60);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.commits.rollback', [$switch, $commit]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $yazilan = $device->payload();

        $this->assertStringContainsString('<load-configuration rollback="1"/>', $yazilan);

        // Geri alma da normal yazma yolundan geçer: kilit, geri alınabilir
        // commit, ayrı oturumda onay. Ayrı bir "check" yoktur.
        $this->assertStringContainsString('<lock-configuration/>', $yazilan);
        $this->assertStringContainsString('<confirmed/>', $yazilan);
        $this->assertStringNotContainsString('<check/>', $yazilan);
    }

    /**
     * Panelde duran sıra numarası ESKİMİŞ olabilir: aradan yeni commit
     * geçtiyse aynı yapılandırma başka bir numaradadır. İndeks cihazdan
     * yeniden okunur; paneldeki numara kullanılsaydı yanlış yapılandırma
     * yüklenirdi.
     */
    public function test_indeks_panelden_degil_cihazdan_okunur(): void
    {
        $switch = $this->switch();

        // Panelde 7 yazıyor; cihazda aynı zaman damgası 2 numarada.
        $commit = $this->commit($switch, sequence: 7, minutesAgo: 120);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.commits.rollback', [$switch, $commit]))
            ->assertRedirect();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<load-configuration rollback="2"/>', $yazilan);
        $this->assertStringNotContainsString('rollback="7"', $yazilan);
    }

    /**
     * Hedef commit cihazın geçmişinde yoksa hiçbir şey yazılmaz: yanlış bir
     * yapılandırmaya dönmektense hiç dönmemek doğrudur.
     */
    public function test_cihazda_bulunmayan_commit_geri_alinmaz(): void
    {
        $switch = $this->switch();
        $commit = SwitchCommit::factory()->for($switch, 'switch')->create([
            'sequence_number' => 3,
            'committed_at' => Carbon::parse('2020-01-01 00:00:00'),
        ]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.commits.rollback', [$switch, $commit]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringNotContainsString('<commit-configuration>', $device->payload());
    }

    public function test_okuma_yetkisi_geri_almaya_yetmez(): void
    {
        $switch = $this->switch();
        $commit = $this->commit($switch, sequence: 1, minutesAgo: 60);
        $device = $this->device();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->put(route('switches.commits.rollback', [$switch, $commit]))
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * Geri alma cihazın tamamını etkiler; dar "configure" yetmez, tam
     * cihaz hakkı "provision" gerekir (önizleme de aynı kapıdan geçer).
     */
    public function test_yalniz_configure_yetkisi_geri_almaya_ve_onizlemeye_yetmez(): void
    {
        $switch = $this->switch();
        $commit = $this->commit($switch, sequence: 1, minutesAgo: 60);
        $device = $this->device();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        $this->actingAs($user->fresh())
            ->put(route('switches.commits.rollback', [$switch, $commit]))
            ->assertForbidden();

        $this->actingAs($user->fresh())
            ->put(route('switches.commits.preview', [$switch, $commit]))
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * Başka switchin commit'i bu switch üzerinden geri alınamaz.
     */
    public function test_baska_switchin_commiti_uzerinden_islem_yapilamaz(): void
    {
        $switch = $this->switch();
        $digeri = $this->switch('198.18.20.196');
        $yabanci = $this->commit($digeri, sequence: 1, minutesAgo: 60);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.commits.rollback', [$switch, $yabanci]))
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    private function commit(NetworkSwitch $switch, int $sequence, int $minutesAgo): SwitchCommit
    {
        return SwitchCommit::factory()->for($switch, 'switch')->create([
            'sequence_number' => $sequence,
            'committed_at' => $this->timestamps()[$minutesAgo],
        ]);
    }

    /**
     * Sahte cihazın geçmişindeki zaman damgaları; panel kaydı da bunlarla
     * eşleşir çünkü eşleştirme zaman damgası üzerinden yapılır.
     *
     * @return array<int, Carbon>
     */
    private function timestamps(): array
    {
        return [
            0 => Carbon::parse('2026-01-01 12:00:00', 'UTC'),
            60 => Carbon::parse('2026-01-01 11:00:00', 'UTC'),
            120 => Carbon::parse('2026-01-01 10:00:00', 'UTC'),
        ];
    }

    private function device(): FakeJunosDevice
    {
        $satirlar = '';

        foreach ([0 => 0, 1 => 60, 2 => 120] as $sequence => $minutesAgo) {
            $satirlar .= '<commit-history>'
                .'<sequence-number>'.$sequence.'</sequence-number>'
                .'<user>admin</user><client>netconf</client>'
                .'<date-time junos:seconds="'.$this->timestamps()[$minutesAgo]->getTimestamp().'">x</date-time>'
                .'</commit-history>';
        }

        $device = new FakeJunosDevice(
            commitInformation: '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"'
                .' xmlns:junos="http://xml.juniper.net/junos/12.3R12/junos">'
                .'<commit-information>'.$satirlar.'</commit-information>'
                ."</rpc-reply>\n]]>]]>",
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure', 'provision']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
