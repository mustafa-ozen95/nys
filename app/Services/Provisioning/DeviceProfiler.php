<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Services\Netconf\DeviceFactsReader;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;

/**
 * Şablonun ihtiyaç duyduğu cihaz bilgilerini okur.
 *
 * Yalnızca okuma yapar ve config üretilmeden ÖNCE çalışır: yazılacak metin
 * cihazın gerçeğine göre kurulur, varsayıma göre değil.
 */
class DeviceProfiler
{
    public function __construct(private DeviceFactsReader $facts) {}

    /**
     * @throws NetconfException
     */
    public function profile(NetconfSession $session): DeviceProfile
    {
        $facts = $this->facts->facts($session);

        return new DeviceProfile(
            model: $facts['model'],
            version: $facts['junos_version'],
            managementInterface: $this->managementInterface($session),
            hostname: $facts['hostname'],
        );
    }

    /**
     * Yönetim arayüzünün adı.
     *
     * Sanal şasi kurulumunda "vme", tek cihazda "me0". Cihazda hangisi
     * varsa o kullanılır; ikisi de yoksa güvenli varsayılan me0'dır çünkü
     * sanal şasi olmayan kurulum daha yaygındır ve yanlış tahmin hâlinde
     * commit doğrulaması zaten reddeder.
     *
     * @throws NetconfException
     */
    private function managementInterface(NetconfSession $session): string
    {
        $reply = $session->rpc('<get-interface-information/>');

        foreach ($reply->xpath('//*[local-name()="physical-interface"]/*[local-name()="name"]') ?: [] as $name) {
            if (trim((string) $name) === 'vme') {
                return 'vme';
            }
        }

        return 'me0';
    }
}
