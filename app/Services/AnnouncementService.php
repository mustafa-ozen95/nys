<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MailLocale;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Rules\Cidr;
use Illuminate\Support\Collection;

/**
 * Prefix anons talebinin metnini üretir.
 *
 * Metin sunucuda üretilir: önizlemede gösterilen ile gönderilen aynı olur,
 * tarayıcıda metin kurgulanmaz.
 */
class AnnouncementService
{
    /**
     * Gönderilebilecek alıcılar. Kim, hangi dilde: panelden yönetilir.
     *
     * @return Collection<int, AnnouncementRecipient>
     */
    public function recipients(?User $user = null): Collection
    {
        return AnnouncementRecipient::query()
            ->active()
            // Kayıt düzeyindeki "görme" yasağı olan alıcı listede de görünmez.
            ->when($user !== null, fn ($query) => $query->visibleTo($user))
            ->orderBy('label')
            ->get();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return Collection<int, AnnouncementRecipient>
     */
    public function recipientsFor(array $ids, ?User $user = null): Collection
    {
        return AnnouncementRecipient::query()
            ->active()
            ->when($user !== null, fn ($query) => $query->visibleTo($user))
            ->whereIn('uuid', $ids)
            ->orderBy('label')
            ->get();
    }

    public function subject(): string
    {
        return config('announcements.subject');
    }

    /**
     * Her alıcı için konu ve gövde.
     *
     * @param  array<int, int|string>  $recipientKeys
     * @return array<int, array{label: string, email: string, locale: string, subject: string, body: string}>
     */
    public function compose(string $prefixes, string $asn, ?string $downstream, array $recipientKeys, ?User $user = null): array
    {
        $blocks = Cidr::split($prefixes);

        return $this->recipientsFor($recipientKeys, $user)
            ->map(fn (AnnouncementRecipient $recipient): array => [
                'label' => $recipient->label,
                'email' => $recipient->email,
                'locale' => $recipient->locale->value,
                'subject' => $this->subject(),
                'body' => $recipient->locale === MailLocale::En
                    ? $this->englishBody($blocks, $asn, $downstream)
                    : $this->turkishBody($blocks, $asn, $downstream),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $blocks
     */
    private function turkishBody(array $blocks, string $asn, ?string $downstream): string
    {
        $list = implode("\n", array_map(fn (string $block): string => "  {$block}", $blocks));
        $noun = count($blocks) === 1 ? 'bloğun' : 'blokların';

        $lines = [
            'Merhaba,',
            '',
            "Aşağıdaki {$noun} prefix listenize eklenmesini rica ederiz:",
            '',
            $list,
            '',
            "Anons eden AS numarası: {$asn}",
        ];

        if (filled($downstream)) {
            $lines[] = "Downstream AS numarası: {$downstream}";
        }

        $lines[] = '';
        $lines[] = 'İyi çalışmalar.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $blocks
     */
    private function englishBody(array $blocks, string $asn, ?string $downstream): string
    {
        $list = implode("\n", array_map(fn (string $block): string => "  {$block}", $blocks));
        $noun = count($blocks) === 1 ? 'prefix' : 'prefixes';

        $lines = [
            'Hello,',
            '',
            "Could you please add the following {$noun} to your prefix list:",
            '',
            $list,
            '',
            "Announcing AS: {$asn}",
        ];

        if (filled($downstream)) {
            $lines[] = "Downstream AS: {$downstream}";
        }

        $lines[] = '';
        $lines[] = 'Best regards.';

        return implode("\n", $lines);
    }
}
