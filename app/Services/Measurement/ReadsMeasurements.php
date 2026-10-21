<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use App\Enums\MeasurementSource;
use App\Models\NetworkSwitch;
use App\Support\Measurement\DeviceMeasurement;
use App\Support\Measurement\PortMeasurement;
use Illuminate\Support\Collection;

/**
 * Bir ölçüm kaynağı.
 *
 * Üç uygulaması vardır: izleme sistemi (Zabbix) ve cihazın kendisi (NETCONF,
 * SNMP). Panel hangisiyle konuştuğunu bilmez; kaynak seçimi ve yedeğe düşme
 * MeasurementResolver'ın işidir.
 */
interface ReadsMeasurements
{
    public function source(): MeasurementSource;

    /**
     * Bu kaynak bu switch için kullanılabilir mi: ayarlarda açık, gerekli
     * kimlik bilgisi tanımlı.
     */
    public function supports(NetworkSwitch $switch): bool;

    /**
     * Birden çok switch okunacağını önceden haber verir.
     *
     * Tek bir çağrıda toplu okuma yapabilen kaynaklar (izleme sistemi)
     * burada hazırlanır; cihaza tek tek bağlanan kaynaklar için karşılığı
     * yoktur ve boş geçilir. Hata yükseltmez: hazırlık başarısız olursa
     * okuma yine tek tek yapılır.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     */
    public function prefetch(Collection $switches): void;

    /**
     * @throws MeasurementUnavailable
     */
    public function device(NetworkSwitch $switch): DeviceMeasurement;

    /**
     * @return array<int, PortMeasurement> ifIndex => ölçüm
     *
     * @throws MeasurementUnavailable
     */
    public function ports(NetworkSwitch $switch): array;
}
