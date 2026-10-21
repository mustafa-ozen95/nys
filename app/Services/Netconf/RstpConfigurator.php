<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchRstpInterface;

/**
 * Spanning tree (RSTP) yapılandırmasını yazar.
 *
 * Panelin en geniş etkili L2 yazması budur: köprü önceliğini değiştirmek kök
 * köprüyü yeniden seçtirir ve topoloji bir kaç saniye yeniden yakınsar;
 * "edge" yanlış porta verilirse o portta döngü ANINDA oluşur.
 *
 * Bu yüzden her yazma geri alınabilir commit'ten geçer ve ekranda switch adı
 * yazdırılır. Yine de: bu ekran uzman ekranıdır.
 *
 * Üç durum ayrıdır ve karıştırılmamalıdır:
 *   - rstp düğümü hiç yok  → cihazın varsayılanı
 *   - rstp var, disable yok → açık
 *   - rstp var, disable var → açıkça kapalı
 */
class RstpConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private DeviceSettingsSynchronizer $settings,
    ) {}

    /**
     * Köprü ayarları.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyBridge(NetworkSwitch $switch, bool $enabled, ?string $bridgePriority, bool $bpduBlockOnEdge): array
    {
        $body = $enabled ? '<disable delete="delete"/>' : '<disable/>';

        $body .= filled($bridgePriority)
            ? '<bridge-priority>'.$this->escape((string) $bridgePriority).'</bridge-priority>'
            : '<bridge-priority delete="delete"/>';

        $body .= $bpduBlockOnEdge ? '<bpdu-block-on-edge/>' : '<bpdu-block-on-edge delete="delete"/>';

        return $this->apply($switch, $this->wrap($body), 'rstp kopru ayarlari');
    }

    /**
     * Bir arayüzün RSTP ayarları.
     *
     * Alanlar önce SİLİNİR sonra yazılır: merge yalnızca eklediği için,
     * kaldırılan bir "edge" ya da maliyet cihazda olduğu yerde kalırdı.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyInterface(
        NetworkSwitch $switch,
        string $interfaceName,
        bool $edge,
        bool $noRootPort,
        ?int $cost,
        ?int $priority,
    ): array {
        $name = $this->escape($interfaceName);

        $reset = "<interface><name>{$name}</name>"
            .'<edge delete="delete"/><no-root-port delete="delete"/>'
            .'<cost delete="delete"/><priority delete="delete"/>'
            .'</interface>';

        $body = "<interface><name>{$name}</name>";
        $body .= $edge ? '<edge/>' : '';
        $body .= $noRootPort ? '<no-root-port/>' : '';
        $body .= $cost !== null ? "<cost>{$cost}</cost>" : '';
        $body .= $priority !== null ? "<priority>{$priority}</priority>" : '';
        $body .= '</interface>';

        return $this->apply(
            $switch,
            [$this->wrap($reset), $this->wrap($body)],
            "rstp arayuzu: {$interfaceName}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteInterface(NetworkSwitch $switch, SwitchRstpInterface $interface): array
    {
        return $this->apply(
            $switch,
            $this->wrap('<interface delete="delete"><name>'.$this->escape($interface->interface_name).'</name></interface>'),
            "rstp arayuzu silindi: {$interface->interface_name}",
        );
    }

    private function wrap(string $body): string
    {
        return "<configuration><protocols><rstp>{$body}</rstp></protocols></configuration>";
    }

    /**
     * @param  string|list<string>  $configuration
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(NetworkSwitch $switch, string|array $configuration, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $configuration,
            $summary,
            fn (NetconfSession $session) => $this->settings->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
