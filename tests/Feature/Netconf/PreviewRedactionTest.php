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
 * Cihaz farkı EKRANA maskelenmiş gider.
 *
 * Cihazın kendi yapılandırması sır taşır: encrypted-password "$9$...",
 * authentication-key "$9$...", community adları. Junos'un $9$ biçimi GERİ
 * ÇEVRİLEBİLİR — düz metin kadar hassastır ve zaten bu yüzden etkinlik
 * kaydında maskeleniyordu. Önizleme o maskelemeyi atlıyordu: geri alma
 * ekranını açan herkes cihazın sırlarını okuyabiliyordu.
 *
 * Panelin KENDİ sakladığı cihaz parolası ayrıdır: o, "secret" yetkisiyle ve
 * her gösterim kayda geçerek DÜZ gösterilir (bkz. PasswordVisibilityTest).
 * İkisi karıştırılmamalı — biri cihazın özeti, diğeri panelin parolası.
 */
final class PreviewRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const HIDDEN_DIFF = "[edit system login user admin authentication]\n"
        ."-  encrypted-password \"\$9\$eskiGizliOzet\"; ## SECRET-DATA\n"
        ."+  encrypted-password \"\$9\$yeniGizliOzet\"; ## SECRET-DATA\n"
        ."[edit snmp]\n"
        .'+  community "gizlicommunity" { authorization read-only; }';

    public function test_geri_alma_onizlemesi_sirlari_maskeler(): void
    {
        $switch = $this->switch();
        $this->device();
        $commit = $this->commit($switch);

        $this->actingAs($this->operator())
            ->put(route('switches.commits.preview', [$switch, $commit]))
            ->assertRedirect();

        $diff = session('rollbackPreview')['diff'] ?? '';

        // Önizleme gerçekten üretilmiş olmalı; boş fark testi anlamsız kılardı.
        $this->assertNotSame('', $diff);

        $this->assertStringNotContainsString('eskiGizliOzet', $diff);
        $this->assertStringNotContainsString('yeniGizliOzet', $diff);
        $this->assertStringNotContainsString('gizlicommunity', $diff);

        // Hangi satırın değiştiği görünmeye devam eder; yalnızca değer gizlenir.
        $this->assertStringContainsString('encrypted-password', $diff);
        $this->assertStringContainsString('••••', $diff);
    }

    public function test_geri_alma_ekrani_sir_basmaz(): void
    {
        $switch = $this->switch();
        $this->device();
        $commit = $this->commit($switch);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->put(route('switches.commits.preview', [$switch, $commit]))
            ->assertRedirect();

        $this->actingAs($operator)
            ->get(route('switches.commits', $switch))
            ->assertOk()
            ->assertDontSee('eskiGizliOzet')
            ->assertDontSee('gizlicommunity');
    }

    /**
     * Geri alınacak commit.
     *
     * Eşleştirme ZAMAN DAMGASIYLA yapılır: panel kaydının damgası sahte
     * cihazın geçmişindekiyle aynı olmalı, yoksa indeks 0 çıkar ve önizleme
     * hiç üretilmez (bkz. CommitRollback).
     */
    private function commit(NetworkSwitch $switch): SwitchCommit
    {
        return SwitchCommit::factory()->for($switch, 'switch')->create([
            'sequence_number' => 1,
            'committed_at' => $this->history()[60],
        ]);
    }

    /**
     * @return array<int, Carbon>
     */
    private function history(): array
    {
        return [
            0 => Carbon::parse('2026-01-01 12:00:00', 'UTC'),
            60 => Carbon::parse('2026-01-01 11:00:00', 'UTC'),
            120 => Carbon::parse('2026-01-01 10:00:00', 'UTC'),
        ];
    }

    private function device(): FakeJunosDevice
    {
        $rows = '';

        foreach ([0 => 0, 1 => 60, 2 => 120] as $sequence => $minute) {
            $rows .= '<commit-history>'
                .'<sequence-number>'.$sequence.'</sequence-number>'
                .'<user>admin</user><client>netconf</client>'
                .'<date-time junos:seconds="'.$this->history()[$minute]->getTimestamp().'">x</date-time>'
                .'</commit-history>';
        }

        $device = new FakeJunosDevice(
            diff: self::HIDDEN_DIFF,
            commitInformation: '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"'
                .' xmlns:junos="http://xml.juniper.net/junos/12.3R12/junos">'
                .'<commit-information>'.$rows.'</commit-information>'
                ."</rpc-reply>\n]]>]]>",
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'update', 'configure', 'provision']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'test1', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
