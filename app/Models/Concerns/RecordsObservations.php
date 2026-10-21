<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * ÖLÇÜM, DÜZENLEME DEĞİLDİR.
 *
 * Çakışma koruması (EnsureRecordIsUnchanged) kaydın "updated_at" damgasını
 * okuyor: form açıldığındaki damga ile gönderim anındaki damga farklıysa
 * "bu kaydı biri değiştirdi" der ve işlemi reddeder.
 *
 * Arka plandaki ölçüm turları o damgayı ilerletseydi — switch durumu
 * saniyeler, port ölçümü dakikalar içinde yenilenir — kimse değiştirmediği
 * hâlde kullanıcının açık formu reddedilirdi: bir porta verilen hız ayarı
 * sessizce düşer, cihaz ayarları formu ise sayfa açıldıktan kısa süre sonra
 * çalışmaz hâle gelirdi.
 *
 * Gözlemin ne zaman yapıldığı kendi alanlarında duruyor (last_polled_at,
 * last_sync_at). "updated_at" İNSAN düzenlemesini gösterir.
 */
trait RecordsObservations
{
    /**
     * Gözlem yazar: sürüm damgasına DOKUNMAZ.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveObservation(array $attributes): void
    {
        $this->timestamps = false;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->timestamps = true;
        }
    }
}
