<?php

declare(strict_types=1);

namespace App\Services\Junos;

use App\Enums\SecureAccessAction;

/**
 * ELS (Enhanced Layer 2 Software) — EX2300 / EX3400 / EX4300 ve sonrası.
 *
 * Şekiller test4 (EX2300-24T, Junos 24.4R2-S4) üzerinde commit check ile
 * ölçüldü. Bu ailenin yolları belgeden ÇIKARILMAZ, cihazda doğrulanır;
 * ayrıntısı .ai/rules/junos.md içinde.
 */
class ElsDialect extends JunosDialect
{
    public function key(): string
    {
        return 'els';
    }

    public function matches(?string $model, ?string $version): bool
    {
        return $model !== null
            && preg_match('/^EX(23|34|43|44|46)/i', $model) === 1;
    }

    public function switchingModeElement(): string
    {
        return 'interface-mode';
    }

    public function l3InterfaceName(): string
    {
        return 'irb';
    }

    /**
     * Aynalama ELS'te "forwarding-options" altındadır; ELS öncesi biçim
     * cihazda "syntax error" verir.
     */
    public function analyzerRootElement(): string
    {
        return 'forwarding-options';
    }

    /**
     * Yalnızca ÇIKIŞ düz metindir:
     *   <output><interface>ge-0/0/3.0</interface></output>
     * Giriş iki ailede de sarmalanmıştır.
     */
    public function usesPlainAnalyzerOutput(): bool
    {
        return true;
    }

    /**
     * Hız arayüzün kendi altındadır: interfaces <ad> { speed 100m; }
     */
    public function usesPlainSpeed(): bool
    {
        return true;
    }

    public function speedValues(): array
    {
        return config('netconf.els_speeds');
    }

    public function usesStormControlProfiles(): bool
    {
        return true;
    }

    /**
     * ELS'te "ethernet-switching-options" kökü YOKTUR. İstenirse cihaz
     * "syntax error, expecting </configuration>" diyerek bütün okumayı
     * reddediyor (test4'te ölçüldü) — ayarların bir kısmı değil, EŞİTLEMENİN
     * TAMAMI düşüyor.
     *
     * @return list<string>
     */
    public function absentConfigurationRoots(): array
    {
        return ['ethernet-switching-options'];
    }

    /**
     * ELS'te kapatma ayarı switch genelinde DEĞİL, her profilin içindedir
     * (test4'te ölçüldü):
     *
     *   forwarding-options storm-control-profiles <p> {
     *       all { bandwidth-percentage 80; }
     *       action-shutdown;              <- burada
     *   }
     *
     * "ethernet-switching-options storm-control action-shutdown" ELS'te
     * "syntax error" veriyor; "all" düğümünün İÇİNE yazmak da öyle.
     */
    public function stormShutdownPerProfile(): bool
    {
        return true;
    }

    /**
     * ELS "all" sözde-adını iki yerde de reddediyor (test4'te ölçüldü):
     *
     *   arayüz  → "invalid interface type in 'all'"
     *   VLAN adı → "Invalid vlan name specified. Reserved word cannot be used"
     *
     * Kısayol yok; panel gerçek port ve VLAN adı ister.
     */
    public function supportsAllKeyword(): bool
    {
        return false;
    }

    public function voipRootElement(): string
    {
        return 'switch-options';
    }

    /**
     * ELS'te tek bir "secure-access-port" düğümü YOKTUR; ayarlar üç ayrı
     * hiyerarşiye dağılmıştır:
     *
     *   vlans <v> forwarding-options dhcp-security   (snooping, arp, source guard)
     *   vlans <v> switch-options                      (mac-move-limit)
     *   switch-options interface <if>                 (interface-mac-limit)
     */
    public function usesSecureAccessPort(): bool
    {
        return false;
    }

    /**
     * ELS'te bu iki alan VLAN KAPSAMINDA yaşar (test4'te ölçüldü):
     *
     *   dhcp-trusted  → vlans <v> ... dhcp-security group <g> { overrides trusted; interface <if>; }
     *   allowed-mac   → vlans <v> switch-options interface <if> static-mac <mac>
     *
     * Panel bu yüzden ELS'te hangi VLAN olduğunu sorar; alan kapatılmaz.
     */
    /**
     * ELS "log" ve "none"ı reddediyor, "drop-and-log" ekliyor (test4'te
     * ölçüldü: cihaz "should be configured with drop/drop-and-log/shutdown
     * action" diyor).
     *
     * @return list<SecureAccessAction>
     */
    public function macLimitActions(): array
    {
        return [
            SecureAccessAction::Drop,
            SecureAccessAction::DropAndLog,
            SecureAccessAction::Shutdown,
        ];
    }

    /**
     * ELS'te MAC sınırı EYLEMSİZ kabul edilmiyor; cihaz commit check'te
     * reddediyor. Panel eylemi kendisi seçmez — trafiği düşürmek ya da portu
     * kapatmak operatörün kararıdır — ve doğrulamada sorar.
     */
    public function requiresMacLimitAction(): bool
    {
        return true;
    }

    /**
     * ELS L3 arayüzünü etiketsiz VLAN'a bağlatmıyor:
     * "l3-interface can be configured only under vlans with
     * 'vlan-id'/'vlan-tags'" (test4'te ölçüldü).
     *
     * ELS öncesinde bu kısıt YOKTUR; orada etiketsiz ama L3 arayüzü olan
     * VLAN'lar çalışır.
     */
    public function requiresVlanIdForL3(): bool
    {
        return true;
    }

    public function vlanScopedSecureAccessFields(): array
    {
        return [
            'dhcp_trusted' => 'ELS’te DHCP güveni VLAN’a bağlıdır (dhcp-security group).',
            'allowed_macs' => 'ELS’te izinli MAC listesi VLAN’a bağlıdır (switch-options static-mac).',
        ];
    }
}
