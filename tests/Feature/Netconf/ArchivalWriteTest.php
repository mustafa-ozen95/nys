<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Yapılandırma yedeği ayarının cihaza yazılması.
 *
 * Ayar CİHAZA ÖZGÜDÜR: her switch kendi sunucusunu gösterebilir, adres
 * girilmemişse .env'deki varsayılan kullanılır.
 *
 * Hedef adres kimlik içerir: sütunda şifreli durur, ne cihazın commit
 * açıklamasına ne de panelin etkinlik kaydına yazılır.
 */
final class ArchivalWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Yedek adresi kimlik içerir; depodaki varsayılan boş olmalı, gerçek
     * adres yalnızca ortamdan (ARCHIVAL_SITE) gelmeli.
     */
    public function test_depoda_gomulu_yedek_kimligi_yok(): void
    {
        $this->assertSame('', config('archival.site'));
    }

    public function test_cihaza_ozel_adres_yazilir_ve_sifreli_saklanir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.archival', $switch), [
                'enabled' => 1,
                'site' => 'ftp://yedek:gizli@192.0.2.51/sw1/',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = implode('', $device->written);

        $this->assertStringContainsString('<transfer-on-commit/>', $written);
        $this->assertStringContainsString('192.0.2.51', $written);

        /*
         * Adres değişince eskisi kalmasın diye düğüm KOMPLE değiştirilir.
         * Adsız "<archive-sites delete=\"delete\"/>" ile temizlemek cihazda
         * (12.3R12.4) "syntax error" veriyor; replace geçen tek biçim.
         */
        $this->assertStringContainsString('<configuration replace="replace">', $written);
        $this->assertStringNotContainsString('<archive-sites delete="delete"/>', $written);

        $this->assertSame('ftp://yedek:gizli@192.0.2.51/sw1/', $switch->fresh()->archival_site);

        // Sütunda düz metin durmaz.
        $this->assertStringNotContainsString(
            'gizli',
            (string) DB::table('switches')->where('id', $switch->id)->value('archival_site'),
        );
    }

    public function test_adres_girilmezse_varsayilan_sunucu_kullanilir(): void
    {
        config(['archival.site' => 'ftp://yedek:gizli@10.9.9.9/sw1/']);

        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.archival', $switch), ['enabled' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('10.9.9.9', implode('', $device->written));

        // Varsayılan kullanıldı diye cihaza özel adres YAZILMAZ; sunucu
        // değişirse tek yerden değişmeye devam etmeli.
        $this->assertNull($switch->fresh()->archival_site);
    }

    public function test_cihaza_ozel_adres_varsayilani_ezer(): void
    {
        config(['archival.site' => 'ftp://yedek:gizli@10.9.9.9/sw1/']);

        $switch = $this->switch();
        $switch->update(['archival_enabled' => true, 'archival_site' => 'ftp://a:b@10.7.7.7/B1/']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.archival', $switch), ['enabled' => 1])
            ->assertRedirect();

        $written = implode('', $device->written);

        $this->assertStringContainsString('10.7.7.7', $written);
        $this->assertStringNotContainsString('10.9.9.9', $written);
    }

    public function test_kayitli_adres_silinince_varsayilana_donulur(): void
    {
        config(['archival.site' => 'ftp://yedek:gizli@10.9.9.9/sw1/']);

        $switch = $this->switch();
        $switch->update(['archival_enabled' => true, 'archival_site' => 'ftp://a:b@10.7.7.7/B1/']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.archival', $switch), ['enabled' => 1, 'reset_site' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($switch->fresh()->archival_site);
        $this->assertStringContainsString('10.9.9.9', implode('', $device->written));
    }

    public function test_semasiz_adres_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->put(route('switches.archival', $switch), ['enabled' => 1, 'site' => '192.0.2.51/sw1'])
            ->assertSessionHasErrors('site');

        $this->assertSame([], $device->written);
    }

    public function test_kapaliyken_ayar_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $switch->update(['archival_enabled' => true]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator())->put(route('switches.archival', $switch))->assertRedirect();

        $this->assertStringContainsString('<archival delete="delete"/>', implode('', $device->written));
        $this->assertFalse($switch->fresh()->archival_enabled);
    }

    public function test_adres_kayda_yazilmaz(): void
    {
        $switch = $this->switch();
        $this->fakeDevice();

        $this->actingAs($this->operator())->put(route('switches.archival', $switch), [
            'enabled' => 1,
            'site' => 'ftp://yedek:cok-gizli-parola@192.0.2.51/sw1/',
        ]);

        $this->assertSame(
            0,
            ActivityLog::query()->where('description', 'like', '%cok-gizli-parola%')->count(),
        );
    }

    public function test_cihaza_yazma_yetkisi_olmayan_yazamaz(): void
    {
        $switch = $this->switch();
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->put(route('switches.archival', $switch), ['enabled' => 1])
            ->assertForbidden();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'jn32-test', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
