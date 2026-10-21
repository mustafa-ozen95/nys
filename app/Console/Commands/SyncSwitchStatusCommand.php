<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesSwitchSync;
use App\Jobs\SyncSwitchStatusJob;
use Illuminate\Console\Command;

class SyncSwitchStatusCommand extends Command
{
    use DispatchesSwitchSync;

    protected $signature = 'network:sync-status {switch? : Yalnızca bu switch adı}';

    protected $description = 'Switchlerin anlık durumunu Zabbix üzerinden eşitler';

    public function handle(): int
    {
        return $this->dispatchForSwitches(SyncSwitchStatusJob::class);
    }
}
