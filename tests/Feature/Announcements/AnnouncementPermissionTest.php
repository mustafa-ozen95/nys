<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\PrefixAuthorizationPolicy;
use App\Models\AnnouncementMail;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Anons edilecek bloğun anons hakkı, mail gitmeden önce sorgulanır.
 *
 * İki kaynak sorulur: RIPE (RPKI ROA) ve RADB (IRR route nesnesi). EN AZ BİR
 * kaynağın onayı yeter; gönderimi durduran şey hiçbir kaynağın
 * onaylamamasıdır.
 *
 * Yanıtlar sahtelenir; dışarı hiç çıkılmaz.
 */
final class AnnouncementPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** Fixture'ı olan, AS64496'a ait ve her iki kaynakta onaylı blok. */
    private const APPROVED = '192.0.2.0/24';

    /**
     * ROA AS64496 için geçerli ama gerçek IRR nesnesi AS64497'in.
     *
     * AS64496 bu prefixte yalnızca RPKI türevi sözde-nesnede görünür; kaynak
     * elenmezse IRR kontrolü ROA'nın kopyasına döner.
     */
    private const RPKI_TRAP = '198.51.100.0/24';

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
    private function payload(string $prefixes): array
    {
        return [
            'prefixes' => $prefixes,
            'asn' => 'AS64496',
            'downstream' => '',
            'recipients' => [AnnouncementRecipient::factory()->create()->uuid],
        ];
    }

    /**
     * RIPEstat rpki-validation yanıtı: prefix başına durum.
     *
     * @param  array<string, string>  $statuses
     */
    private function ripestat(array $statuses, string $default = 'unknown'): void
    {
        Http::fake(function (Request $request) use ($statuses, $default): mixed {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $prefix = (string) ($query['prefix'] ?? '');

            return Http::response([
                'data' => [
                    'status' => $statuses[$prefix] ?? $default,
                    'validating_roas' => [
                        ['origin' => '64510', 'prefix' => $prefix, 'validity' => 'invalid_asn'],
                    ],
                ],
            ]);
        });
    }

    public function test_iki_kaynak_da_onaylarsa_gonderilir(): void
    {
        $this->ripestat([self::APPROVED => 'valid']);
        $this->irrd->fixture(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertRedirect(route('announcements.create'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AnnouncementMail::query()->count());
    }

    /**
     * ROA var, IRR kaydı yok: blok gönderilir.
     *
     * Bir ağın bloklarının bir kısmında yalnızca ROA, bir kısmında yalnızca
     * IRR kaydı bulunabilir. "İkisi birden" şartı, gerçekten o ağa ait olan
     * blokları sırf bir kayıt eksik diye durdururdu.
     */
    public function test_yalnizca_roa_onaylarsa_gonderilir(): void
    {
        $this->ripestat([self::APPROVED => 'valid']);
        $this->irrd->without(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AnnouncementMail::query()->count());
    }

    /**
     * IRR kaydı var, ROA yok: blok yine gönderilir. Karşı tarafın prefix
     * filtresi çoğunlukla IRR'den üretilir; kanıt olarak tek başına yeter.
     */
    public function test_yalnizca_irr_onaylarsa_gonderilir(): void
    {
        $this->ripestat([self::APPROVED => 'unknown']);
        $this->irrd->fixture(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AnnouncementMail::query()->count());
    }

    /**
     * İki kaynak da BAŞKASINI gösteriyor: gönderim durur.
     *
     * Örnek: blok hem ROA'da hem IRR'de AS64510'ü gösteriyor. Böyle bir
     * bloğun prefix listesine eklenmesini istemek, başkasının adresini
     * istemektir.
     */
    public function test_hicbir_kaynak_onaylamazsa_gonderilmez(): void
    {
        $this->ripestat([self::RPKI_TRAP => 'invalid_asn']);
        $this->irrd->fixture(self::RPKI_TRAP);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::RPKI_TRAP))
            ->assertSessionHasErrors('prefixes');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    public function test_hicbir_kayit_yoksa_gonderilmez(): void
    {
        $this->ripestat([self::APPROVED => 'unknown']);
        $this->irrd->without(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertSessionHasErrors('prefixes');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    /**
     * Bir kaynak düşse bile ötekinin onayı yeter: kesinti gönderimi kilitlemez.
     */
    public function test_bir_kaynak_cevap_vermezse_oteki_yeter(): void
    {
        Http::fake(fn (): mixed => Http::response('', 503));
        $this->irrd->fixture(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AnnouncementMail::query()->count());
    }

    /**
     * İki kaynak da sorgulanamazsa gönderilmez: kanıtlanmamış yetki, yetki
     * değildir. "Herhâlde onaylıdır" demek kontrolü hiç koymamakla aynı şey.
     */
    public function test_iki_kaynak_da_cevap_vermezse_gonderilmez(): void
    {
        Http::fake(fn (): mixed => Http::response('', 503));
        $this->irrd->fail();

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertSessionHasErrors('prefixes');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    /**
     * Tek blok düşerse talebin TAMAMI durur: yarısı gönderilmiş bir anons
     * karşı tarafta eksik filtre demektir.
     */
    public function test_bir_blok_dusunce_talep_tumden_durur(): void
    {
        $this->ripestat([self::APPROVED => 'valid', self::RPKI_TRAP => 'invalid_asn']);
        $this->irrd->fixture(self::APPROVED)->fixture(self::RPKI_TRAP);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED."\n".self::RPKI_TRAP))
            ->assertSessionHasErrors('prefixes');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    /**
     * En kritik regresyon: ROA'dan türetilmiş IRR nesnesi IRR kanıtı sayılmaz.
     *
     * Sayılsaydı IRR kontrolü ROA kontrolünün kopyası olurdu ve ekran "IRR
     * kaydınız var" derdi — oysa yok. Blok ROA sayesinde gönderilebilir ama
     * eksik kayıt operatöre SÖYLENİR, çünkü karşı tarafın filtresi ondan
     * üretiliyor.
     */
    public function test_rpki_turevi_irr_nesnesi_kanit_sayilmaz(): void
    {
        $this->ripestat([self::RPKI_TRAP => 'valid']);
        $this->irrd->fixture(self::RPKI_TRAP);

        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(self::RPKI_TRAP))
            ->assertOk()
            ->assertSee('IRR route nesnesi başka bir AS numarasına ait (AS64497).')
            // ROA yettiği için gönderilebilir; düğme çizilir.
            ->assertSee('data-confirm-verify="GONDER"', escape: false);
    }

    /**
     * "Bütün kaynaklar" kipi katıdır ve tek yapılandırma satırıyla açılır.
     */
    public function test_tum_kaynak_kipinde_eksik_kayit_durdurur(): void
    {
        config(['announcements.authorization.policy' => PrefixAuthorizationPolicy::All->value]);

        $this->ripestat([self::APPROVED => 'valid']);
        $this->irrd->without(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(self::APPROVED))
            ->assertSessionHasErrors('prefixes');

        $this->assertSame(0, AnnouncementMail::query()->count());
    }

    public function test_onizleme_her_kaynagin_kararini_gosterir(): void
    {
        $this->ripestat([self::RPKI_TRAP => 'valid']);
        $this->irrd->fixture(self::RPKI_TRAP);

        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(self::RPKI_TRAP))
            ->assertOk()
            ->assertSee('Anons yetkisi')
            ->assertSee('RPKI ROA (RIPE)')
            ->assertSee('IRR route nesnesi (RADB)')
            ->assertSee('Onaylı')
            ->assertSee('Onaysız');
    }

    /**
     * Engellenen talepte gönder düğmesi hiç çizilmez.
     */
    public function test_engellenen_talepte_gonder_dugmesi_cizilmez(): void
    {
        $this->ripestat([self::RPKI_TRAP => 'invalid_asn']);
        $this->irrd->fixture(self::RPKI_TRAP);

        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(self::RPKI_TRAP))
            ->assertOk()
            ->assertDontSee('data-confirm-verify="GONDER"', escape: false)
            ->assertSee('gönderim durduruldu');
    }

    /**
     * Ana makine biti taşıyan giriş normalleştirilerek sorulur; yoksa her
     * kaynak "kayıt yok" derdi.
     */
    public function test_prefix_ag_adresine_normallestirilir(): void
    {
        $this->ripestat(['192.0.2.0/24' => 'valid']);
        $this->irrd->fixture(self::APPROVED);

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload('192.0.2.77/24'))
            ->assertSessionHasNoErrors();

        $this->assertSame(['192.0.2.0/24'], $this->irrd->queried);
        $this->assertSame(1, AnnouncementMail::query()->count());
    }

    /**
     * Sınırın üstündeki liste denetlenemez; denetlenmemiş liste geçirilmez.
     */
    public function test_sinir_ustundeki_liste_denetlenmeden_gecmez(): void
    {
        config(['announcements.authorization.max_prefixes' => 2]);
        Http::fake();

        $blocks = "10.0.0.0/24\n10.0.1.0/24\n10.0.2.0/24";

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload($blocks))
            ->assertSessionHasErrors('prefixes');

        $this->assertSame(0, AnnouncementMail::query()->count());
        $this->assertSame([], $this->irrd->queried);
    }

    /**
     * Aynı blok ikinci kez sorulmaz: kaynaklar dış servistir, her önizleme
     * yeni bir tur açsaydı ekran onların hızına bağlı olurdu.
     */
    public function test_karar_onbellege_alinir(): void
    {
        $this->ripestat([self::APPROVED => 'valid']);
        $this->irrd->fixture(self::APPROVED);

        $operator = $this->operator();
        $payload = $this->payload(self::APPROVED);

        $this->actingAs($operator)->post(route('announcements.preview'), $payload)->assertOk();
        $this->actingAs($operator)->post(route('announcements.preview'), $payload)->assertOk();

        $this->assertSame([self::APPROVED], $this->irrd->queried);
        Http::assertSentCount(1);
    }

    /**
     * Denetim varsayılan olarak AÇIKTIR ve varsayılan ölçüt "en az bir
     * kaynak"tır. Kapalı gelseydi bütün bu testler yeşil kalır, üretimde
     * hiçbir şey sorulmazdı.
     */
    public function test_denetim_varsayilan_olarak_aciktir(): void
    {
        $this->assertTrue((bool) config('announcements.authorization.enabled'));
        $this->assertNotEmpty(config('announcements.authorization.authorities'));
        $this->assertSame(
            PrefixAuthorizationPolicy::Any,
            PrefixAuthorizationPolicy::from(config('announcements.authorization.policy')),
        );
    }
}
