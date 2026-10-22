<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProtocol;
use App\Jobs\RotateSwitchPasswordJob;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cihaz erişim parolasının ekranda gösterilmesi.
 *
 * Panelin genel kuralı parolayı hiçbir ekranda basmamaktır; burası bilinçli
 * bir istisnadır. Bu yüzden üç şey birden doğru olmak zorunda:
 *
 *  - Ayrı bir yetki ister ("secret") ve varsayılan olarak KAPALIDIR.
 *  - Yetki switch başınadır: birinin parolasını görebilen, hepsininkini
 *    göremez.
 *  - Her gösterim kayda geçer.
 */
final class PasswordVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_varsayilan_olarak_kimse_goremez(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'update', 'configure']);

        $this->actingAs($user->fresh())
            ->post(route('switches.password', $switch))
            ->assertForbidden();
    }

    public function test_yetkili_kullanici_parolayi_alir(): void
    {
        $switch = $this->switch();
        $user = $this->withSecret([$switch->id]);

        $this->actingAs($user)
            ->post(route('switches.password', $switch))
            ->assertOk()
            ->assertJson(['username' => 'admin', 'password' => 'cokgizliparola']);
    }

    /**
     * Yetki switch BAŞINADIR: birinin parolasını görebilen, diğerininkini
     * göremez.
     */
    public function test_yetki_switch_basinadir(): void
    {
        $bir = $this->switch();
        $two = $this->switch('test2', '198.18.20.196');

        $user = $this->withSecret([$bir->id]);

        $this->actingAs($user)->post(route('switches.password', $bir))->assertOk();
        $this->actingAs($user)->post(route('switches.password', $two))->assertForbidden();
    }

    /**
     * Kayıt silinemez (hash zinciri); parolayı kimin ne zaman gördüğü
     * sonradan da okunabilir olmalı.
     */
    public function test_her_gosterim_kayda_gecer(): void
    {
        $switch = $this->switch();
        $user = $this->withSecret([$switch->id]);

        $this->actingAs($user)->post(route('switches.password', $switch))->assertOk();
        $this->actingAs($user)->post(route('switches.password', $switch))->assertOk();

        $this->assertSame(
            2,
            ActivityLog::query()->where('description', 'like', '%parolası görüntülendi%')->count(),
        );
    }

    /**
     * Kaydın kendisi parolayı TAŞIMAZ: kayıt dışa aktarılabilir.
     */
    public function test_kayit_parolayi_tasimaz(): void
    {
        $switch = $this->switch();
        $user = $this->withSecret([$switch->id]);

        $this->actingAs($user)->post(route('switches.password', $switch))->assertOk();

        $this->assertSame(
            0,
            ActivityLog::query()->where('description', 'like', '%cokgizliparola%')->count(),
        );
    }

    /**
     * Parola HTML'de bulunmaz: ekranı açan herkes değil, açıkça soran görür.
     */
    public function test_parola_sayfa_kaynaginda_bulunmaz(): void
    {
        $switch = $this->switch();
        $user = $this->withSecret([$switch->id], ['view', 'configure']);

        $this->actingAs($user)
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertSee('Cihaz erişim parolası')
            ->assertDontSee('cokgizliparola');
    }

    /**
     * Görme yetkisi DEĞİŞTİRME yetkisi değildir.
     */
    public function test_gorme_yetkisi_degistirme_yetkisi_degildir(): void
    {
        $switch = $this->switch();
        $user = $this->withSecret([$switch->id]);

        $this->actingAs($user)->get(route('settings.passwords'))->assertOk();

        $this->actingAs($user)
            ->put(route('settings.passwords.update', $switch))
            ->assertForbidden();
    }

    /**
     * Toplu değişim kişinin YETKİLİ olduğu switchlerle sınırlıdır; toplu
     * düğme yetki aşmanın yolu olmamalı.
     */
    public function test_toplu_degisim_yetkisiz_switchi_atlar(): void
    {
        Queue::fake();

        $bir = $this->switch();
        $two = $this->switch('test2', '198.18.20.196');

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switches',
            [],
            ['view' => [$bir->id, $two->id], 'rotate' => [$bir->id]],
        );

        $this->actingAs($user->fresh())
            ->post(route('settings.passwords.store'))
            ->assertRedirect();

        Queue::assertPushed(
            RotateSwitchPasswordJob::class,
            fn ($job): bool => $job->switchId === $bir->id,
        );
        Queue::assertPushed(RotateSwitchPasswordJob::class, 1);
    }

    public function test_ekran_yalnizca_yetkili_switchleri_listeler(): void
    {
        $bir = $this->switch();
        $this->switch('gizli-switch', '198.18.20.196');

        $this->actingAs($this->withSecret([$bir->id]))
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('test1')
            ->assertDontSee('gizli-switch');
    }

    /**
     * @param  list<int>  $switchIds
     * @param  list<string>  $wildcard
     */
    private function withSecret(array $switchIds, array $wildcard = ['view']): User
    {
        $user = User::factory()->create();

        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switches',
            $wildcard,
            ['secret' => $switchIds],
        );

        return $user->fresh();
    }

    private function switch(string $hostname = 'test1', string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => $hostname, 'management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'cokgizliparola',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
