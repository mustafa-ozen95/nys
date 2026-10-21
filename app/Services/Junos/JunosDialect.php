<?php

declare(strict_types=1);

namespace App\Services\Junos;

use App\Enums\SecureAccessAction;
use InvalidArgumentException;

/**
 * Bir Junos ailesinin ŞEKİL SORULARINA verdiği cevaplar.
 *
 * Junos aynı ayarı sürümden sürüme ve aileden aileye farklı yerde, farklı
 * biçimde tutar. Bu farklar servislerin içine "eğer ELS ise" diye
 * serpiştirildiğinde her yeni cihaz ailesi ONLARCA dosyaya dokunmak demek
 * olur — elimizde çok sayıda model ve sürüm var, o yol tıkanır.
 *
 * Burada tersi yapılır: servis şekli SORAR, cevabı lehçe verir. Yeni bir
 * Junos ailesi eklemek şu üç adımdır ve hiçbiri servislere dokunmaz:
 *
 *   1. Bu sınıftan türeyen bir lehçe yaz, yalnızca FARKLI olanı ezersin.
 *   2. config/junos.php içindeki "dialects" listesine ekle.
 *   3. Kalıcı ayrım için JunosSchemaProfile'a bir case ekle.
 *
 * Varsayılanlar ELS ÖNCESİ davranıştır; yeni bir lehçe yalnızca ayrıldığı
 * yeri yazsın diye.
 *
 * Buradaki her cevap CİHAZDA ÖLÇÜLMÜŞTÜR. Ölçülmemiş bir şekil buraya
 * yazılmaz; yazılırsa hangi cihazda ölçüldüğü docblock'a düşer.
 */
abstract class JunosDialect
{
    /** Kalıcı ayrım anahtarı; JunosSchemaProfile değeriyle aynı olmalı. */
    abstract public function key(): string;

    /**
     * Bu lehçe verilen cihazı tanıyor mu?
     *
     * Şema sondası cihaza soru sorarak da karar verebilir; bu yol model ve
     * sürüm bilinen durumlarda sondayı hiç açmadan eşleştirmek içindir.
     * Varsayılan "hayır"dır: eşleşmeyi yalnızca bilerek yazan lehçe iddia
     * eder, yoksa iki lehçe aynı cihaza sahip çıkar.
     */
    public function matches(?string $model, ?string $version): bool
    {
        return false;
    }

    /**
     * Portun anahtarlama modunu taşıyan etiket.
     *
     *   ELS öncesi  port-mode
     *   ELS         interface-mode
     */
    public function switchingModeElement(): string
    {
        return 'port-mode';
    }

    /**
     * VLAN'ın yönlendirilmiş arayüzünün adı: vlan.100 / irb.100.
     */
    public function l3InterfaceName(): string
    {
        return 'vlan';
    }

    /**
     * Port aynalamanın (analyzer) yaşadığı kapsayıcı.
     */
    public function analyzerRootElement(): string
    {
        return 'ethernet-switching-options';
    }

    /**
     * Aynalama ÇIKIŞINDA arayüz adı düz metin mi, sarmalanmış mı?
     */
    public function usesPlainAnalyzerOutput(): bool
    {
        return false;
    }

    /**
     * Hız arayüzün kendi altında düz metin mi
     * (ELS), yoksa ether-options altında eleman mı (ELS öncesi)?
     */
    public function usesPlainSpeed(): bool
    {
        return false;
    }

    /**
     * Panelin Mbps değeri => cihazın yazdığı karşılık.
     *
     * @return array<string, string>
     */
    public function speedValues(): array
    {
        return config('netconf.speeds');
    }

    /**
     * Fırtına denetimi "forwarding-options storm-control-profiles" altında mı?
     */
    public function usesStormControlProfiles(): bool
    {
        return false;
    }

    /**
     * Bu lehçede BULUNMAYAN yapılandırma kökleri.
     *
     * Junos, olmayan bir kök istendiğinde bütün "get-configuration"ı
     * reddediyor; tek fazla kök yüzünden eşitlemenin tamamı düşer
     * (bkz. App\Support\Netconf\ConfigurationFilter).
     *
     * @return list<string>
     */
    public function absentConfigurationRoots(): array
    {
        return [];
    }

    /**
     * "Eşik aşılınca portu kapat" ayarı PROFİL BAŞINA mı yaşıyor?
     *
     * ELS öncesinde hayır: ayar "ethernet-switching-options storm-control"
     * altında switch genelinde tek satırdır.
     */
    public function stormShutdownPerProfile(): bool
    {
        return false;
    }

    /**
     * Ad yerine "all" sözde-adı yazılabilir mi?
     *
     * ELS öncesinde yazılabilir: fırtına denetimi bütün portlara, DHCP
     * snooping bütün VLAN'lara tek satırda bağlanır. Panel bu kısayolu
     * listelerinde sunar.
     */
    public function supportsAllKeyword(): bool
    {
        return true;
    }

    /**
     * VoIP ayarının kapsayıcısı.
     */
    public function voipRootElement(): string
    {
        return 'ethernet-switching-options';
    }

    /**
     * DHCP snooping ve port güvenliği tek bir "secure-access-port" düğümünde
     * mi toplanıyor?
     */
    public function usesSecureAccessPort(): bool
    {
        return true;
    }

    /**
     * Bu lehçede VLAN KAPSAMINDA yaşayan güvenlik alanları.
     *
     * Boş liste "hepsi switch genelinde" demektir. Listedeki bir alan
     * verildiğinde panel hangi VLAN olduğunu SORAR; VLAN'sız yazılamaz.
     *
     * @return array<string, string> alan => kullanıcıya gösterilecek gerekçe
     */
    public function vlanScopedSecureAccessFields(): array
    {
        return [];
    }

    /**
     * MAC sınırı için geçerli eylemler.
     *
     * @return list<SecureAccessAction>
     */
    public function macLimitActions(): array
    {
        return [
            SecureAccessAction::Drop,
            SecureAccessAction::Log,
            SecureAccessAction::Shutdown,
            SecureAccessAction::None,
        ];
    }

    /**
     * MAC sınırı verilince EYLEM de zorunlu mu?
     *
     * ELS öncesinde değildir: eylem yazılmazsa cihazın varsayılanı geçerli
     * olur ve panel kurmadığı bir davranışı dayatmaz.
     */
    public function requiresMacLimitAction(): bool
    {
        return false;
    }

    /**
     * L3 arayüzü taşıyan VLAN'ın vlan-id'si ZORUNLU mu?
     *
     * ELS öncesinde değildir: etiketsiz bir VLAN da l3-interface taşıyabilir
     * (test1'de ölçüldü); var olan yapılandırmalarda böyle VLAN'lar
     * bulunabilir.
     */
    public function requiresVlanIdForL3(): bool
    {
        return false;
    }

    /**
     * Anahtardan lehçe. Lehçeler durumsuzdur; bir kez kurulup paylaşılırlar.
     *
     * @throws InvalidArgumentException
     */
    public static function make(string $key): self
    {
        if (isset(self::$kurulanlar[$key])) {
            return self::$kurulanlar[$key];
        }

        $sinif = config('junos.dialects')[$key] ?? null;

        if ($sinif === null) {
            throw new InvalidArgumentException("Tanımlı olmayan Junos lehçesi: {$key}");
        }

        return self::$kurulanlar[$key] = new $sinif;
    }

    /** @var array<string, self> */
    private static array $kurulanlar = [];
}
