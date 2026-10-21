<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Junos\JunosDialect;

/**
 * Cihazın yapılandırma şeması ailesi.
 *
 * Juniper, EX ailesinde şemayı bir kez değiştirdi: ELS (Enhanced Layer 2
 * Software) öncesi ve sonrası aynı ayarı farklı adlarla yazıyor. En görünür
 * fark portun anahtarlama modudur:
 *
 *   pre-ELS  family ethernet-switching port-mode access
 *   ELS      family ethernet-switching interface-mode access
 *
 * EX4200/EX3200 (ör. 12.3R12.4) pre-ELS'tir. EX2300/EX3400/EX4300 ve
 * QFX ELS'tir.
 *
 * Profil CİHAZA SORULARAK bulunur, sürüm tablosundan tahmin edilmez
 * (bkz. SchemaProbe): "port-mode" yüklenebiliyorsa pre-ELS, yüklenemiyorsa
 * ELS. Tersi ayırt edici DEĞİLDİR — 12.3 "interface-mode"u da kabul ediyor.
 */
enum JunosSchemaProfile: string
{
    case PreEls = 'pre_els';
    case Els = 'els';

    /**
     * Bu ailenin şekil sorularına cevap veren lehçe.
     *
     * Şekiller enum'da DEĞİL lehçede durur: yeni bir Junos ailesi eklemek
     * servislere değil, tek bir sınıfa dokunmak olsun diye
     * (bkz. config/junos.php).
     */
    public function dialect(): JunosDialect
    {
        return JunosDialect::make($this->value);
    }

    /**
     * Cihazın modeline ve sürümüne göre aile tahmini.
     *
     * Şema sondası cihaza soru sorarak kesin cevabı verir; bu yol sonda
     * açılamadığında ya da hızlı bir tahmin gerektiğinde kullanılır.
     * Hiçbiri sahiplenmezse null döner — uydurma yapılmaz.
     */
    public static function guess(?string $model, ?string $version): ?self
    {
        foreach (self::cases() as $profile) {
            if ($profile->dialect()->matches($model, $version)) {
                return $profile;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::PreEls => 'ELS öncesi',
            self::Els => 'ELS',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::PreEls => BadgeVariant::Gray,
            self::Els => BadgeVariant::Cyan,
        };
    }

    /**
     * Portun anahtarlama modunu taşıyan Junos etiketi.
     */
    public function switchingModeElement(): string
    {
        return $this->dialect()->switchingModeElement();
    }

    /**
     * VLAN'ın yönlendirilmiş arayüzünü taşıyan Junos arayüzü.
     *
     *   pre-ELS  interfaces vlan unit 100   →  l3-interface vlan.100
     *   ELS      interfaces irb unit 100    →  l3-interface irb.100
     *
     * ELS'te "interfaces vlan" diye bir arayüz YOKTUR; yanlış adla yazılan
     * yükleme commit check'te düşer.
     */
    public function l3InterfaceName(): string
    {
        return $this->dialect()->l3InterfaceName();
    }

    /**
     * Port aynalamanın (analyzer) yaşadığı kapsayıcı.
     *
     *   ELS öncesi  ethernet-switching-options analyzer <ad>
     *   ELS         forwarding-options analyzer <ad>
     *
     * İkisi de test4'te ölçüldü: ELS cihazda ELS öncesi biçim "syntax error"
     * veriyor. Panel kapsayıcıyı ayırmadığı için ELS bir switchte port
     * aynalama HİÇ yazılamıyordu.
     */
    public function analyzerRootElement(): string
    {
        return $this->dialect()->analyzerRootElement();
    }

    /**
     * Aynalama ÇIKIŞINDA arayüz adı düz metin mi?
     *
     *   ELS öncesi  <output><interface><name>ge-0/0/3.0</name></interface></output>
     *   ELS         <output><interface>ge-0/0/3.0</interface></output>
     *
     * ELS'te sarmalanmış biçim "syntax error" veriyor (test4'te ölçüldü).
     * GİRİŞ tarafı ikisinde de sarmalanmıştır; yalnızca çıkış farklı.
     */
    public function usesPlainAnalyzerOutput(): bool
    {
        return $this->dialect()->usesPlainAnalyzerOutput();
    }

    /**
     * Hız ayarı arayüzün KENDİ altında düz metin olarak mı durur?
     *
     *   ELS öncesi : ether-options { speed { ethernet-100m; } }
     *   ELS        : interfaces <ad> { speed 100m; }
     *
     * test4 (EX2300-24T, 24.4R2-S4) üzerinde ölçüldü: ELS öncesi biçim ELS
     * cihazda "syntax error" verir. Port formu hızı her kayıtta gönderdiği
     * için bu ayrım olmadan ELS bir switchte HİÇBİR port düzenlemesi
     * çalışmıyordu.
     */
    public function usesPlainSpeed(): bool
    {
        return $this->dialect()->usesPlainSpeed();
    }

    /**
     * Fırtına denetiminin yaşadığı yer.
     *
     * İki aile birbirinin yerine geçmez ve bu ölçüldü (test1, commit check):
     * ELS'in "forwarding-options storm-control-profiles" biçimi ELS öncesi
     * cihazda "syntax error" veriyor. Tersi de doğrudur.
     *
     * İKİSİ DE ÖLÇÜLDÜ. ELS biçimi test4'te (EX2300, 24.4R2-S4) kabul
     * ediliyor; ELS öncesi biçim aynı cihazda "syntax error" veriyor.
     */
    public function usesStormControlProfiles(): bool
    {
        return $this->dialect()->usesStormControlProfiles();
    }

    /**
     * DHCP snooping ve port güvenliği "ethernet-switching-options
     * secure-access-port" altında mı?
     *
     * ELS öncesinde evet ve cihazda ölçüldü (test1, commit check):
     * "secure-access-port { vlan all { examine-dhcp; } }" kabul ediliyor.
     *
     * ELS'te bu düğüm YOKTUR; Juniper snooping'i forwarding-options
     * dhcp-relay altına, port güvenliğini switch-options'a taşıdı. Bu ayrı
     * bir hiyerarşidir ve elimizde ELS cihaz olmadığı için UYDURULMAZ:
     * panel ELS cihazda bu ekranı salt okunur bırakır ve yazma isteğini
     * doğrulamada reddeder. Yanlış bir yol yazmaktansa yazmamak yeğdir.
     */
    public function usesSecureAccessPort(): bool
    {
        return $this->dialect()->usesSecureAccessPort();
    }

    /**
     * VoIP (ses) VLAN ayarının yaşadığı kapsayıcı.
     *
     *   ELS öncesi  ethernet-switching-options voip interface <ad>
     *   ELS         switch-options voip interface <ad>
     *
     * ELS öncesi biçim test1'de (12.3R12.4) commit check ile doğrulandı:
     * cihaz YAPIYI kabul etti, reddetme yalnızca semantikti ("Interface
     * ge-0/0/20.0 not found or does not have ethernet-switching family").
     *
     * ELS biçimi de ÖLÇÜLDÜ: test4'te (EX2300, 24.4R2-S4) "switch-options
     * voip" kabul ediliyor, "ethernet-switching-options voip" ise aynı
     * cihazda "syntax error" veriyor.
     */
    public function voipRootElement(): string
    {
        return $this->dialect()->voipRootElement();
    }

    /**
     * L3 arayüz adı olabilecek her önek. Okuma yolu profilden bağımsız
     * çalışmak zorunda: cihazdan gelen değer neyse envantere o girer ve
     * doğrulama onu geri çevirmemelidir.
     *
     * @return list<string>
     */
    public static function l3InterfaceNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $profile): string => $profile->l3InterfaceName(),
            self::cases(),
        )));
    }
}
