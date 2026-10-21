<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use Carbon\CarbonImmutable;

/**
 * Cihazın commit geçmişi.
 *
 * SNMP karşılığı (jnxCmCfgChgEventTable) yalnızca sıra, kullanıcı ve cihazın
 * çalışma süresine göre hesaplanan bir zaman verir; açıklama alanı yoktur ve
 * cihazın saati yanlışsa zaman da yanlış çıkar. NETCONF mutlak zaman damgası
 * (junos:seconds) ve commit açıklamasını doğrudan veriyor.
 *
 * Açıklama alanı, panelden atılan commit'lerde işlemi yapan kullanıcıyı
 * taşır (bkz. CommitLog); cihaz kendi tarafında yalnızca paylaşılan "admin"
 * hesabını görür.
 */
class CommitHistoryReader
{
    /**
     * @return list<array{sequence_number: int, committed_by: ?string, committed_at: ?CarbonImmutable, comment: ?string, client: ?string}>
     *
     * @throws NetconfException
     */
    public function commits(NetconfSession $session): array
    {
        $reply = $session->rpc('<get-commit-information/>');
        $commits = [];

        foreach ($reply->xpath('//*[local-name()="commit-history"]') ?: [] as $node) {
            $sequence = trim((string) ($node->xpath('./*[local-name()="sequence-number"]')[0] ?? ''));

            if (! ctype_digit($sequence)) {
                continue;
            }

            $commits[] = [
                'sequence_number' => (int) $sequence,
                'committed_by' => $this->text($node, 'user'),
                'committed_at' => $this->committedAt($node),
                'comment' => $this->comment($node),
                'client' => $this->text($node, 'client'),
            ];
        }

        return $commits;
    }

    /**
     * Kullanıcının commit'e iliştirdiği mesaj <log> alanındadır; <comment>
     * Junos'un kendi notudur ("commit confirmed, rollback in 2mins"). Panelde
     * gösterilmesi gereken, işlemi kimin neden yaptığıdır.
     */
    private function comment(\SimpleXMLElement $node): ?string
    {
        return $this->text($node, 'log') ?? $this->text($node, 'comment');
    }

    private function committedAt(\SimpleXMLElement $node): ?CarbonImmutable
    {
        $dateTime = $node->xpath('./*[local-name()="date-time"]')[0] ?? null;

        if ($dateTime === null) {
            return null;
        }

        // junos:seconds mutlak epoch'tur; metin gösterimi cihazın saat
        // diliminde olduğu için ondan değil bundan okunur.
        $seconds = (string) ($dateTime->attributes('junos', true)['seconds'] ?? '');

        return ctype_digit($seconds) ? CarbonImmutable::createFromTimestampUTC((int) $seconds) : null;
    }

    private function text(\SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath("./*[local-name()=\"{$element}\"]")[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
