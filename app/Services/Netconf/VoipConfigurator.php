<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Models\SwitchVoipInterface;
use App\Support\Netconf\ResidualXml;

/**
 * VoIP (ses) VLAN ayarını cihaza yazar.
 *
 * Kapsayıcı ŞEMA AİLESİNE bağlıdır (bkz. JunosSchemaProfile::voipRootElement);
 * altındaki gövde iki ailede de aynıdır:
 *
 *   voip {
 *     interface <ad> {
 *       vlan <vlan-adı>;
 *       forwarding-class <sınıf>;
 *     }
 *   }
 *
 * test1'de (12.3R12.4) commit check ile ölçüldü: ŞEMA GEÇERLİ. Reddedilme
 * semantikti — "Interface ge-0/0/20.0 not found or does not have
 * ethernet-switching family". Yani port anahtarlama ailesinde olmalı ve
 * panel bunu FORMDA ÖNCEDEN söyler (bkz. VoipInterfaceRequest).
 *
 * Arayüz düğümü `replace="replace"` ile yazılır: merge yalnızca EKLER, o
 * yüzden boşaltılan bir forwarding-class cihazda olduğu gibi kalırdı.
 * Panelin okumadığı satırlar ResidualXml ile aynen geri basılır.
 */
class VoipConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private VoipSynchronizer $voip,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, string $interfaceName, array $settings): array
    {
        $body = '<name>'.$this->escape($interfaceName).'</name>';

        if (filled($settings['vlan_name'] ?? null)) {
            $body .= '<vlan>'.$this->escape((string) $settings['vlan_name']).'</vlan>';
        }

        if (filled($settings['forwarding_class'] ?? null)) {
            $body .= '<forwarding-class>'.$this->escape((string) $settings['forwarding_class']).'</forwarding-class>';
        }

        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        return $this->send(
            $switch,
            $this->wrap($switch, "<interface replace=\"replace\">{$body}</interface>"),
            "voip vlan: {$interfaceName}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchVoipInterface $voipInterface): array
    {
        $name = $this->escape((string) $voipInterface->interface_name);

        return $this->send(
            $switch,
            $this->wrap($switch, "<interface delete=\"delete\"><name>{$name}</name></interface>"),
            "voip vlan silindi: {$voipInterface->interface_name}",
        );
    }

    /**
     * Gövdeyi ailesine ait kapsayıcıya sarar.
     */
    private function wrap(NetworkSwitch $switch, string $body): string
    {
        $root = $this->profile($switch)->voipRootElement();

        return "<configuration><{$root}><voip>{$body}</voip></{$root}></configuration>";
    }

    private function profile(NetworkSwitch $switch): JunosSchemaProfile
    {
        return $switch->schema_profile ?? JunosSchemaProfile::PreEls;
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function send(NetworkSwitch $switch, string $document, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            [$document],
            $summary,
            fn (NetconfSession $session) => $this->voip->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
