<?php

declare(strict_types=1);

namespace App\Services\Announcement\Authorities;

use App\Enums\PrefixAuthorizationState;
use App\Services\Announcement\AuthorityVerdict;
use App\Services\Announcement\PrefixAuthority;
use App\Support\Net\Asn;
use App\Support\Net\Cidr;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * RPKI kaynak doğrulaması (ROA) — RIPEstat üzerinden.
 *
 * Soru şudur: bu prefixi bu AS numarasının anons etmesine izin veren,
 * imzalanmış bir kayıt var mı? Cevap dört durumdan biridir ve üçü de
 * gönderimi durdurur:
 *
 * | RIPEstat status | Anlamı                                   | Karar     |
 * | --------------- | ---------------------------------------- | --------- |
 * | valid           | ROA var, ASN ve uzunluk uyuyor           | Onaylı    |
 * | invalid_asn     | ROA BAŞKA bir ASN'yi yetkilendiriyor     | Onaysız   |
 * | invalid_length  | ROA var ama max-length daha kısa         | Onaysız   |
 * | unknown         | Prefixi kapsayan ROA yok                 | Kayıt yok |
 *
 * invalid_asn örneği: AS64496 adına anons edilen
 * 203.0.113.0/24 için ROA AS64510'ü gösteriyor. Böyle bir bloğun prefix
 * listesine eklenmesini istemek, üstelik başkasının adresini istemektir.
 */
class RoaAuthority implements PrefixAuthority
{
    public function __construct(private Factory $http) {}

    public function key(): string
    {
        return 'roa';
    }

    public function label(): string
    {
        return 'RPKI ROA (RIPE)';
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

        $responses = $this->fetch($prefixes, $asn);

        return collect($prefixes)
            ->mapWithKeys(fn (Cidr $prefix): array => [
                $prefix->toString() => $this->verdict($responses[$prefix->toString()] ?? null),
            ])
            ->all();
    }

    /**
     * Prefixler tek tek sorulur ama AYNI ANDA: RIPEstat toplu uç sunmuyor,
     * sıradan bir talepte on sorgu ard arda on saniye eder.
     *
     * @param  array<int, Cidr>  $prefixes
     * @return array<string, mixed>
     */
    private function fetch(array $prefixes, string $asn): array
    {
        $resource = (string) Asn::normalize($asn);

        return $this->http->pool(fn (Pool $pool): array => collect($prefixes)
            ->map(fn (Cidr $prefix) => $pool->as($prefix->toString())
                ->timeout((int) config('announcements.authorization.ripestat.timeout'))
                ->get(config('announcements.authorization.ripestat.url'), [
                    'resource' => $resource,
                    'prefix' => $prefix->toString(),
                    'sourceapp' => config('announcements.authorization.ripestat.source_app'),
                ]))
            ->all());
    }

    private function verdict(mixed $response): AuthorityVerdict
    {
        if (! $response instanceof Response || $response->failed()) {
            return $this->unknown('RIPEstat yanıt vermedi.');
        }

        try {
            $status = $response->json('data.status');
        } catch (Throwable) {
            return $this->unknown('RIPEstat yanıtı okunamadı.');
        }

        return match ($status) {
            'valid' => $this->make(PrefixAuthorizationState::Approved, 'ROA bu ASN için geçerli.'),
            'invalid_asn' => $this->make(
                PrefixAuthorizationState::Rejected,
                'ROA başka bir AS numarasını yetkilendiriyor'.$this->authorisedOrigins($response).'.',
            ),
            'invalid_length' => $this->make(
                PrefixAuthorizationState::Rejected,
                'ROA var ama izin verilen en uzun prefixten daha spesifik.',
            ),
            'unknown' => $this->make(PrefixAuthorizationState::Missing, 'Bu prefixi kapsayan ROA yok.'),
            default => $this->unknown('RIPEstat beklenmeyen bir durum döndürdü.'),
        };
    }

    /**
     * Reddin gerekçesini somutlaştırır: hangi ASN yetkili?
     */
    private function authorisedOrigins(Response $response): string
    {
        $origins = collect($response->json('data.validating_roas') ?? [])
            ->pluck('origin')
            ->filter()
            ->unique()
            ->map(fn (int|string $origin): string => 'AS'.$origin)
            ->take(3);

        return $origins->isEmpty() ? '' : ' ('.$origins->implode(', ').')';
    }

    private function make(PrefixAuthorizationState $state, string $detail): AuthorityVerdict
    {
        return new AuthorityVerdict($this->key(), $this->label(), $state, $detail);
    }

    private function unknown(string $detail): AuthorityVerdict
    {
        return $this->make(PrefixAuthorizationState::Unknown, $detail);
    }
}
