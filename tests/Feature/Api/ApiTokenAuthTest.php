<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CredentialProtocol;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API jetonuyla kimlik doğrulama ve yetki daraltması.
 *
 * En kritik davranış: jeton SAHİBİNİ AŞAMAZ. Yetki, jetonun kendi listesi
 * ile sahibinin panel yetkilerinin KESİŞİMİDİR. Yöneticinin jetonu bile
 * yalnızca listesindekini yapabilir — aksi hâlde bir jeton sızdığında
 * panelin tamamı sızmış olurdu.
 */
final class ApiTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN = 'nsm_testJetonuGizliDegeri1234567890';

    public function test_jetonsuz_istek_reddedilir(): void
    {
        $this->switch();

        $this->getJson('/api/v1/switches')->assertUnauthorized();
    }

    public function test_gecersiz_jeton_reddedilir(): void
    {
        $this->getJson('/api/v1/switches', ['Authorization' => 'Bearer olmayan-jeton'])
            ->assertUnauthorized();
    }

    public function test_iptal_edilmis_jeton_reddedilir(): void
    {
        $this->token(revoked: true);

        $this->apiGet('/api/v1/switches')->assertUnauthorized();
    }

    public function test_suresi_dolmus_jeton_reddedilir(): void
    {
        $this->token(expired: true);

        $this->apiGet('/api/v1/switches')->assertUnauthorized();
    }

    /**
     * Oturum çerezi API'de kabul EDİLMEZ: tarayıcıdan gelen bir isteğin
     * çerezle API'ye geçmesi, CSRF korumasının dışına çıkmak olurdu.
     */
    /**
     * Kimliği doğrulanamayan istek de sınırlanmalı.
     *
     * "auth:api" isteği 401 ile bitirdiği için ondan SONRA gelen jeton bazlı
     * sınır hiç çalışmıyordu: geçersiz jetonla yapılan çağrı sınırsızdı.
     * Jeton 256 bit rastgele olduğu için deneme yanılma zaten umutsuz, ama
     * kimliksiz bir uca sınırsız istek atılabilmesi başlı başına açıklıktır.
     */
    public function test_gecersiz_jetonla_yapilan_istekler_sinirlanir(): void
    {
        config(['api.guest_rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', 'Bearer yanlis')
                ->getJson(route('api.v1.switches.index'))
                ->assertUnauthorized();
        }

        $this->withHeader('Authorization', 'Bearer yanlis')
            ->getJson(route('api.v1.switches.index'))
            ->assertStatus(429);
    }

    public function test_oturum_cerezi_apiyi_acmaz(): void
    {
        $this->switch();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/v1/switches')
            ->assertUnauthorized();
    }

    public function test_yetkili_jeton_switchleri_listeler(): void
    {
        $switch = $this->switch();
        $this->token(owner: $this->owner([$switch->id]));

        $this->apiGet('/api/v1/switches')
            ->assertOk()
            ->assertJsonPath('data.0.hostname', 'test1')
            ->assertJsonPath('data.0.id', $switch->uuid);
    }

    /**
     * Sıralı birincil anahtar dışarı ÇIKMAZ.
     */
    public function test_yanit_sirali_anahtar_tasimaz(): void
    {
        $switch = $this->switch();
        $this->token(owner: $this->owner([$switch->id]));

        $response = $this->apiGet('/api/v1/switches')->assertOk()->json('data.0');

        $this->assertSame($switch->uuid, $response['id']);
        $this->assertArrayNotHasKey('switch_id', $response);
    }

    /**
     * Cihaz kimlik bilgileri hiçbir koşulda yanıtta yer almaz: API jetonu
     * sızarsa cihaz erişimi de sızmış olurdu.
     */
    public function test_yanit_cihaz_parolasini_tasimaz(): void
    {
        $switch = $this->switch();
        $this->token(owner: $this->owner([$switch->id]));

        $this->apiGet('/api/v1/switches')
            ->assertOk()
            ->assertDontSee('cokgizliparola')
            ->assertJsonMissingPath('data.0.credentials');
    }

    /**
     * Jeton kendi listesinde olmayanı yapamaz.
     */
    public function test_jeton_listesinde_olmayani_yapamaz(): void
    {
        $switch = $this->switch();
        $owner = $this->owner([$switch->id], ['view', 'update']);

        // Jeton yalnızca "view" taşıyor; sahibinin "update" yetkisi var.
        $this->token(owner: $owner, abilities: ['switches' => ['view']]);

        $this->apiGet('/api/v1/switches')->assertOk();
        $this->apiGet("/api/v1/switches/{$switch->uuid}/ports")->assertForbidden();
    }

    /**
     * Jeton SAHİBİNİ aşamaz: listesinde olsa bile sahibinin yetkisi yoksa
     * geçemez.
     */
    public function test_jeton_sahibini_asamaz(): void
    {
        $switch = $this->switch();

        // Sahibinin switch yetkisi HİÇ yok.
        $owner = User::factory()->create();

        $this->token(owner: $owner, abilities: ['switches' => ['view']]);

        $this->apiGet('/api/v1/switches')->assertForbidden();
    }

    /**
     * Yöneticinin jetonu bile listesindekiyle sınırlıdır.
     *
     * Daraltma yönetici geçişinden ÖNCE çalışmak zorunda; ters sırada
     * yöneticiye ait bir jeton bütün kontrolleri geçerdi.
     */
    public function test_yoneticinin_jetonu_da_listesiyle_sinirlidir(): void
    {
        $switch = $this->switch();

        $this->token(
            owner: User::factory()->admin()->create(),
            abilities: ['switches' => ['view']],
        );

        $this->apiGet('/api/v1/switches')->assertOk();
        $this->apiGet("/api/v1/switches/{$switch->uuid}/ports")->assertForbidden();
        $this->apiGet("/api/v1/switches/{$switch->uuid}/vlans")->assertForbidden();
    }

    /**
     * Liste sahibinin GÖREBİLDİĞİ switchlerle sınırlıdır; yetkisiz kayıt
     * listede hiç görünmez.
     */
    public function test_liste_sahibinin_gordukleriyle_sinirlidir(): void
    {
        $bir = $this->switch();
        $this->switch('gizli-switch', '198.18.20.196');

        $this->token(owner: $this->owner([$bir->id]));

        $this->apiGet('/api/v1/switches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertDontSee('gizli-switch');
    }

    public function test_izinli_adres_disindan_reddedilir(): void
    {
        $switch = $this->switch();
        $this->token(owner: $this->owner([$switch->id]), allowedIps: ['198.18.20.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.18.20.40'])
            ->apiGet('/api/v1/switches')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->apiGet('/api/v1/switches')
            ->assertUnauthorized();
    }

    public function test_tek_adres_kurali_calisir(): void
    {
        $switch = $this->switch();
        $this->token(owner: $this->owner([$switch->id]), allowedIps: ['198.18.20.40']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.18.20.40'])
            ->apiGet('/api/v1/switches')->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.18.20.41'])
            ->apiGet('/api/v1/switches')->assertUnauthorized();
    }

    public function test_kullanim_kayda_gecer(): void
    {
        $switch = $this->switch();
        $token = $this->token(owner: $this->owner([$switch->id]));

        $this->withServerVariables(['REMOTE_ADDR' => '198.18.20.40'])
            ->apiGet('/api/v1/switches')->assertOk();

        $token = $token->fresh();

        $this->assertNotNull($token->last_used_at);
        $this->assertSame('198.18.20.40', $token->last_used_ip);
    }

    /**
     * Yeni sır üretmek eskisini ANINDA geçersiz kılar.
     */
    public function test_yeni_sir_eskisini_gecersiz_kilar(): void
    {
        $switch = $this->switch();
        $token = $this->token(owner: $this->owner([$switch->id]));

        $this->apiGet('/api/v1/switches')->assertOk();

        $new = $token->rotate();

        $this->apiGet('/api/v1/switches')->assertUnauthorized();

        $this->getJson('/api/v1/switches', ['Authorization' => "Bearer {$new}"])->assertOk();
    }

    /**
     * Kapatılmış hesabın jetonu da çalışmaz.
     */
    public function test_pasif_kullanicinin_jetonu_calismaz(): void
    {
        $switch = $this->switch();
        $owner = $this->owner([$switch->id]);
        $this->token(owner: $owner);

        $owner->forceFill(['status' => UserStatus::Inactive])->save();

        $this->apiGet('/api/v1/switches')->assertUnauthorized();
    }

    private function apiGet(string $url)
    {
        /*
         * Aynı testte ikinci bir istek atarken guard'ın çözdüğü kullanıcı
         * bellekte duruyor: RequestGuard onu önbelleğe alıyor ve testte
         * uygulama örneği istekler arasında paylaşılıyor. Üretimde her
         * istek kendi uygulamasını kurar (FPM) ya da Octane guard'ları
         * sıfırlar; testte biz sıfırlıyoruz ki jeton GERÇEKTEN yeniden
         * doğrulansın.
         */
        $this->app['auth']->forgetGuards();

        return $this->getJson($url, ['Authorization' => 'Bearer '.self::PLAIN]);
    }

    /**
     * @param  array<string, list<string>>  $abilities
     * @param  list<string>  $allowedIps
     */
    private function token(
        ?User $owner = null,
        array $abilities = ['switches' => ['view']],
        array $allowedIps = [],
        bool $revoked = false,
        bool $expired = false,
    ): ApiToken {
        $factory = ApiToken::factory()->withPlain(self::PLAIN);

        if ($revoked) {
            $factory = $factory->revoked();
        }

        if ($expired) {
            $factory = $factory->expired();
        }

        return $factory->create([
            'user_id' => ($owner ?? User::factory()->admin()->create())->id,
            'abilities' => $abilities,
            'allowed_ips' => $allowedIps,
        ]);
    }

    /**
     * @param  list<int>  $switchIds
     * @param  list<string>  $actions
     */
    private function owner(array $switchIds, array $actions = ['view']): User
    {
        $user = User::factory()->create();

        $allowed = [];

        foreach ($actions as $action) {
            $allowed[$action] = $switchIds;
        }

        app(UserPermissionResolver::class)->syncResource($user, 'switches', [], $allowed);

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
