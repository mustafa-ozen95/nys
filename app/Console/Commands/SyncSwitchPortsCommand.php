<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesSwitchSync;
use App\Jobs\SyncSwitchPortsJob;
use Illuminate\Console\Command;

class SyncSwitchPortsCommand extends Command
{
    use DispatchesSwitchSync;

    protected $signature = 'network:sync-ports {switch? : Yalnızca bu switch adı}';

    protected $description = 'Arayüz envanterini Zabbix üzerinden eşitler';

    public function handle(): int
    {
        return $this->dispatchForSwitches(SyncSwitchPortsJob::class);
    }
}
