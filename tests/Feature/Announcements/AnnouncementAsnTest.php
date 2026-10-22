<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\AnnouncementMail;
use App\Models\AnnouncementRecipient;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Anons hangi AS numarası adına yapılıyor?
 *
 * Anons yalnızca kurumun kendi AS numarası için değil, müşterinin numarası
 * için de istenebilir; bu yüzden numara her talepte sorulur.
 *
 * Cihazlardan okunan liste bir KOLAYLIKTIR; alan ona kilitli değildir.
 * Müşterinin numarası hiçbir switchte yazmaz ve elle girilebilmelidir.
 */
final class AnnouncementAsnTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '192.0.2.0/24';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'announcements', ['view', 'create']);

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $asn, ?string $downstream = null): array
    {
        return [
            'prefixes' => self::PREFIX,
            'asn' => $asn,
            'downstream' => $downstream,
            'recipients' => [AnnouncementRecipient::factory()->create()->uuid],
        ];
    }

    /**
     * Her prefixi onaylayan RIPEstat; sorulan ASN'yi de kaydeder.
     *
     * @param  array<int, string>  $sorulan
     */
    private function ripestatApproves(array &$asked): void
    {
        Http::fake(function (Request $request) use (&$asked): mixed {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $asked[] = (string) ($query['resource'] ?? '');

            return Http::response(['data' => ['status' => 'valid', 'validating_roas' => []]]);
        });
    }

    /**
     * Müşteri kendi numarası için talep edebiliyor: numara listede yoktur ve
     * elle girilir. Kilitli bir liste bu talebi hiç kabul etmezdi.
     */
    public function test_listede_olmayan_musteri_asnsi_kabul_edilir(): void
    {
        NetworkSwitch::factory()->create(['autonomous_system' => '65000']);

        $asked = [];
        $this->ripestatApproves($asked);
        $this->irrd->fixture(self::PREFIX);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload('AS64496'))
            ->assertSessionHasNoErrors();

        // Denetim MÜŞTERİNİN numarasına sorulur, bizimkine değil.
        $this->assertSame(['AS64496'], array_unique($asked));
        $this->assertSame('AS64496', AnnouncementMail::query()->value('asn'));
    }

    /**
     * "as64496", "64496" ve "AS64496" aynı numaradır; panelde tek yazım
     * dolaşır. Aksi hâlde önbellekte iki anahtar açılır ve mailde her
     * seferinde başka biçim basılır.
     */
    public function test_yazim_farki_tek_bicime_indirilir(): void
    {
        $asked = [];
        $this->ripestatApproves($asked);
        $this->irrd->fixture(self::PREFIX);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload('as64496', '0064500'))
            ->assertSessionHasNoErrors();

        $mail = AnnouncementMail::query()->first();

        $this->assertSame('AS64496', $mail->asn);
        $this->assertSame('AS64500', $mail->downstream_asn);
        $this->assertStringContainsString('AS64496', $mail->body);
    }

    /**
     * Geçersiz numara sessizce varsayılana ÇEVRİLMEZ.
     *
     * Çevrilseydi kullanıcı müşterinin numarasını yanlış yazdığında talep
     * bizim ASN'miz adına giderdi — yanlış anons, sessizce.
     */
    public function test_gecersiz_numara_varsayilana_cevrilmez(): void
    {
        config(['announcements.default_asn' => 'AS64496']);
        Http::fake();

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload('AS64496x'))
            ->assertSessionHasErrors('asn');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    public function test_bos_numara_varsayilana_cevrilmez(): void
    {
        config(['announcements.default_asn' => 'AS64496']);
        Http::fake();

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(''))
            ->assertSessionHasErrors('asn');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    /**
     * 32 bit aralığın dışı reddedilir. Yalnız `\d{1,10}` yetmez: 9999999999
     * gibi var olmayan bir numarayı kabul eder.
     */
    public function test_aralik_disi_numara_reddedilir(): void
    {
        Http::fake();

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload('4294967296'))
            ->assertSessionHasErrors('asn');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    /**
     * Ekranda hem liste hem elle giriş bulunur; liste kilit değildir.
     */
    public function test_ekran_elle_girise_izin_verir(): void
    {
        NetworkSwitch::factory()->create(['autonomous_system' => '65000']);

        $this->actingAs($this->operator())
            ->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('AS65000')
            ->assertSee('Listede yok — elle gir')
            ->assertSee('data-selection-manual', escape: false);
    }
}
