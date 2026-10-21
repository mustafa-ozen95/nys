<?php

declare(strict_types=1);

namespace App\Services\Announcement\Authorities;

use App\Enums\PrefixAuthorizationState;
use App\Services\Announcement\AuthorityVerdict;
use App\Services\Announcement\PrefixAuthority;
use App\Services\Announcement\Transport\IrrdException;
use App\Services\Announcement\Transport\IrrdTransport;
use App\Support\Net\Asn;
use App\Support\Net\Cidr;
use Illuminate\Support\Facades\Log;

/**
 * IRR route/route6 nesnesi — RADB whois sunucusu üzerinden.
 *
 * ROA'dan bağımsız İKİNCİ bir kanıttır: karşı taraftaki prefix filtreleri
 * çoğunlukla IRR'den üretilir, RPKI'dan değil. Bir blok RPKI'da geçerli olup
 * IRR'de kayıtsız olabilir; o blok karşı tarafın filtresine girmez ve talep
 * cevapsız kalır.
 *
 * İKİ TUZAK VARDIR, ikisi de gerçek IRRd yanıtlarında görülür:
 *
 * 1. **Kapsayan blok yetki değildir.** Tam eşleşme sorulmazsa 192.0.2.0/24
 *    için AS64498'in 192.0.0.0/21 nesnesi dönüyor. Tam eşleşmeyi taşıma
 *    katmanı garanti eder (`!r`), ORİJİN eşleşmesini bu sınıf.
 *
 * 2. **`source: RPKI` sayılmaz.** IRRd, ROA'lardan otomatik route nesneleri
 *    üretip aynı akışta sunuyor. Bunlar kabul edilseydi IRR kontrolü ROA
 *    kontrolünün kopyası olurdu: ROA geçerliyse ikisi de hep geçerdi, yani
 *    ikinci kontrol hiçbir şey ölçmezdi. Örnek: 198.51.100.0/24 için gerçek
 *    IRR nesnesi AS64497'e ait; AS64496 yalnızca RPKI türevi nesnede görünür.
 */
class IrrRouteAuthority implements PrefixAuthority
{
    public function __construct(private IrrdTransport $irrd) {}

    public function key(): string
    {
        return 'irr';
    }

    public function label(): string
    {
        return 'IRR route nesnesi (RADB)';
    }

    /**
     * @param  array<int, Cidr>  $prefixes
     * @return array<string, AuthorityVerdict>
     */
    public function check(array $prefixes, string $asn): array
    {
        if ($prefixes === []) {
            return [];
        }

        $keys = array_map(fn (Cidr $prefix): string => $prefix->toString(), $prefixes);

        try {
            $objects = $this->irrd->routeObjects($keys);
        } catch (IrrdException $exception) {
            Log::warning('IRR sorgusu başarısız.', ['error' => $exception->getMessage()]);

            return collect($keys)
                ->mapWithKeys(fn (string $key): array => [
                    $key => $this->make(PrefixAuthorizationState::Unknown, 'IRR sunucusu yanıt vermedi.'),
                ])
                ->all();
        }

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [
                $key => $this->verdict($objects[$key] ?? null, $asn),
            ])
            ->all();
    }

    private function verdict(?string $rpsl, string $asn): AuthorityVerdict
    {
        $origins = $this->origins((string) $rpsl);

        if ($origins === []) {
            return $this->make(
                PrefixAuthorizationState::Missing,
                'Bu prefix için IRR route nesnesi yok.',
            );
        }

        if (in_array(Asn::normalize($asn), $origins, true)) {
            return $this->make(PrefixAuthorizationState::Approved, "IRR route nesnesi bu ASN'ye ait.");
        }

        return $this->make(
            PrefixAuthorizationState::Rejected,
            'IRR route nesnesi başka bir AS numarasına ait ('
            .collect($origins)->take(3)->implode(', ').').',
        );
    }

    /**
     * RPSL akışındaki nesnelerin orijinleri — RPKI türevleri elenmiş hâlde.
     *
     * @return array<int, string>
     */
    private function origins(string $rpsl): array
    {
        $ignored = array_map('strtoupper', (array) config('announcements.authorization.irr.ignored_sources'));
        $origins = [];

        foreach (preg_split('/\n\s*\n/', trim($rpsl)) ?: [] as $object) {
            if ($object === '') {
                continue;
            }

            $source = strtoupper($this->field($object, 'source'));

            if ($source === '' || in_array($source, $ignored, true)) {
                continue;
            }

            $origin = Asn::normalize($this->field($object, 'origin'));

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * RPSL alanının ilk sözcüğü. "source: RPKI  # Trust Anchor: ripe"
     * satırındaki yorum atılır.
     */
    private function field(string $object, string $name): string
    {
        if (preg_match('/^'.$name.':\s*(\S+)/mi', $object, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    private function make(PrefixAuthorizationState $state, string $detail): AuthorityVerdict
    {
        return new AuthorityVerdict($this->key(), $this->label(), $state, $detail);
    }
}
