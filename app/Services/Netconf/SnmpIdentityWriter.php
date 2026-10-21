<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;

/**
 * Cihazın SNMP kimliğini (sysName, description, engine-id) düzeltir.
 *
 * Bu değişiklik yönlendirme düzlemine dokunmaz: snmpd yeni adı okur, PFE
 * yeniden programlanmaz, tek bir paket bile düşmez. Yazma yolunun ilk
 * kullanıcısı bilinçli olarak bu seçildi.
 *
 * Neden gerekli: Zabbix system.name item'ı sysName okur. Bu değer hem yanlış
 * modeli hem de yanlış adı taşıyabilir (ör. bir EX4200'de
 * "sw1.Juniper-EX3200"); o zaman panelde ve Zabbix'te yanlış isim görünür.
 */
class SnmpIdentityWriter
{
    public function __construct(private ConfigurationWriter $writer) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function rename(NetworkSwitch $switch, string $name, ?string $location = null, ?string $contact = null): array
    {
        return $this->writer->apply($switch, $this->configuration($name, $location, $contact), "SNMP kimligi: {$name}");
    }

    /**
     * Konum ve iletişim boş bırakılırsa cihazdan SİLİNİR: merge yalnızca
     * eklediği için, temizlenmek istenen alan aksi hâlde olduğu yerde
     * kalırdı ve kullanıcı sildiğini sanırdı.
     */
    private function configuration(string $name, ?string $location, ?string $contact): string
    {
        $escaped = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES);

        $body = "<name>{$escaped}</name>"
            ."<description>{$escaped}</description>"
            ."<engine-id><local>{$escaped}</local></engine-id>";

        foreach (['location' => $location, 'contact' => $contact] as $element => $value) {
            $body .= filled($value)
                ? "<{$element}>".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES)."</{$element}>"
                : "<{$element} delete=\"delete\"/>";
        }

        return "<configuration><snmp>{$body}</snmp></configuration>";
    }
}
