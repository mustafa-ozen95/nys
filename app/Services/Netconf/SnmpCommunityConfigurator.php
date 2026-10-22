<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchSnmpCommunity;

/**
 * Cihazdaki SNMP community'lerini yazar.
 *
 * Community adı bir SIRDIR: onu bilen cihazın bütün SNMP verisini çeker.
 * Bu yüzden commit açıklamasına konmaz ve cihaz farkı kayda geçerken
 * maskelenir (bkz. ConfigurationRedactor).
 *
 * Ad cihazda community'nin KİMLİĞİDİR; değiştirmek silip yeniden yaratmak
 * demektir. Panel bu yüzden adı düzenletmez.
 */
class SnmpCommunityConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private DeviceSettingsSynchronizer $settings,
    ) {}

    /**
     * @param  list<string>  $clients
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function create(NetworkSwitch $switch, string $name, string $authorization, array $clients): array
    {
        return $this->apply(
            $switch,
            $this->wrap($this->element($name, $authorization, $clients)),
            'snmp community eklendi',
        );
    }

    /**
     * @param  list<string>  $clients
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function update(NetworkSwitch $switch, SwitchSnmpCommunity $community, string $authorization, array $clients): array
    {
        /*
         * İstemci listesi değiştiğinde eskisi kalmamalı; düğüm KOMPLE
         * değiştirilir.
         *
         * "<clients delete=\"delete\"/>" ile temizlemek ÇALIŞMIYOR: cihaz
         * (12.3R12.4) adsız silmeye "syntax error" veriyor ve bütün yükleme
         * düşüyor — archive-sites ile birebir aynı tuzak. jn32-test üzerinde
         * commit check ile doğrulandı; replace geçen tek temiz biçim.
         */
        return $this->apply(
            $switch,
            $this->wrap($this->element((string) $community->name, $authorization, $clients, replace: true)),
            'snmp community guncellendi',
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchSnmpCommunity $community): array
    {
        return $this->apply(
            $switch,
            $this->wrap('<community delete="delete"><name>'.$this->escape((string) $community->name).'</name></community>'),
            'snmp community silindi',
        );
    }

    /**
     * @param  list<string>  $clients
     */
    private function element(string $name, string $authorization, array $clients, bool $replace = false): string
    {
        $body = '<name>'.$this->escape($name).'</name>'
            .'<authorization>'.$this->escape($authorization).'</authorization>';

        foreach ($clients as $client) {
            if (filled($client)) {
                $body .= '<clients><name>'.$this->escape((string) $client).'</name></clients>';
            }
        }

        $attribute = $replace ? ' replace="replace"' : '';

        return "<community{$attribute}>{$body}</community>";
    }

    private function wrap(string $body): string
    {
        return "<configuration><snmp>{$body}</snmp></configuration>";
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
