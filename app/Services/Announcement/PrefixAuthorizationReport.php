<?php

declare(strict_types=1);

namespace App\Services\Announcement;

use App\Enums\PrefixAuthorizationPolicy;
use Illuminate\Support\Collection;

/**
 * Bir anons talebindeki bütün prefixlerin yetki durumu.
 *
 * Ekranın ihtiyacı olan her sayı burada hesaplanır; Blade yalnızca basar.
 *
 * "Denetim yapılmadı"nın İKİ hâli vardır ve sonuçları terstir:
 *
 * - `disabled()` — denetim kapatılmış. Gönderime izin verilir; kapatmak
 *   operatörün bilinçli kararıdır.
 * - `refused()` — denetim yapılamadı. Gönderime izin VERİLMEZ; denetlenmemiş
 *   listeyi geçirmek, kontrolü hiç koymamakla aynı şey olurdu.
 */
final readonly class PrefixAuthorizationReport
{
    /**
     * @param  Collection<int, PrefixAudit>  $audits
     */
    private function __construct(
        public Collection $audits,
        public bool $enforced,
        public bool $allowed,
        public PrefixAuthorizationPolicy $policy = PrefixAuthorizationPolicy::Any,
        public ?string $note = null,
    ) {}

    /**
     * @param  Collection<int, PrefixAudit>  $audits
     */
    public static function of(Collection $audits, PrefixAuthorizationPolicy $policy): self
    {
        return new self($audits, enforced: true, allowed: true, policy: $policy);
    }

    public static function disabled(string $note): self
    {
        return new self(collect(), enforced: false, allowed: true, note: $note);
    }

    public static function refused(string $note): self
    {
        return new self(collect(), enforced: false, allowed: false, note: $note);
    }

    /**
     * @return Collection<int, PrefixAudit>
     */
    public function blocked(): Collection
    {
        return $this->audits->reject(fn (PrefixAudit $audit): bool => $audit->approved())->values();
    }

    public function passes(): bool
    {
        if (! $this->enforced) {
            return $this->allowed;
        }

        return $this->blocked()->isEmpty();
    }

    public function approvedCount(): int
    {
        return $this->audits->count() - $this->blocked()->count();
    }

    /**
     * Gönderilebilir ama bir kaynağı eksik olan bloklar.
     *
     * Gönderimi durdurmaz; karşı taraf filtresini çoğunlukla IRR'den ürettiği
     * için eksik kayıt talebin cevapsız kalmasının sebebidir ve söylenmelidir.
     *
     * @return Collection<int, PrefixAudit>
     */
    public function incomplete(): Collection
    {
        return $this->audits
            ->filter(fn (PrefixAudit $audit): bool => $audit->approved() && $audit->gaps() !== [])
            ->values();
    }

    /**
     * Gönderim engellendiğinde kullanıcıya gösterilecek gerekçe.
     */
    public function refusal(): string
    {
        if (! $this->enforced) {
            return (string) $this->note;
        }

        $blocked = $this->blocked();

        $reasons = $blocked
            ->map(fn (PrefixAudit $audit): string => "{$audit->prefix} — {$audit->reason()}")
            ->implode(' | ');

        return $blocked->count().' prefix için hiçbir kaynak anons yetkisi göstermedi, gönderim durduruldu: '.$reasons;
    }
}
