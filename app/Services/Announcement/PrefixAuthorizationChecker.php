<?php

declare(strict_types=1);

namespace App\Services\Announcement;

use App\Enums\PrefixAuthorizationPolicy;
use App\Enums\PrefixAuthorizationState;
use App\Rules\Cidr as CidrRule;
use App\Support\Net\Asn;
use App\Support\Net\Cidr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Anons edilecek prefixlerin yetkisini kaynaklara sorar.
 *
 * Kural: RPKI (ROA) ve IRR (RADB) kaynaklarının ikisine de sorulur; bir blok
 * en az bir kaynak onaylıyorsa gönderilir, HİÇBİRİ onaylamıyorsa durur.
 * Ölçüt PrefixAuthorizationPolicy'de.
 *
 * Kaynaklar config/announcements.php içinde sıralanır; bu sınıf hiçbirinin
 * adını bilmez. Bir kaynağı çıkarmak listeden bir satır silmektir.
 */
class PrefixAuthorizationChecker
{
    public function __construct(private PrefixAuthorityRegistry $authorities) {}

    public function enabled(): bool
    {
        return (bool) config('announcements.authorization.enabled')
            && $this->authorities->all() !== [];
    }

    public function audit(string $prefixes, string $asn): PrefixAuthorizationReport
    {
        if (! $this->enabled()) {
            return PrefixAuthorizationReport::disabled('Anons yetki denetimi kapalı.');
        }

        $blocks = $this->blocks($prefixes);
        $limit = (int) config('announcements.authorization.max_prefixes');

        if ($blocks->count() > $limit) {
            /*
             * Sınır sorgu maliyeti içindir: her prefix her kaynağa bir sorgu
             * demek. Sessizce ilk N tanesini denetlemek, denetlenmemiş
             * prefixleri "onaylı" göstermek olurdu.
             */
            return PrefixAuthorizationReport::refused(
                "Tek talepte en fazla {$limit} prefix denetlenebilir; liste {$blocks->count()} blok içeriyor."
            );
        }

        return PrefixAuthorizationReport::of($this->verdicts($blocks, $asn), $this->policy());
    }

    /**
     * Metni normalleştirilmiş, tekrarsız bloklara çevirir.
     *
     * Normalleştirme şart: kullanıcı 192.0.2.5/24 yazarsa kaynaklara
     * sorulacak anahtar 192.0.2.0/24'tür, yoksa her kayıt "yok" döner.
     *
     * @return Collection<int, Cidr>
     */
    private function blocks(string $prefixes): Collection
    {
        return collect(CidrRule::split($prefixes))
            ->map(fn (string $line): ?Cidr => Cidr::tryParse($line))
            ->filter()
            ->unique(fn (Cidr $block): string => $block->toString())
            ->values();
    }

    /**
     * @param  Collection<int, Cidr>  $blocks
     * @return Collection<int, PrefixAudit>
     */
    private function verdicts(Collection $blocks, string $asn): Collection
    {
        $found = [];

        foreach ($this->authorities->all() as $authority) {
            foreach ($this->ask($authority, $blocks, $asn) as $prefix => $verdict) {
                $found[$prefix][] = $verdict;
            }
        }

        $policy = $this->policy();

        return $blocks->map(fn (Cidr $block): PrefixAudit => new PrefixAudit(
            $block->toString(),
            $found[$block->toString()] ?? [],
            $policy,
        ));
    }

    /**
     * Önbellekte olmayanları TOPLU sorar; sonucu tek tek önbelleğe yazar.
     *
     * @param  Collection<int, Cidr>  $blocks
     * @return array<string, AuthorityVerdict>
     */
    private function ask(PrefixAuthority $authority, Collection $blocks, string $asn): array
    {
        $cached = [];
        $missing = [];

        foreach ($blocks as $block) {
            $verdict = Cache::get($this->cacheKey($authority, $block, $asn));

            if ($verdict instanceof AuthorityVerdict) {
                $cached[$block->toString()] = $verdict;

                continue;
            }

            $missing[] = $block;
        }

        foreach ($authority->check($missing, $asn) as $prefix => $verdict) {
            $cached[$prefix] = $verdict;
            $this->remember($authority, $prefix, $asn, $verdict);
        }

        return $cached;
    }

    /**
     * Kararlı cevaplar uzun, olumsuzlar kısa saklanır; "sorgulanamadı" hiç
     * saklanmaz — geçici bir kesinti yarım saat boyunca gönderimi kilitlerdi.
     */
    private function remember(PrefixAuthority $authority, string $prefix, string $asn, AuthorityVerdict $verdict): void
    {
        if ($verdict->state === PrefixAuthorizationState::Unknown) {
            return;
        }

        $ttl = (int) config(
            'announcements.authorization.cache.'.($verdict->approves() ? 'approved' : 'rejected')
        );

        Cache::put($this->cacheKey($authority, Cidr::parse($prefix), $asn), $verdict, $ttl);
    }

    private function policy(): PrefixAuthorizationPolicy
    {
        return PrefixAuthorizationPolicy::from(config('announcements.authorization.policy'));
    }

    private function cacheKey(PrefixAuthority $authority, Cidr $block, string $asn): string
    {
        return 'announce-permission:'.$authority->key().':'.Asn::digits($asn).':'.$block->toString();
    }
}
