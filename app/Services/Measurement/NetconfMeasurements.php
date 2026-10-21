<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use App\Enums\MeasurementSource;
use App\Models\NetworkSwitch;
use App\Services\Netconf\DeviceMetricsReader;
use App\Services\Netconf\InterfaceMetricsReader;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use App\Support\Measurement\DeviceMeasurement;
use Illuminate\Support\Collection;

/**
 * Cihazın kendisinden ölçüm (NETCONF).
 *
 * İzleme sistemine bağımlı olmayan yol budur. Yalnızca okuma yapılır.
 *
 * Oturum kurmak pahalıdır (~2 sn, cihazda bir mgd fork'u), bu yüzden cihaz
 * ve port ölçümleri ayrı ayrı istendiğinde bile her biri tek oturumda biter;
 * çağıran taraf sonucu önbelleğe alır.
 *
 * Oturum açılamaması cihazın erişilemez olduğunu söylemez — kimlik yanlış da
 * olabilir, ağ yolunda bir sorun da. Bu yüzden "çevrimdışı" değil "kaynak
 * yanıt vermedi" olarak bildirilir ve çözümleyici sıradaki kaynağa geçer.
 */
class NetconfMeasurements implements ReadsMeasurements
{
    public function __construct(
        private NetconfClient $client,
        private DeviceMetricsReader $metrics,
        private InterfaceMetricsReader $interfaces,
    ) {}

    public function source(): MeasurementSource
    {
        return MeasurementSource::Netconf;
    }

    public function supports(NetworkSwitch $switch): bool
    {
        return $switch->netconfCredential !== null || $switch->sshCredential !== null;
    }

    /**
     * Cihaza tek tek bağlanılır; toplu okuma diye bir şey yoktur.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     */
    public function prefetch(Collection $switches): void {}

    public function device(NetworkSwitch $switch): DeviceMeasurement
    {
        return $this->read($switch, fn (NetconfSession $session): DeviceMeasurement => $this->metrics->metrics($session));
    }

    public function ports(NetworkSwitch $switch): array
    {
        return $this->read($switch, fn (NetconfSession $session): array => $this->interfaces->metrics($session));
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(NetconfSession): TReturn  $callback
     * @return TReturn
     */
    private function read(NetworkSwitch $switch, \Closure $callback): mixed
    {
        try {
            return $this->client->run($switch, $callback);
        } catch (NetconfException $exception) {
            throw MeasurementUnavailable::from($exception);
        }
    }
}
