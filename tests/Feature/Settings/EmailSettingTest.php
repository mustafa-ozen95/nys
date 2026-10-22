<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\MailScheme;
use App\Enums\MailTransport;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SMTP ayarları VERİTABANINDAN yönetilir.
 *
 * Yalnızca .env'de dursaydı yanlış kalmış bir değer (ör. MAIL_MAILER=log)
 * prefix anons taleplerini sessizce yutardı ve panelden görülebilir hiçbir
 * işaret olmazdı.
 *
 * Ayarın anahtarı config yolunun kendisidir; bu yüzden Mail'in okuduğu yer
 * ile ayarın yazdığı yer AYNI olmak zorunda. Bu testin ölçtüğü şey de o:
 * kaydedilen değer gerçekten gönderime geçiyor mu.
 */
final class EmailSettingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'mail__default' => MailTransport::Smtp->value,
            'mail__mailers__smtp__host' => 'posta.ornek.test',
            'mail__mailers__smtp__port' => '587',
            'mail__mailers__smtp__scheme' => MailScheme::Smtp->value,
            'mail__mailers__smtp__username' => 'panel@ornek.test',
            'mail__mailers__smtp__password' => 'cok-gizli',
            'mail__from__address' => 'noc@ornek.test',
            'mail__from__name' => 'Ağ Yönetim Paneli',
            'reason' => 'SMTP kurulumu',
        ], $overrides);
    }

    /**
     * SMTP parolası ekranda hiç basılmaz ve boş bırakılınca korunur;
     * sunucu/port/şifreleme/kullanıcı adı değişirse parola yeni uca giderdi.
     */
    public function test_sunucu_degisince_kayitli_parola_yeniden_istenir(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.update', 'email'), $this->payload())
            ->assertSessionHasNoErrors();

        app(SettingsRepository::class)->forget();
        app(SettingsRepository::class)->apply();

        foreach (['mail__mailers__smtp__host' => 'toplayici.saldirgan.test',
            'mail__mailers__smtp__port' => '2525',
            'mail__mailers__smtp__username' => 'baska@ornek.test'] as $alan => $value) {
            $this->actingAs($admin)
                ->put(route('settings.update', 'email'), $this->payload([
                    $alan => $value,
                    'mail__mailers__smtp__password' => '',
                ]))
                ->assertSessionHasErrors('mail__mailers__smtp__password');
        }

        // Uç değişmiyorsa parola boş bırakılabilir.
        $this->actingAs($admin)
            ->put(route('settings.update', 'email'), $this->payload([
                'mail__from__name' => 'Yeni Ad',
                'mail__mailers__smtp__password' => '',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_bolum_yoneticiye_acilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.show', 'email'))
            ->assertOk()
            ->assertSee('SMTP sunucusu')
            ->assertSee('Gönderim biçimi');
    }

    public function test_yonetici_olmayan_kaydedemez(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('settings.update', 'email'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('settings', 0);
    }

    /**
     * Asıl sözleşme: kaydedilen değer GÖNDERİME geçmeli.
     *
     * config'e bindiğini görmek yetmez — Mail kendi yapılandırmasını ayrı
     * okusaydı ayar ekranı çalışıyor görünüp hiçbir şeyi değiştirmezdi.
     */
    public function test_kaydedilen_smtp_ayari_gonderime_geciyor(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'email'), $this->payload())
            ->assertRedirect();

        app(SettingsRepository::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('posta.ornek.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('noc@ornek.test', config('mail.from.address'));

        // Mailer'ın gerçekten bu sunucuya bağlanacak biçimde kurulduğu.
        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertSame('posta.ornek.test', $transport->getStream()->getHost());
        $this->assertSame(587, $transport->getStream()->getPort());
    }

    /**
     * Parola şifreli saklanır ve hiçbir ekranda basılmaz.
     */
    public function test_parola_sifreli_saklanir_ve_ekranda_basilmaz(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.update', 'email'), $this->payload())
            ->assertRedirect();

        $row = Setting::query()->where('key', 'mail.mailers.smtp.password')->firstOrFail();

        // Değer önce JSON'lanır, sonra şifrelenir (tip bilgisi korunsun diye).
        $this->assertNotSame('cok-gizli', $row->getRawOriginal('value'));
        $this->assertSame('cok-gizli', json_decode(Crypt::decryptString($row->getRawOriginal('value')), true));

        $this->actingAs($admin)
            ->get(route('settings.show', 'email'))
            ->assertOk()
            ->assertDontSee('cok-gizli');
    }

    /**
     * Boş parola "değiştirme" demektir; yoksa formu her kaydeden kişi
     * parolayı sessizce siler.
     */
    public function test_bos_parola_mevcut_degeri_korur(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('settings.update', 'email'), $this->payload());
        $this->actingAs($admin)->put(
            route('settings.update', 'email'),
            $this->payload(['mail__mailers__smtp__password' => '']),
        );

        app(SettingsRepository::class)->apply();

        $this->assertSame('cok-gizli', config('mail.mailers.smtp.password'));
    }
}
