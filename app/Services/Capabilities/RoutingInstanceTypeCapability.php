<?php

declare(strict_types=1);

namespace App\Services\Capabilities;

use App\Models\NetworkSwitch;
use App\Services\Junos\JunosDialect;

/**
 * Yönlendirme tablosunun kabul ettiği türler.
 *
 * EX2300 "forwarding" ve "virtual-switch" için "invalid value" diyor;
 * "virtual-router" ve "no-forwarding" kabul ediliyor (test4'te ölçüldü).
 * EX3200 dördünü de kabul ediyor (test2).
 *
 * ÖNEMLİ: filtre tabanlı yönlendirme (FBF) klasik olarak "forwarding"
 * tablosuyla kurulur. O tür bulunmayan bir cihazda FBF "virtual-router" ile
 * kurulmak zorunda — ekran bunu VARSAYMAZ, buradan öğrenir.
 */
final class RoutingInstanceTypeCapability implements DeviceCapability
{
    /**
     * Panelin kurabildiği türler. "vrf" listede YOKTUR: cihaz onu
     * route-distinguisher olmadan reddediyor ve panel henüz o alanı
     * yönetmiyor — sonda, yönetilmeyen bir alanı ölçmez.
     *
     * @var list<string>
     */
    private const TYPES = ['forwarding', 'virtual-router', 'no-forwarding', 'virtual-switch'];

    /**
     * Sonda geçici bir ad kullanır ve aday atıldığı için cihazda kalmaz.
     */
    private const NAME = 'sonda-tablo';

    public function key(): string
    {
        return 'routing.instance_types';
    }

    public function label(): string
    {
        return 'Yönlendirme tablosu türü';
    }

    public function candidates(JunosDialect $dialect, NetworkSwitch $switch): array
    {
        $adaylar = [];

        foreach (self::TYPES as $tur) {
            $adaylar[$tur] = '<routing-instances><instance><name>'.self::NAME.'</name>'
                .'<instance-type>'.$tur.'</instance-type></instance></routing-instances>';
        }

        return $adaylar;
    }
}
