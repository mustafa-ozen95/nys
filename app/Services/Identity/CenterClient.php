<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kimlik Yönetim Merkezi ile konuşan istemci.
 *
 * AKIŞ: Authorization Code + PKCE. Parola bu panele HİÇ uğramaz; kullanıcı
 * merkezin kendi sayfasında giriş yapar, buraya yalnızca tek kullanımlık bir
 * kod döner.
 *
 * PKCE NEDEN: kod, tarayıcı üzerinden adres çubuğunda geliyor. Araya giren
 * biri kodu kapsa bile, doğrulayıcıyı (yalnızca bizim oturumumuzda duran
 * rastgele dize) bilmediği için jetona çeviremez.
 */
class CenterClient
{
    /**
     * Merkez ile giriş açık mı?
     *
     * İKİ KOŞUL: yöneticinin ayardan verdiği karar VE istemci bilgilerinin
     * varlığı. Ayar tek başına yeterli olsaydı, yapılandırılmamış bir
     * kurulumda düğme açılabilir ve kullanıcı çalışmayan bir yola gönderilirdi.
     *
     * Soru tek yerden sorulur: rota da, giriş ekranı da buradan geçer.
     */
    public function isOpen(): bool
    {
        return (bool) config('identity.acik') && (bool) config('identity.configured');
    }

    public function verifier(): string
    {
        /* RFC 7636: 43-128 karakter, URL-güvenli. */
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function challengeRead(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Kullanıcının gönderileceği adres. */
    public function loginAddress(string $status, string $challengeRead): string
    {
        return config('identity.url').'/oauth/authorize?'.http_build_query([
            'client_id' => config('identity.client_id'),
            'redirect_uri' => config('identity.redirect_uri'),
            'response_type' => 'code',
            'scope' => '',
            'state' => $status,
            'code_challenge' => $challengeRead,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Merkeze giden her istek aynı doğrulamayı kullanır.
     *
     * Sertifika kökü yapılandırılmışsa yalnızca ONA güvenilir; kök verilmemişse
     * olağan doğrulama sürer. Hiçbir dalda doğrulama kapatılmaz.
     */
    /**
     * Merkezdeki kimliklerin ŞU ANKİ durumu (arka kanal).
     *
     * NEDEN VAR: merkezde rol değişince ya da hesap askıya alınınca buradaki
     * kopya eskir; o kişi burada erişmeye devam etmemeli. Girişte bakmak
     * yetmez — değişiklik, kişi hiç giriş yapmadan da olur.
     *
     * KULLANICI JETONU YOK: soran biz değil, sunucumuz. Kimlik doğrulaması
     * istemci kimliği ve sırrıyla yapılır.
     *
     * YALNIZCA SORULAN DÖNER: merkez bütün kadroyu listelemez. Sorulmayan bir
     * kimlik yanıtta yoktur ve çağıran ona dokunmaz.
     *
     * @param  list<string>  $kimlikler  merkezdeki uuid'ler
     * @return array<string, array{status: string, can_enter?: bool, role?: ?string, last_logout?: ?int}>
     *
     * @throws RuntimeException merkez yanıt vermezse
     */
    public function identityStatuses(array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $response = $this->request()->acceptJson()->post(config('identity.url').'/api/identity-status', [
            'client_id' => config('identity.client_id'),
            'client_secret' => config('identity.client_secret'),
            'identities' => array_values($identities),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Merkez kimlik durumlarını vermedi ('.$response->status().').');
        }

        return (array) $response->json('identities', []);
    }

    /**
     * Ortak çıkış: "bu kişi bende çıkış yaptı" (merkez: api/session-closed).
     * Merkez anı yazar, kendi oturumunu ve öteki panelleri kapatır.
     * Bekleme kısa: merkeze ulaşılamıyorsa çıkış yine olur, yalnız ortak kalmaz.
     */
    public function sessionClosed(string $subject): void
    {
        $response = $this->request()->timeout(5)->acceptJson()->post(config('identity.url').'/api/session-closed', [
            'client_id' => config('identity.client_id'),
            'client_secret' => config('identity.client_secret'),
            'subject' => $subject,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Merkez çıkışı almadı ('.$response->status().').');
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(15);
        $root = config('identity.ca_cert');

        return filled($root) ? $request->withOptions(['verify' => $root]) : $request;
    }

    /**
     * Kodu jetona çevirir.
     *
     * @return array<string, mixed>
     */
    public function token(string $code, string $verifier): array
    {
        $response = $this->request()->asForm()->post(config('identity.url').'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('identity.client_id'),
            'client_secret' => config('identity.client_secret'),
            'redirect_uri' => config('identity.redirect_uri'),
            'code_verifier' => $verifier,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Merkez jetonu vermedi ('.$response->status().').');
        }

        return (array) $response->json();
    }

    /**
     * Jetonun kime ait olduğunu sorar.
     *
     * @return array{sub: string, name: string, email: string, email_verified: bool}
     */
    public function user(string $token): array
    {
        $response = $this->request()->withToken($token)->acceptJson()
            ->get(config('identity.url').'/oauth/userinfo');

        /*
         * MERKEZ KAPIYI KAPATTIYSA AYRI HATA.
         *
         * Hangi rolün hangi uygulamaya girebileceğine merkez karar veriyor
         * (İstemciler ekranındaki rol listesi) ve rolü izinli değilse burası
         * 403 döner. "Merkez cevap vermedi" ile "senin yetkin yok" aynı
         * mesaja düşerse kimse hangisi olduğunu bilemez: birincisinde
         * kullanıcı parolasıyla girişe düşmeli, ikincisinde kapı kapalı.
         */
        if ($response->status() === 403) {
            throw new CenterUnauthorizedException(
                (string) ($response->json('error') ?? 'Bu panele erişim yetkiniz yok.'),
            );
        }

        if ($response->failed()) {
            throw new RuntimeException('Merkez kimliği vermedi ('.$response->status().').');
        }

        $veri = (array) $response->json();

        if (blank($veri['sub'] ?? null) || blank($veri['email'] ?? null)) {
            throw new RuntimeException('Merkezden gelen kimlik eksik.');
        }

        return [
            'sub' => (string) $veri['sub'],
            'name' => (string) ($veri['name'] ?? ''),
            'email' => (string) $veri['email'],
            'email_verified' => (bool) ($veri['email_verified'] ?? false),

            /*
             * Merkezdeki rolün slug'ı. BİLGİDİR, EMİR DEĞİL: kararı bu panel
             * verir (bkz. AccountOpener). Merkez rol döndürmüyorsa alan boş
             * kalır ve akış bozulmaz.
             */
            'role' => filled($veri['role'] ?? null) ? (string) $veri['role'] : null,

            /*
             * HESAP KENDİLİĞİNDEN AÇILABİLİR Mİ? Kararı da merkez verir
             * (İstemciler ekranında uygulama başına bir kutucuk). Merkez
             * söylemiyorsa varsayılan HAYIR: eksik bilgi yetki üretmemeli.
             */
            'can_create_account' => (bool) ($veri['can_create_account'] ?? false),
        ];
    }
}
