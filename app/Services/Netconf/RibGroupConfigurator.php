<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchRibGroup;
use App\Support\Netconf\ResidualXml;

/**
 * RIB gruplarını cihaza yazar.
 *
 * import-rib YİNELENEN düz elemanlardır ve SIRALARI ANLAMLIDIR (test1'de
 * ölçüldü). Grup "replace" ile yeniden kurulur: merge yalnızca eklediği için,
 * listeden çıkarılan bir tablo aksi hâlde cihazda kalırdı.
 */
class RibGroupConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private RibGroupSynchronizer $synchronizer,
    ) {}

    /**
     * @param  array{import_ribs?: list<string>, residual_xml?: ?array<int, string>}  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, string $name, array $settings): array
    {
        $body = '<name>'.$this->escape($name).'</name>';

        foreach ($settings['import_ribs'] ?? [] as $rib) {
            $body .= '<import-rib>'.$this->escape((string) $rib).'</import-rib>';
        }

        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        return $this->send(
            $switch,
            "<rib-groups replace=\"replace\">{$body}</rib-groups>",
            "rib grubu: {$name}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchRibGroup $group): array
    {
        return $this->send(
            $switch,
            '<rib-groups delete="delete"><name>'.$this->escape($group->name).'</name></rib-groups>',
            "rib grubu silindi: {$group->name}",
        );
    }

    /**
     * Arayüz yollarının kopyalanacağı grup: switch başına TEK işaretçi.
     *
     * Boş verilmek satırı SİLER; merge yalnızca eklediği için silme açıkça
     * istenmezse cihazdaki eski işaretçi olduğu yerde kalırdı.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyInterfaceRoutes(NetworkSwitch $switch, ?string $group): array
    {
        $body = filled($group)
            ? '<rib-group><inet>'.$this->escape((string) $group).'</inet></rib-group>'
            : '<rib-group delete="delete"/>';

        return $this->send(
            $switch,
            "<interface-routes>{$body}</interface-routes>",
            'arayuz yollari rib grubu: '.($group ?? 'kaldirildi'),
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function send(NetworkSwitch $switch, string $body, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            ["<configuration><routing-options>{$body}</routing-options></configuration>"],
            $summary,
            fn (NetconfSession $session) => $this->synchronizer->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
