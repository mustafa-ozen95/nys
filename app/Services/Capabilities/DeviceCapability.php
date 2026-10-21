<?php

declare(strict_types=1);

namespace App\Services\Capabilities;

use App\Models\NetworkSwitch;
use App\Services\Junos\JunosDialect;

/**
 * Cihazın bir alanda hangi DEĞERLERİ kabul ettiği sorusu.
 *
 * Lehçe (JunosDialect) ailenin ŞEKLİNİ bilir: hangi düğüm nerede durur. Bir
 * alanın kabul ettiği değerler ise MODELE göre de değişiyor ve lehçeye
 * yazmak, ölçülmemiş bir kısıtı bütün aileye dayatmak olurdu. Ölçüldü:
 *
 *   speed 1g                  EX3200/12.3 KABUL · EX2300/24.4 RED
 *   instance-type forwarding  EX3200/12.3 KABUL · EX2300/24.4 RED
 *
 * Cevabı cihazın kendisi verir. Yeni bir yetenek eklemek bu arayüzü uygulayan
 * BİR sınıf ve config/capabilities.php'de BİR satırdır; çıkarmak da aynı iki
 * yerden siler (bkz. .ai/rules/modularity.md).
 */
interface DeviceCapability
{
    /**
     * Yetenek anahtarı; kaydın ve ekranın kullandığı ad ("port.speeds").
     */
    public function key(): string;

    /**
     * Kullanıcıya gösterilecek ad.
     */
    public function label(): string;

    /**
     * Denenecek değerler ve her biri için cihaza yüklenecek yapılandırma.
     *
     * Belgeler CİHAZDA İZ BIRAKMAZ: özel bir adaya yalnızca load edilir,
     * commit check bile yapılmaz ve aday atılır (bkz. CapabilityProbe).
     *
     * Anahtar KAPSAMLI olabilir: aynı alanın kabul ettiği değerler cihazın
     * her yerinde aynı olmayabilir. Port hızı böyle — kısıt PIC başınadır
     * (bkz. PortSpeedCapability).
     *
     * @return array<string, string> değer => <configuration> gövdesi
     */
    public function candidates(JunosDialect $dialect, NetworkSwitch $switch): array;
}
