<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * ORTAK ÇIKIŞ — panel tarafı (merkezdeki eşi: KYM App\Services\SharedLogout).
 *
 * Bir panelden çıkış yapan kişi merkezden ve öteki panellerden de çıkmış
 * olmalı. Her uygulama kendi oturumunu tutar; kişinin merkezdeki son
 * çıkış anından ÖNCE açılmış panel oturumu bir sonraki istekte kapanır
 * (bkz. VerifyIdentityStatus). Anı üç yol getirir:
 *
 *   - merkezin imzalı çıkış bildirimi (IdentityLogoutController),
 *   - identity-status yanıtındaki `last_logout` (bildirim kaybolursa),
 *   - bu panelden çıkış (LoginController · destroy; merkeze de bildirilir).
 *
 * An yalnızca İLERİ gider: geç gelen eski bir bildirim yeni girişi kapatmaz.
 */
final class SharedLogout
{
    /** Oturumun açıldığı an (ms); Login olayında yazılır (bkz. WriteLogin). */
    public const SESSION_KEY = 'oturum_acilis_ms';

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Çıkış ekran kilidinden mi (süre doldu)? O çıkış ortak değildir (bkz. session-lock.js). */
    public static function isTimedOut(Request $request): bool
    {
        return $request->header('X-Oturum-Zaman-Asimi') === '1';
    }

    public static function bag(User $user): ?UserIdentity
    {
        return UserIdentity::query()
            ->where('user_id', $user->getKey())
            ->where('provider', AccountMatcher::PROVIDER)
            ->first();
    }

    public static function isle(UserIdentity $bag, int $moment): void
    {
        if ($moment > (int) $bag->merkez_cikis_ms) {
            $bag->forceFill(['merkez_cikis_ms' => $moment])->save();
        }
    }

    public static function isSessionStale(UserIdentity $bag, Session $session): bool
    {
        $exit = (int) $bag->merkez_cikis_ms;

        return $exit > 0 && (int) $session->get(self::SESSION_KEY, 0) < $exit;
    }
}
