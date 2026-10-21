<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Models\SwitchStormControl;
use App\Support\Netconf\ResidualXml;

/**
 * Fırtına denetimini cihaza yazar.
 *
 * AYARIN YERİ ŞEMA AİLESİNE BAĞLIDIR ve ikisi birbirinin yerine geçmez;
 * test1'de commit check ile ölçüldü:
 *
 *   ELS öncesi : ethernet-switching-options storm-control interface <ad>
 *                → KABUL
 *   ELS biçimi : forwarding-options storm-control-profiles <ad>
 *                → "syntax error"
 *
 * ELS öncesi yol test1'de, ELS yolu test4'te doğrulandı (bkz. elsDocument).
 * Biçim yanlış olursa cihaz commit check'te reddeder, kalıcı bir değişiklik
 * olmaz ve hata cihaz işlem kaydına düşer.
 *
 * Cihazın kendi kuralı: arayüz ethernet-switching ailesinde değilse
 * "Interface ... not found" der (ölçüldü). Panel bunu önceden söyler.
 */
class StormControlConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private StormControlSynchronizer $storm,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, string $interfaceName, array $settings): array
    {
        return $this->send(
            $switch,
            [$this->document($switch, $interfaceName, $settings)],
            "firtina denetimi: {$interfaceName}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchStormControl $control): array
    {
        $name = $this->escape($control->interface_name);

        $body = $this->profile($switch)->usesStormControlProfiles()
            ? '<configuration><interfaces><interface><name>'.$name.'</name><unit><name>0</name>'
                .'<family><ethernet-switching><storm-control delete="delete"/></ethernet-switching></family>'
                .'</unit></interface></interfaces></configuration>'
            : '<configuration><ethernet-switching-options><storm-control>'
                .'<interface delete="delete"><name>'.$name.'</name></interface>'
                .'</storm-control></ethernet-switching-options></configuration>';

        return $this->send($switch, [$body], "firtina denetimi silindi: {$control->interface_name}");
    }

    /**
     * Eşik aşılınca port kapatılsın mı?
     *
     * AYARIN YERİ LEHÇEYE BAĞLI. ELS öncesinde switch genelinde tek satırdır;
     * ELS'te böyle bir satır YOKTUR ve ayar her profilin içinde yaşar
     * (test4'te ölçüldü — bkz. JunosDialect::stormShutdownPerProfile).
     *
     * Bu yol da lehçeyi SORAR: bütün fırtına yazmaları document() üzerinden
     * dallanır; burası doğrudan ELS ÖNCESİ biçimi yazsaydı ELS'te "syntax
     * error" alırdı. Aynı satıra iki yol çıkıyorsa ikisi de aynı soruyu
     * sormalı.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyShutdown(NetworkSwitch $switch, bool $shutdown): array
    {
        if ($this->profile($switch)->dialect()->stormShutdownPerProfile()) {
            return $this->send(
                $switch,
                $this->perProfileShutdownDocuments($switch, $shutdown),
                'firtina denetimi: esik asilinca port kapatma',
            );
        }

        $body = $shutdown ? '<action-shutdown/>' : '<action-shutdown delete="delete"/>';

        return $this->send(
            $switch,
            ['<configuration><ethernet-switching-options><storm-control>'.$body
                .'</storm-control></ethernet-switching-options></configuration>'],
            'firtina denetimi: esik asilinca port kapatma',
        );
    }

    /**
     * ELS: ayar profil başına olduğu için PANELİN YÖNETTİĞİ her profile
     * yazılır. Tanımlı profil yoksa yazılacak bir şey de yoktur; ayar
     * bir sonraki profil yazımında kendiliğinden taşınır (bkz. elsDocument).
     *
     * @return list<string>
     */
    private function perProfileShutdownDocuments(NetworkSwitch $switch, bool $shutdown): array
    {
        $satir = $shutdown ? '<action-shutdown/>' : '<action-shutdown delete="delete"/>';

        return $switch->stormControls()
            ->orderBy('interface_name')
            ->pluck('interface_name')
            ->map(fn (string $ad): string => '<configuration><forwarding-options><storm-control-profiles>'
                .'<name>'.$this->escape($this->profileName($ad)).'</name>'.$satir
                .'</storm-control-profiles></forwarding-options></configuration>')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function document(NetworkSwitch $switch, string $interfaceName, array $settings): string
    {
        return $this->profile($switch)->usesStormControlProfiles()
            ? $this->elsDocument($switch, $interfaceName, $settings)
            : $this->preElsDocument($interfaceName, $settings);
    }

    /**
     * Arayüzün ELS profil adı. Tek yerde üretilir: iki yerde üretilseydi
     * kapatma bayrağı başka bir profile yazılırdı.
     */
    private function profileName(string $interfaceName): string
    {
        return 'panel-'.preg_replace('/[^A-Za-z0-9]+/', '-', $interfaceName);
    }

    /**
     * ELS öncesi: ayar doğrudan arayüzün altındadır.
     *
     * @param  array<string, mixed>  $settings
     */
    private function preElsDocument(string $interfaceName, array $settings): string
    {
        $name = $this->escape($interfaceName);
        $body = "<name>{$name}</name>";

        if (filled($settings['level'] ?? null)) {
            $body .= '<level>'.(int) $settings['level'].'</level>';
        } elseif (filled($settings['bandwidth'] ?? null)) {
            $body .= '<bandwidth>'.(int) $settings['bandwidth'].'</bandwidth>';
        }

        foreach (self::EXEMPTIONS as $element => $key) {
            $body .= ($settings[$key] ?? false) ? "<{$element}/>" : "<{$element} delete=\"delete\"/>";
        }

        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        return '<configuration><ethernet-switching-options><storm-control>'
            ."<interface replace=\"replace\">{$body}</interface>"
            .'</storm-control></ethernet-switching-options></configuration>';
    }

    /**
     * ELS: önce bir profil tanımlanır, sonra arayüze bağlanır.
     *
     * ÖLÇÜLDÜ (test4 / EX2300 24.4R2-S4): profil + GERÇEK arayüz KABUL.
     * Arayüz adı "all" olduğunda cihaz "invalid interface type in 'all'"
     * diyerek reddediyor; kısayolu lehçe kapatır, buraya hiç gelmez
     * (bkz. JunosDialect::supportsAllKeyword).
     *
     * @param  array<string, mixed>  $settings
     */
    private function elsDocument(NetworkSwitch $switch, string $interfaceName, array $settings): string
    {
        $ad = $this->escape($this->profileName($interfaceName));

        $esik = filled($settings['level'] ?? null)
            ? '<bandwidth-percentage>'.(int) $settings['level'].'</bandwidth-percentage>'
            : (filled($settings['bandwidth'] ?? null)
                ? '<bandwidth-level>'.(int) $settings['bandwidth'].'</bandwidth-level>'
                : '');

        /*
         * Profil "replace" ile yazılıyor: kapatma bayrağı burada TEKRAR
         * yazılmazsa her arayüz düzenlemesi onu sessizce silerdi.
         */
        $kapat = $switch->storm_control_shutdown ? '<action-shutdown/>' : '';

        return '<configuration>'
            ."<forwarding-options><storm-control-profiles replace=\"replace\"><name>{$ad}</name>"
            ."<all>{$esik}</all>{$kapat}</storm-control-profiles></forwarding-options>"
            .'<interfaces><interface><name>'.$this->escape($interfaceName).'</name><unit><name>0</name>'
            ."<family><ethernet-switching><storm-control><profile-name>{$ad}</profile-name></storm-control>"
            .'</ethernet-switching></family></unit></interface></interfaces>'
            .'</configuration>';
    }

    /**
     * Denetim dışında bırakılabilecek trafik türleri.
     *
     * @var array<string, string>
     */
    private const EXEMPTIONS = [
        'no-broadcast' => 'no_broadcast',
        'no-unknown-unicast' => 'no_unknown_unicast',
        'no-multicast' => 'no_multicast',
        'no-registered-multicast' => 'no_registered_multicast',
        'no-unregistered-multicast' => 'no_unregistered_multicast',
    ];

    private function profile(NetworkSwitch $switch): JunosSchemaProfile
    {
        return $switch->schema_profile ?? JunosSchemaProfile::PreEls;
    }

    /**
     * @param  list<string>  $documents
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function send(NetworkSwitch $switch, array $documents, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $documents,
            $summary,
            fn (NetconfSession $session) => $this->storm->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
