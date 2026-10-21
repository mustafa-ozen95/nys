<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchRoutingInstance;
use App\Support\Netconf\ResidualXml;

/**
 * Yönlendirme tablolarını cihaza yazar.
 *
 * Biçimlerin tamamı test1'de commit check ile ölçüldü: sade forwarding
 * örneği, açıklamalı örnek, virtual-router, statik route'lu örnek ve silme —
 * hepsi kabul ediliyor. instance-type olmadan da kabul ediliyor, ama panel
 * türü hep yazar: türsüz bir tablo neye yaradığı belirsiz bir kabuktur.
 *
 * SİLMEK TEHLİKELİDİR: tabloyu silmek içindeki route'ları da siler ve ona
 * atıf yapan firewall terimi varsa cihaz commit'i reddeder. Çağıran taraf
 * önce bağımlılıkları sorar.
 */
class RoutingInstanceConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private RoutingInstanceSynchronizer $synchronizer,
    ) {}

    /**
     * @param  array{instance_type?: ?string, description?: ?string, residual_xml?: ?array<int, string>}  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, string $name, array $settings): array
    {
        $body = '<name>'.$this->escape($name).'</name>';

        if (filled($settings['instance_type'] ?? null)) {
            $body .= '<instance-type>'.$this->escape((string) $settings['instance_type']).'</instance-type>';
        }

        /*
         * Boş açıklama "dokunma" değil "sil" demektir: merge yalnızca ekler,
         * silme açıkça istenmezse cihazdaki eski açıklama olduğu yerde kalır
         * ve kullanıcı temizlediğini sanırdı.
         */
        $body .= filled($settings['description'] ?? null)
            ? '<description>'.$this->escape((string) $settings['description']).'</description>'
            : '<description delete="delete"/>';

        // Panelin tanımadığı satırlar (içindeki route'lar dâhil) geri yazılır.
        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        return $this->send(
            $switch,
            "<instance>{$body}</instance>",
            "yonlendirme tablosu: {$name}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchRoutingInstance $instance): array
    {
        return $this->send(
            $switch,
            '<instance delete="delete"><name>'.$this->escape($instance->name).'</name></instance>',
            "yonlendirme tablosu silindi: {$instance->name}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function send(NetworkSwitch $switch, string $body, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            ["<configuration><routing-instances>{$body}</routing-instances></configuration>"],
            $summary,
            fn (NetconfSession $session) => $this->synchronizer->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
