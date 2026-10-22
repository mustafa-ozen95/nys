<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Models\SwitchAnalyzer;
use App\Support\Netconf\ResidualXml;

/**
 * Port aynalamayı (analyzer) cihaza yazar.
 *
 * ELS ÖNCESİ YOL: ethernet-switching-options analyzer <ad>. Şemanın geçerli
 * olduğu test1'de commit check ile ölçüldü; reddedilme SEMANTİKTİ:
 *
 *   "Analyzer output interface ge-0/0/21.0 should be configured with family
 *    ethernet-switching"
 *
 * yani çıkış portu anahtarlama ailesinde olmalı. Panel bunu FORMDA ÖNCEDEN
 * söyler (AnalyzerRequest), cihazın reddine bırakmaz.
 *
 * Analyzer TEK PARÇA yazılır (replace="replace"): kaynak listesinden bir
 * satır çıkarmak "merge" ile mümkün değildir, merge yalnızca ekler. Bütün
 * düğüm yeniden kurulduğu için panelin tanımadığı satırlar da HER SEVİYEDE
 * geri basılır (residual_xml); yoksa kullanıcının dokunmadığı satır sessizce
 * kaybolurdu.
 *
 * Aynalama mantıksal arayüz ister (ge-0/0/21.0); envanterdeki port adı
 * fiziksel olduğu için birim eklenir — ölçülen hata mesajındaki ad da
 * birimlidir.
 *
 * ÖLÇÜLMEYEN TEK NOKTA: çıkış hedefi VLAN olduğunda kullanılan yazım
 * (<vlan><name>..</name></vlan>). Kaynak listeleriyle aynı Junos kimlik
 * elemanı seçildi (cihazda doğrulanmış olan bu biçimdir). Yanlışsa cihaz
 * commit check'te reddeder, kalıcı bir değişiklik olmaz ve hata cihaz işlem
 * kaydına düşer.
 */
class AnalyzerConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private AnalyzerSynchronizer $analyzers,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, string $name, array $settings): array
    {
        $profile = $switch->schema_profile ?? JunosSchemaProfile::PreEls;

        return $this->send(
            $switch,
            $this->wrap(
                '<analyzer replace="replace">'.$this->body($name, $settings, $profile).'</analyzer>',
                $profile,
            ),
            "port aynalama: {$name}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchAnalyzer $analyzer): array
    {
        return $this->send(
            $switch,
            $this->wrap(
                '<analyzer delete="delete"><name>'.$this->escape((string) $analyzer->name).'</name></analyzer>',
                $switch->schema_profile ?? JunosSchemaProfile::PreEls
            ),
            "port aynalama silindi: {$analyzer->name}",
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function body(string $name, array $settings, JunosSchemaProfile $profile): string
    {
        $body = '<name>'.$this->escape($name).'</name>';

        if (filled($settings['ratio'] ?? null)) {
            $body .= '<ratio>'.(int) $settings['ratio'].'</ratio>';
        }

        if (filled($settings['loss_priority'] ?? null)) {
            $body .= '<loss-priority>'.$this->escape((string) $settings['loss_priority']).'</loss-priority>';
        }

        return $body
            .$this->input($settings)
            .$this->output($settings, $profile)
            .ResidualXml::emit($this->residual($settings, SwitchAnalyzer::RESIDUAL_ROOT));
    }

    /**
     * Aynalanacak kaynaklar.
     *
     * @param  array<string, mixed>  $settings
     */
    private function input(array $settings): string
    {
        $ingress = $this->direction($settings, 'ingress', SwitchAnalyzer::RESIDUAL_INGRESS);
        $egress = $this->direction($settings, 'egress', SwitchAnalyzer::RESIDUAL_EGRESS);
        $residual = ResidualXml::emit($this->residual($settings, SwitchAnalyzer::RESIDUAL_INPUT));

        $body = ($ingress === '' ? '' : "<ingress>{$ingress}</ingress>")
            .($egress === '' ? '' : "<egress>{$egress}</egress>")
            .$residual;

        return $body === '' ? '' : "<input>{$body}</input>";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function direction(array $settings, string $direction, string $residualPath): string
    {
        $body = $this->references($settings["{$direction}_interfaces"] ?? null, 'interface')
            .$this->references($settings["{$direction}_vlans"] ?? null, 'vlan')
            .ResidualXml::emit($this->residual($settings, $residualPath));

        return $body;
    }

    /**
     * Aynalanan trafiğin gittiği yer: ya ARAYÜZ ya VLAN.
     *
     * İkisi birlikte verilmez; kural formda ve doğrulamada da durur.
     *
     * @param  array<string, mixed>  $settings
     */
    private function output(array $settings, JunosSchemaProfile $profile): string
    {
        $body = '';

        if (filled($settings['output_interface'] ?? null)) {
            /*
             * ELS çıkışta arayüz adını DÜZ METİN ister; sarmalanmış biçim
             * "syntax error" veriyor (test4'te ölçüldü). Giriş tarafı ikisinde
             * de sarmalanmıştır — yalnızca çıkış farklı.
             */
            $name = $this->escape((string) $settings['output_interface']);

            $body .= $profile->usesPlainAnalyzerOutput()
                ? "<interface>{$name}</interface>"
                : "<interface><name>{$name}</name></interface>";
        } elseif (filled($settings['output_vlan'] ?? null)) {
            $body .= '<vlan><name>'.$this->escape((string) $settings['output_vlan']).'</name>'
                .ResidualXml::emit($this->residual($settings, SwitchAnalyzer::RESIDUAL_OUTPUT_VLAN))
                .'</vlan>';
        }

        $body .= ResidualXml::emit($this->residual($settings, SwitchAnalyzer::RESIDUAL_OUTPUT));

        return $body === '' ? '' : "<output>{$body}</output>";
    }

    /**
     * Junos'ta kimlik elemanı <name>'dir; kaynak listeleri de çıkış hedefi de
     * aynı biçimi kullanır.
     *
     * @param  ?array<int, string>  $values
     */
    private function references(?array $values, string $element): string
    {
        $body = '';

        foreach ($values ?? [] as $value) {
            if (filled($value)) {
                $body .= "<{$element}><name>".$this->escape((string) $value)."</name></{$element}>";
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>|null
     */
    private function residual(array $settings, string $path): ?array
    {
        $residual = $settings['residual_xml'] ?? null;

        return is_array($residual) ? ($residual[$path] ?? null) : null;
    }

    private function wrap(string $body, JunosSchemaProfile $profile): string
    {
        $root = $profile->analyzerRootElement();

        return "<configuration><{$root}>{$body}</{$root}></configuration>";
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function send(NetworkSwitch $switch, string $document, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $document,
            $summary,
            fn (NetconfSession $session) => $this->analyzers->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
