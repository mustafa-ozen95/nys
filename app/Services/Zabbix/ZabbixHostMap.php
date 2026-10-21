<?php

declare(strict_types=1);

namespace App\Services\Zabbix;

use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\Cache;

/**
 * Switchin yönetim IP'sini Zabbix host kimliğine eşler.
 *
 * Eşleşme bir kez çözülüp switches.zabbix_host_id kolonuna yazılır; her poll'da
 * host.get çağrılmaz; çağrılsaydı her ölçüm turunda bütün host listesi
 * yeniden çekilirdi.
 */
class ZabbixHostMap
{
    private const CACHE_KEY = 'zabbix:host-map';

    public function __construct(private ZabbixClient $client) {}

    public function hostIdFor(NetworkSwitch $switch): ?string
    {
        if (filled($switch->zabbix_host_id)) {
            return $switch->zabbix_host_id;
        }

        return $this->resolve($switch);
    }

    /**
     * Kayıtlı kimliği yok sayıp eşlemeyi baştan çözer.
     *
     * Zabbix'te host yeniden oluşturulduğunda kayıtlı kimlik bayatlar ve
     * sorgular sessizce boş döner; bu durumda yeniden çözmek gerekir.
     */
    public function resolve(NetworkSwitch $switch): ?string
    {
        $hostId = $this->map()[$switch->management_ip] ?? null;

        $switch->forceFill(['zabbix_host_id' => $hostId])->saveQuietly();

        return $hostId;
    }

    /**
     * @return array<string, string> yönetim IP'si => host kimliği
     */
    public function map(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('zabbix.cache.host_map'),
            fn (): array => $this->fetch(),
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private function fetch(): array
    {
        $hosts = $this->client->call('host.get', [
            'output' => ['hostid', 'host', 'name'],
            'selectInterfaces' => ['ip'],
        ]);

        $map = [];

        foreach ($hosts as $host) {
            foreach ($host['interfaces'] ?? [] as $interface) {
                $ip = $interface['ip'] ?? null;

                if (filled($ip)) {
                    $map[$ip] = (string) $host['hostid'];
                }
            }
        }

        return $map;
    }
}
