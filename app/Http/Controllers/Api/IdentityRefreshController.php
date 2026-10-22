<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\CenterClient;
use App\Services\Identity\IdentitySyncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Merkez "şu kimlik değişti" der; panel doğruyu KENDİ sorar.
 *
 * NEDEN VAR: rol ya da durum değişikliği zamanlanmış eşitleme turunu
 * beklememeli; erişimi kaldırılan biri tur gelene kadar panelde çalışmaya
 * devam etmemeli.
 *
 * İKİ KATLI KORUMA:
 *
 *  1. İMZA. İstek, merkezin o projeye verdiği BİLDİRİM SIRRI ile
 *     imzalanır (HMAC). Sır ayrı bir alandır; OAuth istemci sırrı bu iş için
 *     kullanılamaz çünkü merkezde bcrypt özeti olarak duruyor — merkez onu
 *     doğrulayabilir ama üretemez.
 *  2. GELEN VERİYE GÜVENİLMEZ. İstek yalnızca hangi kimliğin değiştiğini
 *     söyler; yeni rol ya da durum gövdeden OKUNMAZ. Panel merkeze kendi
 *     arka kanalından sorar ve yanıtı uygular. İmza bir gün sızsa bile
 *     kimsenin rolü yükseltilemez; yapılabilecek tek şey panelin merkezle
 *     HİZALANMASINI erkene almaktır.
 *
 * Sır tanımlı değilse bildirim KABUL EDİLMEZ: imzasız çalışan bir uç,
 * ayarın eksik olduğunu kimseye söylemeden açık kalırdı. O panel
 * değişikliği zamanlanmış eşitleme turuyla öğrenir.
 *
 * Yük de üç frenle sınırlanır: uç başına hız sınırı (rota), kimlik başına
 * bekleme ve YEREL KARŞILIĞI OLMAYAN kimlik için merkeze hiç gitmemek.
 *
 * Zamanlanmış tur YİNE DE ÇALIŞIR: bildirim kaybolursa (ağ, yeniden
 * başlatma) erişim sonsuza dek eski kalmamalı.
 */
class IdentityRefreshController extends Controller
{
    /** Aynı kimlik için merkeze bu kadar saniye içinde ikinci kez sorulmaz. */
    private const WAIT = 10;

    public function __invoke(
        Request $request,
        CenterClient $center,
        IdentitySyncer $syncer,
    ): JsonResponse {
        $subject = trim((string) $request->input('subject'));
        $secret = (string) config('identity.notify_secret');

        /*
         * Sabit süreli karşılaştırma: doğru imzanın ne kadarının tuttuğu
         * ölçülebilmemeli.
         */
        $signed = $secret !== '' && hash_equals(
            hash_hmac('sha256', $subject, $secret),
            (string) $request->header('X-Identity-Signature'),
        );

        if ($signed && $subject !== '' && $center->isOpen()) {
            $this->refresh($subject, $center, $syncer);
        }

        /*
         * YANIT HER ZAMAN AYNI. "Bu kimlik burada var mı", "imza tuttu mu"
         * soruları bu uçtan öğrenilemez.
         */
        return response()->json(['result' => 'received']);
    }

    private function refresh(string $subject, CenterClient $center, IdentitySyncer $syncer): void
    {
        $bag = UserIdentity::with('user')
            ->where('provider', AccountMatcher::PROVIDER)
            ->where('subject', $subject)
            ->first();

        /*
         * Bu kimlik burada hiç kullanılmamışsa merkeze GİDİLMEZ: bildirim
         * bütün panellere gidiyor ve her panelde her kullanıcının hesabı
         * olmak zorunda değil.
         */
        if ($bag?->user === null) {
            return;
        }

        $key = 'kimlik:tazele:'.$subject;

        if (! Cache::add($key, true, self::WAIT)) {
            return;
        }

        try {
            $statuses = $center->identityStatuses([$subject]);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if (isset($statuses[$subject])) {
            $syncer->apply($bag->user, (array) $statuses[$subject]);
        }
    }
}
