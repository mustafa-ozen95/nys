<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NetworkSwitch;
use App\Services\Snmp\CommitReader;
use App\Services\Snmp\SnmpException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Cihazdaki commit geçmişini okur.
 *
 * Salt okuma. Kayıtlar cihazda kalıcı değildir (tablo dönüşümlüdür), bu
 * yüzden silme yapılmaz: bir kez görülen commit panelde kalır.
 */
class SyncSwitchCommitsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Kilidin en fazla ne kadar tutulacağı.
     *
     * Bu OLMADAN kilit iş bitene kadar durur; iş bir şekilde kaybolursa
     * (yanlış kuyruk, düşen işçi) kilit sonsuza kadar kalır ve sonraki her
     * dispatch SESSİZCE atılır. Eşitleme yarım saatte bir koştuğu için
     * kilit ondan önce düşmeli.
     */
    public int $uniqueFor = 1500;

    public function __construct(public int $switchId)
    {
        $this->onQueue(config('snmp.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->switchId;
    }

    public function handle(CommitReader $reader): void
    {
        $switch = NetworkSwitch::query()->find($this->switchId);

        if ($switch === null || $switch->snmpCredential === null) {
            return;
        }

        /*
         * NETCONF varsa bu iş çalışmaz: get-commit-information mutlak zaman
         * damgası ve commit açıklamasını veriyor, SNMP ise zamanı cihazın
         * çalışma süresinden hesaplıyor ve açıklama alanı taşımıyor. İkisi
         * birden yazsaydı aynı satırı farklı zamanlarla ezerlerdi. Bu yol
         * yalnızca NETCONF kimliği tanımlı olmayan cihazlar için kalır.
         */
        if ($switch->netconfCredential !== null) {
            return;
        }

        try {
            $commits = $reader->commits($switch);
        } catch (SnmpException $exception) {
            Log::warning("Commit geçmişi okunamadı: {$switch->hostname}", ['hata' => $exception->getMessage()]);

            return;
        }

        foreach ($commits as $commit) {
            $switch->commits()->updateOrCreate(
                ['sequence_number' => $commit['sequence']],
                [
                    'committed_by' => $commit['committed_by'],
                    'committed_at' => $commit['committed_at'],
                ],
            );
        }
    }
}
