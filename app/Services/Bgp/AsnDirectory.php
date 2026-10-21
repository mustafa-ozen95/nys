<?php

declare(strict_types=1);

namespace App\Services\Bgp;

use App\Models\NetworkSwitch;
use App\Support\Net\Asn;

/**
 * Kullanılabilir yerel AS numaraları.
 *
 * Kaynak switchlerin kendi AS numaralarıdır (routing-options
 * autonomous-system) ve cihazdan eşitlenir.
 *
 * Liste elle girilen bir kolondan beslenmez: öyle olsaydı aynı switch için
 * iki farklı değer taşıyabilirdi — cihazda karşılığı olmayan bir durum.
 *
 * Bu liste bir KOLAYLIKTIR, kısıt değildir. Anons yalnızca kendi ASN'lerimiz
 * için yapılmıyor: müşteri kendi numarası için de talep edebiliyor ve o numara
 * hiçbir switchte yazmaz. Bu yüzden anons ekranındaki alan listeye kilitli
 * değildir, elle giriş de kabul eder.
 */
class AsnDirectory
{
    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $recorded = NetworkSwitch::query()
            ->whereNotNull('autonomous_system')
            ->distinct()
            ->orderBy('autonomous_system')
            ->pluck('autonomous_system')
            ->all();

        $fallback = config('announcements.default_asn');

        $asns = $recorded === [] && filled($fallback) ? [$fallback] : $recorded;

        /*
         * Anahtarlar "AS65000" biçimindedir; array_combine ile çıplak sayı
         * kullanılamaz. "65000" gibi sayısal bir dize PHP'de dizi anahtarı
         * olurken int'e döner ve default() metnin yerine sayı verir; bu da
         * anons ekranını 500 hatasına düşürür.
         */
        return collect($asns)
            ->map(fn (int|string $asn): ?string => Asn::normalize((string) $asn))
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $asn): array => [$asn => $asn])
            ->all();
    }

    public function default(): ?string
    {
        $first = array_key_first($this->options());

        return $first === null ? null : (string) $first;
    }
}
