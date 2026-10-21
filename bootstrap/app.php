<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureRecordIsUnchanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RecordUserActivity;
use App\Http\Middleware\RequireActionReason;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SharePanelMode;
use App\Http\Middleware\ThrottleApiClients;
use App\Http\Middleware\VerifyIdentityStatus;
use App\Services\Netconf\NetconfException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',

        /*
         * API ayrı bir kapıdır: oturum çerezi taşımaz, CSRF beklemez ve
         * kimliğini yalnızca jetondan alır (bkz. config/auth.php).
         */
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TERS VEKİLE GÜVEN — konteyner kurulumunda ŞART.
         *
         * TLS önde duran vekilde (Caddy) sonlanıyor ve konteynere düz HTTP
         * geliyor. Bu satır olmadan uygulama isteği "http" sanar: ürettiği
         * bütün mutlak adresler http olur, Secure çerez gönderilmez ve
         * merkeze giden OAuth dönüş adresi kayıtlı adresle eşleşmediği için
         * takas "redirect_uri mismatch" ile reddedilir.
         *
         * Liste '*' DEĞİL: X-Forwarded-For'a güvenmek onu YAZILABİLİR kılar.
         * Hız sınırları ve denetim kayıtları istemci adresine bakıyor; herkese
         * güvenilseydi kendi adresini yazan biri ikisini de atlatırdı.
         * Varsayılan özel ağlardır (docker köprüsü 172.16/12 içinde kalır);
         * başka bir yerleşimde TRUSTED_PROXIES ile daraltılır.
         */
        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env(
                'TRUSTED_PROXIES',
                '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->redirectGuestsTo(fn (): string => route('login'));

        /*
         * Parola değişince DİĞER OTURUMLAR GERÇEKTEN DÜŞER.
         *
         * Auth::logoutOtherDevices (profil ekranındaki parola değişimi) yalnızca
         * parola özetini yeniden yazar; kapıda bu özeti oturumdakiyle
         * karşılaştıran AuthenticateSession yoksa hiçbir şey yapmaz ve eski
         * oturum açık kalırdı. Yönetici parolayı sıfırladığında da aynı özet
         * değişir, bu yüzden o yol da kapsanır. Dağıtımda herkes BİR KEZ oturum
         * kapatmış olur (oturumlarda henüz özet damgası yok).
         */
        $middleware->authenticateSessions();

        // Güvenlik başlıkları uygulamadan gelir; vhost değişse de kaybolmaz.
        $middleware->append(SecurityHeaders::class);

        /*
         * Sürüm çakışması kontrolü tek yerden geçer: her düzenleme formunun
         * kendi kontrolünü yazması hem tekrar hem de unutulacak bir adım
         * olurdu. Sürüm damgası taşımayan istekler etkilenmez.
         *
         * Global yığına DEĞİL web grubuna eklenir: rota parametreleri modele
         * ancak SubstituteBindings'ten sonra dönüşür, global yığında hâlâ ham
         * metindir ve kontrol sessizce hiçbir şey yapmazdı.
         */
        $middleware->web(append: [EnsureRecordIsUnchanged::class]);

        /*
         * Durumu değiştiren her işlem gerekçe ister. Kontrol sunucudadır:
         * onay penceresini atlayıp doğrudan istek göndermek işe yaramaz.
         */
        /*
         * Merkezle giren kişinin merkezdeki durumu HÂLÂ geçerli mi?
         *
         * Merkezde rolü değişen ya da hesabı askıya alınan biri, oturumu açık
         * diye burada erişmeye devam etmemeli. Merkeze her istekte
         * sorulmaz: yanıt kullanıcı başına önbelleklenir
         * (bkz. VerifyIdentityStatus).
         */
        /*
         * SIRA: önce hesap etkin mi, sonra merkezdeki durumu, sonra gerekçe.
         *
         * EnsureUserIsActive yalnızca ana rota grubunda olsaydı "hesap"
         * grubu onu taşımaz ve pasife alınmış biri hâlâ kendi profilini ve
         * parolasını değiştirebilirdi. Kural bu yüzden her web isteğini kapsar.
         */
        $middleware->web(append: [
            EnsureUserIsActive::class,
            VerifyIdentityStatus::class,
            RequireActionReason::class,
        ]);

        /*
         * Kullanıcının her hareketi kayda geçer. Yazma yanıt gönderildikten
         * sonra (terminate) yapılır; sayfayı bekletmez.
         */
        $middleware->web(append: [RecordUserActivity::class]);

        /*
         * Panelin ayrıntı düzeyi bütün görünümlere tek yerden verilir; her
         * ekranın kullanıcıya ayrıca sorması gerekmez.
         */
        $middleware->web(append: [SharePanelMode::class]);

        /*
         * API istekleri de kayda geçer: jetonla yapılan çağrı, tarayıcıdan
         * yapılan kadar denetlenebilir olmalı.
         */
        $middleware->api(append: [RecordUserActivity::class]);

        /*
         * Kimliği doğrulanmamış istek de sınırlanır. Rota üzerindeki
         * "throttle:..." bunu yapamıyor: Laravel Authenticate'i
         * ThrottleRequests'ten önce sıralıyor, dolayısıyla geçersiz jeton 401
         * ile biter ve sınır hiç çalışmaz. Bu sınıf öncelik listesinde
         * olmadığı için grubun başındaki yerini korur.
         */
        $middleware->api(prepend: [ThrottleApiClients::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Cihazın reddi bir program hatası DEĞİLDİR.
         *
         * Junos, panelin kabul ettiği ama kendi kurallarına uymayan bir
         * yapılandırmayı commit check'te reddeder: "Referenced filter is not
         * defined", "prefix-list referenced but not defined", "no other
         * family type can be configured". Bunlar olağan durumlardır ve
         * kullanıcıya CİHAZIN CÜMLESİYLE gösterilmelidir — 500 sayfası
         * kullanıcıya ne yapacağını söylemez ve kaydı da bulanıklaştırır.
         *
         * Geri alınabilir commit sayesinde cihazda kalıcı bir değişiklik
         * olmaz; anlatılacak tek şey nedenidir.
         */
        $exceptions->render(function (NetconfException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        });
    })->create();
