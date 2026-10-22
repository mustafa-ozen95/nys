<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\CenterClient;
use App\Services\Identity\IdentitySyncer;
use App\Services\Identity\SharedLogout;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Merkezle giren kişinin merkezdeki durumu HÂLÂ geçerli mi?
 *
 * NEDEN VAR: merkezde rol değişince ya da hesap askıya alınınca buradaki
 * kopya eskir. Kişi merkeze giremediği hâlde, oturumu zaten açık olduğu için
 * burada erişmeye devam etmemeli. Girişte bakmak yetmez: değişiklik, kişi
 * hiç giriş yapmadan da olur.
 *
 * HER İSTEKTE SORULMAZ. Yanıt kullanıcı başına önbelleklenir
 * (`identity.dogrulama_dakika`); aksi hâlde her sayfa açılışı merkeze bir istek
 * atardı. Aralık, "erişimi kalkan biri en fazla ne kadar daha içeride
 * kalabilir" sorusunun yanıtıdır.
 *
 * MERKEZ ERİŞİLEMEZSE KİMSE ATILMAZ. Bu panelin kendi girişi merkezden
 * bağımsızdır ve merkez düştüğü gün buraya girilebilmelidir; "sorulamadı"yı
 * "erişimi yok" saymak, bir kesintide bütün kadroyu dışarı atmak olurdu.
 * Hata kayda geçer ve kısa bir süre yeniden denenmez.
 */
class VerifyIdentityStatus
{
    /** Merkez yanıt vermediğinde bu kadar dakika yeniden sorulmaz. */
    private const ERROR_WAIT = 1;

    public function __construct(
        private CenterClient $center,
        private IdentitySyncer $syncer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->center->isOpen()) {
            return $next($request);
        }

        /*
         * ORTAK ÇIKIŞ HER İSTEKTE sorulur, önbellekten önce: merkezden ya da
         * başka bir panelden çıkılınca bu oturum bir sonraki tıklamada
         * kapanır (bkz. SharedLogout). Tek satırlık bir sorgu.
         */
        $shared = SharedLogout::bag($user);

        if ($shared !== null && $request->hasSession() && SharedLogout::isSessionStale($shared, $request->session())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Merkezden ya da bağlı bir panelden çıkış yapıldığı için oturumunuz kapatıldı.',
            ]);
        }

        $key = 'kimlik:dogrulama:'.$user->getKey();

        if (Cache::has($key)) {
            return $next($request);
        }

        $bag = UserIdentity::where('user_id', $user->getKey())
            ->where('provider', AccountMatcher::PROVIDER)
            ->first();

        /* Merkezle hiç bağlanmamış hesap buraya girmez: sorulacak bir kimlik yok. */
        if ($bag === null) {
            return $next($request);
        }

        try {
            $statuses = $this->center->identityStatuses([$bag->subject]);
        } catch (Throwable $e) {
            report($e);
            Cache::put($key, true, now()->addMinutes(self::ERROR_WAIT));

            return $next($request);
        }

        if (isset($statuses[$bag->subject])) {
            $this->syncer->apply($user, (array) $statuses[$bag->subject]);
        }

        Cache::put($key, true, now()->addMinutes((int) config('identity.verify_minutes')));

        /*
         * Erişimi kalkan kişi İSTEĞİN ORTASINDA dışarı alınır; bir sonraki
         * girişi beklemek, kapatılmış bir hesabın oturumunu açık bırakmak
         * olurdu.
         */
        if (! $user->fresh()?->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Merkezdeki erişiminiz kalktığı için oturumunuz kapatıldı.',
            ]);
        }

        return $next($request);
    }
}
