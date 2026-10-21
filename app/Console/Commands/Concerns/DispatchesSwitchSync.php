<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\NetworkSwitch;
use Closure;
use Illuminate\Support\Collection;

/**
 * Eşitleme komutlarının ortak gövdesi: hangi switchler, hangi iş, kaç tane.
 */
trait DispatchesSwitchSync
{
    /**
     * @param  class-string|Closure(int, int): void  $job  İş sınıfı ya da
     *                                                     birden fazla iş
     *                                                     kuyruğa alan kapanış
     */
    protected function dispatchForSwitches(string|Closure $job, ?int $delayStep = null): int
    {
        $switches = $this->targetSwitches();

        if ($switches->isEmpty()) {
            $this->warn('Eşitlenecek switch bulunamadı.');

            return self::SUCCESS;
        }

        $switches->values()->each(function (NetworkSwitch $switch, int $index) use ($job, $delayStep): void {
            // Yavaş kaynaklarda (SNMP) switchler sırayla yüklenir.
            $delay = $delayStep === null ? 0 : $index * $delayStep;

            if ($job instanceof Closure) {
                $job($switch->id, $delay);

                return;
            }

            $pending = $job::dispatch($switch->id);

            if ($delayStep !== null) {
                $pending->delay(now()->addSeconds($delay));
            }
        });

        $this->info("{$switches->count()} switch için eşitleme kuyruğa alındı.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, NetworkSwitch>
     */
    private function targetSwitches(): Collection
    {
        return NetworkSwitch::query()
            ->when(
                $this->argument('switch'),
                fn ($query, string $hostname) => $query
                    ->where('hostname', $hostname)
                    ->orWhere('management_ip', $hostname),
            )
            ->get();
    }
}
