<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Kimlik Yönetim Merkezi ile giriş.
 *
 * Bu akışta panel, kimliği KENDİ sormuyor: merkezin sözüne güveniyor. O
 * yüzden sınanan şey "giriş oluyor mu" değil, güvenin nerede DURDUĞU —
 * merkezden gelen her söz kabul edilmemeli, eşleşmeyen kimlikle hesap
 * açılmamalı ve akışı başlatmadığımız bir dönüş işlenmemeli.
 */
final class CenterLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.test',
            'identity.client_id' => 'istemci-1',
            'identity.client_secret' => 'sir-1',
            'identity.redirect_uri' => 'https://panel.test/login/center/callback',
            'identity.name' => 'Kimlik Yönetim Merkezi',
        ]);
    }

    /** Merkezin iki ucunu da taklit eder. */
    private function center(array $identity): void
    {
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton-1']),
            'merkez.test/oauth/userinfo' => Http::response($identity),
        ]);
    }

    /** @return array{sub: string, name: string, email: string, email_verified: bool} */
    private function identity(array $onto = []): array
    {
        return ['sub' => 'merkez-1', 'name' => 'Ali', 'email' => 'ali@example.test', 'email_verified' => true, ...$onto];
    }

    /** Yerleşik rol; testte migration'dan gelmiyorsa açılır. */
    private function role(string $slug, bool $admin = false, bool $default = false, array $template = []): Role
    {
        return Role::query()->updateOrCreate(
            ['slug' => $slug],
            ['label' => ucfirst($slug), 'is_admin' => $admin, 'is_default' => $default, 'template' => $template],
        );
    }

    /** Akışı, oturumda durum ve doğrulayıcı varmış gibi kurar. */
    private function return(array $onto = []): TestResponse
    {
        return $this->withSession(['kimlik_durum' => 'durum-1', 'kimlik_dogrulayici' => 'dogrulayici-1'])
            ->get(route('identity.callback', ['code' => 'kod-1', 'state' => 'durum-1', ...$onto]));
    }

    /**
     * Merkez açıkken parola formu KALDIRILMAZ, katlanır.
     *
     * Kaldırılsaydı merkez erişilemez olduğu gün panele kimse giremezdi —
     * yöneticisi dahil.
     */
    public function test_merkez_acikken_parola_formu_katlanir_ama_durur(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Kimlik Yönetim Merkezi ile giriş')
            ->assertSee('Parolayla giriş')
            ->assertSee('Oturum aç')
            ->assertSee('name="password"', false);
    }

    /** Merkez kapalıyken ekran eskisi gibi: form açık, düğme yok. */
    public function test_merkez_kapaliyken_form_acik_gelir(): void
    {
        config(['identity.acik' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Kimlik Yönetim Merkezi ile giriş')
            ->assertSee('Oturum aç');
    }

    /**
     * Yapılandırılmamış kurulumda ayar AÇIK OLSA DA özellik yok.
     *
     * Ayarın yeteneği geçersiz kılabilmesi, kullanıcıyı çalışmayan bir yola
     * göndermek demekti.
     */
    public function test_yapilandirilmamis_kurulumda_ayar_acik_olsa_da_uc_yoktur(): void
    {
        config(['identity.acik' => true, 'identity.configured' => false]);

        $this->get(route('login'))->assertOk()->assertDontSee('Kimlik Yönetim Merkezi ile giriş');
        $this->get(route('identity.login'))->assertNotFound();
        $this->get(route('identity.callback'))->assertNotFound();
    }

    /** Ayar kapatıldığında uçlar da kapanır: düğmesiz ama açık bir kapı kalmaz. */
    public function test_ayar_kapaliyken_uclar_da_kapanir(): void
    {
        config(['identity.acik' => false]);

        $this->get(route('identity.login'))->assertNotFound();
        $this->get(route('identity.callback'))->assertNotFound();
    }

    public function test_baslangic_merkeze_pkce_ile_yonlendirir(): void
    {
        $response = $this->get(route('identity.login'))->assertRedirect();

        $address = $response->headers->get('Location');
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://merkez.test/oauth/authorize', $address);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('istemci-1', $query['client_id']);
        $this->assertSame('https://panel.test/login/center/callback', $query['redirect_uri']);

        /*
         * S256 ŞART: "plain" kipinde doğrulayıcı adres çubuğunda açıkça
         * gider ve kodu kapan biri jetona çevirebilirdi.
         */
        $this->assertSame('S256', $query['code_challenge_method']);

        $verifier = session('kimlik_dogrulayici');
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
            'Meydan okuma, oturumda duran doğrulayıcının özeti olmalı.',
        );

        $this->assertSame(session('kimlik_durum'), $query['state']);
    }

    /**
     * Başlatmadığımız bir dönüş İŞLENMEZ.
     *
     * Saldırgan kendi kodunu kurbanın tarayıcısında kullandırıp onu kendi
     * merkez hesabına bağlayabilirdi.
     */
    public function test_durum_uyusmazsa_giris_yapilmaz(): void
    {
        Http::fake();
        $user = User::factory()->create(['email' => 'ali@example.test']);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'kimlik', 'subject' => 'merkez-1']);

        $this->return(['state' => 'baska-durum'])->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_oturumda_durum_yoksa_giris_yapilmaz(): void
    {
        Http::fake();

        $this->get(route('identity.callback', ['code' => 'kod-1', 'state' => 'durum-1']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_bagli_kimlik_dogrudan_giris_yapar(): void
    {
        $this->center($this->identity(['email' => 'baska@example.test']));

        $user = User::factory()->create(['email' => 'ali@example.test']);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'kimlik', 'subject' => 'merkez-1']);

        $this->return()->assertRedirect(route('overview'));

        /* Bağ SUBJECT ile kuruluydu: merkezdeki e-posta değişmiş olsa da giriş bozulmuyor. */
        $this->assertAuthenticatedAs($user);
    }

    public function test_dogrulanmis_eposta_tek_hesapla_eslesir_ve_bag_kurulur(): void
    {
        $this->center($this->identity());

        $user = User::factory()->create(['email' => 'ali@example.test']);

        $this->return()->assertRedirect(route('overview'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'kimlik',
            'subject' => 'merkez-1',
        ]);
    }

    /**
     * Merkezî giriş "beni hatırla" çerezi bırakmaz; yoksa 60 dakikalık
     * boşta kalma süresi 400 günlük çerezle etkisiz kalırdı.
     */
    public function test_merkez_girisi_beni_hatirla_cerezi_birakmaz(): void
    {
        $this->center($this->identity());
        $user = User::factory()->create(['email' => 'ali@example.test']);

        $response = $this->return()->assertRedirect(route('overview'));

        $this->assertAuthenticatedAs($user);
        $cookies = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
        $this->assertSame([], array_values(array_filter($cookies, fn (string $name): bool => str_starts_with($name, 'remember_'))));
    }

    /**
     * Doğrulanmamış adresle eşleşme YOK.
     *
     * Merkezde meslektaşının adresiyle hesap açan biri, onun yerine bu panele
     * girebilirdi. (Merkezde adresi doğrulayan şey posta değil, hesabı
     * etkinleştiren yöneticinin onayıdır.)
     */
    public function test_dogrulanmamis_eposta_eslesmez(): void
    {
        $this->center($this->identity(['email_verified' => false]));

        User::factory()->create(['email' => 'ali@example.test']);

        $this->return()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('user_identities', 0);
    }

    /**
     * Aynı adresi iki hesap taşıyamaz.
     *
     * E-postayla eşleşmenin tek karşılık şartı, ancak adres BENZERSİZSE
     * anlamlı: iki hesap aynı adresi taşısaydı hangisine girileceği belli
     * olmazdı. Kural veritabanında duruyor; `AccountMatcher` ayrıca
     * `limit(2)` ile sayıyor, yani kısıt bir gün kalkarsa eşleşme tahmine
     * dönüşmez, sessizce durur.
     */
    public function test_eposta_benzersizdir(): void
    {
        User::factory()->create(['email' => 'ali@example.test']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => 'ali@example.test']);
    }

    /**
     * MERKEZ KAPIYI KAPATTIYSA KAPI KAPALIDIR.
     *
     * Hangi rolün buraya girebileceğine merkez karar veriyor ve rolü izinli
     * olmayana `/oauth/userinfo` ucunda 403 dönüyor. Panel bunu "merkez
     * cevap vermedi"den AYIRMALI: orada kullanıcı parolasıyla girişe düşer,
     * burada kapı kapalıdır ve hesabı varsa bile merkezden giremez.
     */
    public function test_merkez_rolu_reddederse_giris_olmaz(): void
    {
        User::factory()->create(['email' => 'ali@example.test']);

        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton-1']),
            'merkez.test/oauth/userinfo' => Http::response(
                ['error' => 'Bu uygulamaya erişim yetkiniz yok.', 'code' => 'role_forbidden'],
                403,
            ),
        ]);

        $this->return()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Bu uygulamaya erişim yetkiniz yok.']);

        $this->assertGuest();
    }

    /**
     * MERKEZ İZİN VERMEDİYSE HESAP AÇILMAZ.
     *
     * Kararı merkez veriyor ve yanıtında söylüyor (`hesap_acilabilir`).
     * Söylemiyorsa yanıt HAYIR sayılır: eksik bilgi yetki üretmemeli.
     */
    public function test_merkez_izin_vermediyse_hesap_acilmaz(): void
    {
        $this->center($this->identity(['email' => 'yabanci@example.test']));

        $this->return()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'yabanci@example.test']);
    }

    /**
     * Merkez izin verdiğinde hesap MERKEZDEKİ ROLÜN yerel karşılığıyla açılır.
     *
     * Eşleme rol slug'ı iledir: merkezdeki rol adı yereldeki slug ile eşleşir.
     */
    public function test_merkez_izin_verince_hesap_merkezdeki_rolle_acilir(): void
    {

        $this->role('network');

        $this->center($this->identity(['email' => 'kerem@example.test', 'name' => 'Kerem', 'role' => 'network', 'can_create_account' => true]));

        $this->return()->assertRedirect();

        $new = User::where('email', 'kerem@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($new);
        $this->assertSame('Kerem', $new->name);
        $this->assertSame('network', $new->role?->slug);
        $this->assertTrue($new->isActive());
    }

    /**
     * MERKEZİN YÖNETİCİSİ BURADA KENDİLİĞİNDEN YÖNETİCİ OLMAZ.
     *
     * Merkezin yöneticisi kimlikleri yönetir; buranın yöneticisi cihazlara
     * yazabilir ve parola değiştirebilir. Devrolsaydı merkezde rol dağıtabilen
     * biri, buradaki hiçbir yöneticinin haberi olmadan cihazlara yazma
     * yetkisi kazanırdı.
     */
    public function test_merkezdeki_yoneticilik_kendiliginden_devrolmaz(): void
    {

        $this->role('admin', admin: true);
        $this->role('teknik', default: true);

        $this->center($this->identity(['email' => 'yonetici@example.test', 'role' => 'admin', 'can_create_account' => true]));

        $this->return()->assertRedirect();

        $new = User::where('email', 'yonetici@example.test')->firstOrFail();

        $this->assertSame('teknik', $new->role?->slug);
        $this->assertFalse($new->isAdmin());
    }

    /** Açıkça devredilirse geçer; karar yöneticinin. */
    public function test_yonetici_devri_acikken_yoneticilik_gecer(): void
    {
        config(['identity.yonetici_devri' => true]);

        $this->role('admin', admin: true);

        $this->center($this->identity(['email' => 'yonetici@example.test', 'role' => 'admin', 'can_create_account' => true]));

        $this->return()->assertRedirect();

        $this->assertTrue(User::where('email', 'yonetici@example.test')->firstOrFail()->isAdmin());
    }

    /** Tanınmayan rol adı yetki üretmez: en dar role düşer. */
    public function test_taninmayan_rol_varsayilana_duser(): void
    {

        $this->role('teknik', default: true);

        $this->center($this->identity(['email' => 'yabanci@example.test', 'role' => 'boyle-bir-rol-yok', 'can_create_account' => true]));

        $this->return()->assertRedirect();

        $this->assertSame('teknik', User::where('email', 'yabanci@example.test')->firstOrFail()->role?->slug);
    }

    /**
     * DOĞRULANMAMIŞ E-POSTAYLA HESAP AÇILMAZ.
     *
     * Açılsaydı, meslektaşının adresiyle merkezde hesap açan biri bu panelin
     * kapısını da açmış olurdu.
     */
    public function test_dogrulanmamis_eposta_ile_hesap_acilmaz(): void
    {

        $this->center($this->identity(['email' => 'sahte@example.test', 'email_verified' => false, 'can_create_account' => true]));

        $this->return()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'sahte@example.test']);
    }

    /** Açılan hesap PAROLA İLE girilemez: kapısı merkezdir. */
    public function test_acilan_hesabin_parolasi_bilinmez(): void
    {

        $this->role('teknik', default: true);

        $this->center($this->identity(['email' => 'kerem@example.test', 'role' => 'teknik', 'can_create_account' => true]));
        $this->return();

        $new = User::where('email', 'kerem@example.test')->firstOrFail();

        foreach (['', 'password', 'kerem@example.test', 'teknik'] as $trial) {
            $this->assertFalse(Hash::check($trial, $new->password));
        }
    }

    public function test_pasif_hesap_merkezden_de_giremez(): void
    {
        $this->center($this->identity());

        User::factory()->create(['email' => 'ali@example.test', 'status' => UserStatus::Inactive]);

        $this->return()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** Merkez cevap vermiyorsa kullanıcı ekranda kalmaz, parola yoluna döner. */
    public function test_merkez_ulasilamazsa_giris_ekranina_donulur(): void
    {
        Http::fake(['merkez.test/*' => Http::response('', 500)]);

        User::factory()->create(['email' => 'ali@example.test']);

        $this->return()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Sertifika doğrulaması HİÇBİR DALDA kapatılmaz.
     *
     * Merkez şirket içi bir adreste durabilir ve orada genel bir sertifika
     * otoritesi yoktur; akla gelen ilk çözüm `verify => false`'tur ve
     * yanlıştır — jeton takasında araya giren biri istemci sırrını okur.
     * Doğru olan, güvenilen kökü DARALTMAK: doğrulama açık kalır, yalnızca
     * kime güvenileceği daralır.
     */
    public function test_kok_verilince_dogrulama_daraltilir_kapatilmaz(): void
    {
        $options = [];

        config(['identity.ca_cert' => '/tmp/merkez.crt']);

        Http::fake(function ($request, $setting) use (&$options) {
            $options[] = $setting;

            return str_contains($request->url(), '/oauth/token')
                ? Http::response(['access_token' => 'jeton-1'])
                : Http::response($this->identity());
        });

        User::factory()->create(['email' => 'ali@example.test']);

        $this->return()->assertRedirect(route('overview'));

        $this->assertCount(2, $options, 'Hem jeton hem kullanıcı isteği gitmeli.');

        foreach ($options as $setting) {
            $this->assertSame('/tmp/merkez.crt', $setting['verify']);
        }
    }

    /** Kök verilmemişse olağan doğrulama sürer; kapatma diye bir dal yok. */
    public function test_kok_yoksa_dogrulama_olagan_kalir(): void
    {
        $options = [];

        config(['identity.ca_cert' => null]);

        Http::fake(function ($request, $setting) use (&$options) {
            $options[] = $setting;

            return str_contains($request->url(), '/oauth/token')
                ? Http::response(['access_token' => 'jeton-1'])
                : Http::response($this->identity());
        });

        User::factory()->create(['email' => 'ali@example.test']);

        $this->return()->assertRedirect(route('overview'));

        foreach ($options as $setting) {
            $this->assertNotFalse($setting['verify'] ?? true, 'Doğrulama kapatılmış.');
        }
    }

    /**
     * Kod TEK KULLANIMLIK: doğrulayıcı ve durum oturumdan silinir.
     *
     * Kalsalardı geri düğmesiyle aynı dönüş yeniden işlenebilirdi.
     */
    public function test_akis_bilgileri_oturumdan_silinir(): void
    {
        $this->center($this->identity());

        User::factory()->create(['email' => 'ali@example.test']);

        $this->return();

        $this->assertNull(session('kimlik_durum'));
        $this->assertNull(session('kimlik_dogrulayici'));
    }

    /**
     * MERKEZDE ROLÜ YOKSA HESAP AÇILMAZ.
     *
     * Merkezde yeni açılmış, henüz kimsenin bakmadığı hesap rolsüz duruyor.
     * Rolsüzü de içeri alsaydık, merkezde kendi kendine kaydolan herkes buraya
     * varsayılan rolle düşerdi — oysa rol atamak, o kişiye bakılmış olmasının
     * kendisidir.
     */
    public function test_merkezde_rolu_olmayan_icin_hesap_acilmaz(): void
    {

        $this->role('teknik', default: true);

        $this->center($this->identity(['email' => 'rolsuz@example.test', 'role' => null, 'can_create_account' => true]));

        $this->return()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'rolsuz@example.test']);
    }

    /**
     * AÇILAN HESAP ROLÜN HAZIR YETKİLERİYLE BAŞLAR.
     *
     * Rol tek başına yetki değil, başlangıç kümesi: yetkiler kullanıcıya
     * kopyalanıyor. Ekrandan açılan hesapta bu yapılıyordu, merkezden
     * açılanda yapılmıyordu — sonuç "rol verdim ama hiçbir şey göremiyor"
     * oluyordu.
     */
    public function test_acilan_hesap_rolun_yetkileriyle_calisir(): void
    {

        $this->role('network', template: ['servers' => ['view', 'connect']]);

        $this->center($this->identity(['email' => 'kerem@example.test', 'role' => 'network', 'can_create_account' => true]));

        $this->return();

        $new = User::where('email', 'kerem@example.test')->firstOrFail();

        $this->assertSame(0, $new->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue($new->can('viewAny', Server::class), 'Rolün yetkisi geçerli olmalı.');
    }

    /**
     * MERKEZDEKİ DÖRT ROLÜN BURADA DA KARŞILIĞI VAR.
     *
     * Karşılığı olmayan rol varsayılana düşüyordu; merkezden gelen
     * muhasebeciye teknik yetkisi vermek olurdu.
     */
    public function test_merkezdeki_dort_rolun_karsiligi_vardir(): void
    {
        foreach (['admin', 'network', 'teknik', 'muhasebe'] as $slug) {
            $this->assertNotNull(
                Role::query()->where('slug', $slug)->first(),
                "Merkezdeki '{$slug}' rolünün burada karşılığı olmalı.",
            );
        }
    }
}
