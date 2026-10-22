<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessiz kayıp güncellemeyi engeller.
 *
 * İki kullanıcı aynı kaydı aynı anda düzenlediğinde son yazan kazanıyor ve
 * diğerine hiçbir şey söylenmiyordu: firewall term'ünün sırası, BGP grubunun
 * politikası ya da bir portun VLAN üyeliği fark edilmeden geri alınabiliyordu.
 *
 * Form açılırken kaydın sürüm damgası (updated_at) gizli alanda taşınır;
 * gönderim geldiğinde kayıt o damgadan sonra değiştiyse işlem yapılmaz ve
 * kullanıcıya durum söylenir.
 *
 * Alanı taşımayan formlar etkilenmez: kontrol, alan eklenerek devreye alınır.
 * Yeni kayıt oluşturan formlarda sürüm damgası YOKTUR — ortada henüz bir
 * kayıt yoktur.
 */
class EnsureRecordIsUnchanged
{
    public const FIELD = 'record_version';

    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->input(self::FIELD);

        if ($version === null || ! $request->isMethod('PUT') && ! $request->isMethod('PATCH')) {
            return $next($request);
        }

        $record = $this->target($request);

        if ($record !== null && (string) $record->updated_at->getTimestamp() !== (string) $version) {
            throw ValidationException::withMessages([
                self::FIELD => 'Bu kayıt siz formu açtıktan sonra değişti. Sayfayı yenileyip yeniden deneyin.',
            ]);
        }

        return $next($request);
    }

    /**
     * Düzenlenen kayıt: rotadaki EN İÇTEKİ modeldir.
     *
     * İç içe rotalarda ilk parametre üst kayıttır (switch), asıl hedef
     * sondadır — "switches/{switch}/firewall/{filter}" isteğinde konu
     * filtredir, switch değil.
     */
    private function target(Request $request): ?Model
    {
        $models = array_filter(
            $request->route()?->parameters() ?? [],
            static fn (mixed $parameter): bool => $parameter instanceof Model
                && $parameter->usesTimestamps()
                && $parameter->updated_at !== null,
        );

        return $models === [] ? null : end($models);
    }
}
