<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Certificate\CertificateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sertifika ekranı: panel istek bırakır, sunucu koşturur.
 *
 * Buradaki asıl kural, ekranın güzelliği değil: PANEL SERTİFİKA ALMAZ.
 * Yayılmamış bir kayıtla "tamamla" denemesi engellenir — o deneme başarısız
 * sayılır ve tur baştan başlar.
 */
class CertificateScreenTest extends TestCase
{
    use RefreshDatabase;

    /** Dosya sistemine ve DNS'e çıkmayan sahte durum. */
    private function fake(array $propagation, bool $left = true): CertificateType
    {
        return new class($propagation, $left) extends CertificateType
        {
            public ?string $lastAction = null;

            public function __construct(private array $propagationList, private bool $left) {}

            public function status(): ?array
            {
                return ['veren' => "Let's Encrypt", 'bitis' => 'Jan  1 00:00:00 2030 GMT', 'kalan_gun' => 89];
            }

            public function records(): ?array
            {
                return array_map(fn (array $k): array => ['ad' => $k['ad'], 'deger' => $k['deger']], $this->propagationList);
            }

            public function lastOperation(): ?array
            {
                return null;
            }

            public function isRequestPending(): bool
            {
                return false;
            }

            public function propagationStatus(): array
            {
                return $this->propagationList;
            }

            public function requestLeave(string $action): bool
            {
                $this->lastAction = $action;

                return $this->left;
            }
        };
    }

    #[Test]
    public function ekran_yalnizca_ayar_yetkisi_olana_acilir(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.certificate'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.certificate'))
            ->assertOk()
            ->assertSee('Şu anki sertifika');
    }

    #[Test]
    public function kayitlar_ve_yayilma_durumu_gosterilir(): void
    {
        $this->instance(CertificateType::class, $this->fake([
            ['ad' => '_acme-challenge.panel-b.ornek.test', 'deger' => 'abc123', 'yayildi' => true],
            ['ad' => '_acme-challenge.merkez.ornek.test', 'deger' => 'def456', 'yayildi' => false],
        ]));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.certificate'))
            ->assertOk()
            ->assertSee('_acme-challenge.panel-b.ornek.test')
            ->assertSee('abc123')
            ->assertSee('görünüyor')
            ->assertSee('henüz yok');
    }

    /**
     * YAYILMAMIŞ KAYITLA TAMAMLANMAZ.
     *
     * Sertifika sağlayıcısına yayılmamış bir kayıtla gitmek başarısız bir
     * deneme demektir; tur baştan başlar ve kullanıcı neden olduğunu
     * anlamaz.
     */
    #[Test]
    public function yayilmamis_kayit_varken_onay_reddedilir(): void
    {
        $fake = $this->fake([
            ['ad' => '_acme-challenge.panel-b.ornek.test', 'deger' => 'abc123', 'yayildi' => false],
        ]);

        $this->instance(CertificateType::class, $fake);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.certificate'))
            ->post(route('settings.certificate.confirm'))
            ->assertRedirect(route('settings.certificate'))
            ->assertSessionHasErrors('sertifika');

        $this->assertNull($fake->lastAction, 'Sunucuya istek gitmemeliydi.');
    }

    #[Test]
    public function kayitlar_yayildiysa_istek_birakilir(): void
    {
        $fake = $this->fake([
            ['ad' => '_acme-challenge.panel-b.ornek.test', 'deger' => 'abc123', 'yayildi' => true],
        ]);

        $this->instance(CertificateType::class, $fake);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.certificate.confirm'))
            ->assertRedirect();

        $this->assertSame('tamamla', $fake->lastAction);
    }

    #[Test]
    public function tur_baslatmak_istek_birakir(): void
    {
        $fake = $this->fake([]);

        $this->instance(CertificateType::class, $fake);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.certificate.start'))
            ->assertRedirect();

        $this->assertSame('baslat', $fake->lastAction);
    }

    /**
     * İSTEK BIRAKILAMAZSA BAŞARI DENMEZ.
     *
     * İstek dizini yazılamıyorsa (ör. başka bir kullanıcıya aitse) sunucudaki ajan
     * hiçbir şey görmez. Ekran "tur başlatıldı" derse kullanıcı boşuna bekler;
     * hata gösterilir.
     */
    #[Test]
    public function istek_birakilamazsa_tur_baslatildi_denmez(): void
    {
        $fake = $this->fake([], left: false);
        $this->instance(CertificateType::class, $fake);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.certificate'))
            ->post(route('settings.certificate.start'))
            ->assertRedirect(route('settings.certificate'))
            ->assertSessionHasErrors('sertifika')
            ->assertSessionMissing('status');

        $this->assertSame('baslat', $fake->lastAction);
    }

    #[Test]
    public function istek_birakilamazsa_sertifika_aliniyor_denmez(): void
    {
        $fake = $this->fake([
            ['ad' => '_acme-challenge.panel-b.ornek.test', 'deger' => 'abc123', 'yayildi' => true],
        ], left: false);
        $this->instance(CertificateType::class, $fake);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.certificate'))
            ->post(route('settings.certificate.confirm'))
            ->assertRedirect(route('settings.certificate'))
            ->assertSessionHasErrors('sertifika')
            ->assertSessionMissing('status');

        $this->assertSame('tamamla', $fake->lastAction);
    }

    /** Hata ekranda bir kez görünür: bildirim bloğu doğrulama hatalarını zaten basıyor. */
    #[Test]
    public function iletilemedi_hatasi_ekranda_bir_kez_gorunur(): void
    {
        $this->instance(CertificateType::class, $this->fake([], left: false));

        $response = $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.certificate'))
            ->followingRedirects()
            ->post(route('settings.certificate.start'))
            ->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'İstek sunucuya iletilemedi'));
    }
}
