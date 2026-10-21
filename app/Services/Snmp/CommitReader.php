<?php

declare(strict_types=1);

namespace App\Services\Snmp;

use App\Models\NetworkSwitch;
use Carbon\CarbonImmutable;

/**
 * Cihazdaki yapılandırma değişiklikleri (jnxCmCfgChgEventTable).
 *
 * Zaman, cihazın çalışma süresine göre timetick olarak gelir; mutlak zamana
 * çevirmek için cihazın uptime'ı kullanılır.
 */
class CommitReader
{
    public function __construct(private SnmpClient $snmp) {}

    /**
     * @return array<int, array{sequence: int, committed_by: ?string, committed_at: ?CarbonImmutable}>
     */
    public function commits(NetworkSwitch $switch): array
    {
        $oids = config('snmp.oids');

        $times = $this->snmp->walk($switch, $oids['commit_time']);
        $users = $this->snmp->walk($switch, $oids['commit_user']);

        $açılış = $this->bootedAt($switch);
        $commits = [];

        foreach ($times as $sequence => $timeticks) {
            $commits[] = [
                'sequence' => (int) $sequence,
                'committed_by' => ($users[$sequence] ?? '') ?: null,
                'committed_at' => $this->at($açılış, $timeticks),
            ];
        }

        return $commits;
    }

    /**
     * Cihazın açıldığı an. Uptime alanı eşitlemeden geliyor; yoksa zaman
     * hesaplanamaz ve kayıtlar zamansız saklanır.
     */
    private function bootedAt(NetworkSwitch $switch): ?CarbonImmutable
    {
        return $switch->uptime_seconds === null
            ? null
            : CarbonImmutable::now()->subSeconds($switch->uptime_seconds);
    }

    /**
     * Timetick 1/100 saniyedir.
     *
     * İstemci "-Ot" ile okuduğu için değer ham sayıdır. Biçimlenmiş
     * "416:9:45:59.92" gelirse ayrıştırılamaz ve zaman boş bırakılır.
     */
    private function at(?CarbonImmutable $bootedAt, string $timeticks): ?CarbonImmutable
    {
        $timeticks = trim($timeticks);
        $ticks = ctype_digit($timeticks) ? (int) $timeticks : 0;

        if ($bootedAt === null || $ticks <= 0) {
            return null;
        }

        return $bootedAt->addSeconds(intdiv($ticks, 100));
    }
}
