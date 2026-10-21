<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use Illuminate\Support\Str;

/**
 * Panel yönetimli kurulum şablonu: temel kimlik ve erişim.
 *
 * Kullanıcı şablonun kendisini görmez; ekrana yalnızca girdileri yazar
 * (cihaz adı, yönetim adresi, DNS...) ve panel cihaza uygun metni üretir.
 *
 * Üretilen metin "set" satırlarıdır — terminale yapıştırılan biçimin aynısı.
 * Böylece ekranda gösterilen önizleme, cihaza gidenle birebir aynıdır ve
 * gerekirse elle de uygulanabilir.
 *
 * Cihaza göre değişen kısımlar profilden gelir: yönetim arayüzünün adı
 * sanal şasi kurulumunda "vme", tek cihazda "me0"dır ve bu şablonda
 * varsayılmaz, cihazdan okunur.
 */
class BaseConfigurationTemplate
{
    /**
     * @param  array{
     *     hostname: string,
     *     management_address: string,
     *     management_prefix: int,
     *     gateway: ?string,
     *     dns: list<string>,
     *     ntp: list<string>,
     *     root_password: ?string,
     *     admin_username: ?string,
     *     admin_password: ?string,
     *     snmp_community: ?string,
     *     archival_site?: ?string,
     * }  $input
     */
    public function build(array $input, DeviceProfile $profile): string
    {
        $satirlar = [
            ...$this->identity($input, $profile),
            ...$this->access($input),
            ...$this->services(),
            ...$this->snmp($input),
            ...$this->archival($input),
        ];

        return implode("\n", array_filter($satirlar))."\n";
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function identity(array $input, DeviceProfile $profile): array
    {
        $arayuz = $profile->managementInterface;
        $adres = $input['management_address'].'/'.$input['management_prefix'];

        $satirlar = [
            'set system host-name '.$this->quote((string) $input['hostname']),
            "set interfaces {$arayuz} unit 0 family inet address {$adres}",
        ];

        if (filled($input['gateway'] ?? null)) {
            /*
             * Yönetim yolu statik route ile verilir. Junos'ta "backup-router"
             * yalnızca açılış sırasında geçerlidir; kalıcı yol routing
             * tablosundadır.
             */
            $satirlar[] = 'set routing-options static route 0.0.0.0/0 next-hop '.$input['gateway'];
        }

        foreach ($input['dns'] ?? [] as $sunucu) {
            $satirlar[] = "set system name-server {$sunucu}";
        }

        foreach ($input['ntp'] ?? [] as $sunucu) {
            $satirlar[] = "set system ntp server {$sunucu}";
        }

        return $satirlar;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function access(array $input): array
    {
        $satirlar = [];

        if (filled($input['root_password'] ?? null)) {
            $satirlar[] = 'set system root-authentication plain-text-password-value '
                .$this->quote((string) $input['root_password']);
        }

        if (filled($input['admin_username'] ?? null) && filled($input['admin_password'] ?? null)) {
            $kullanici = (string) $input['admin_username'];

            $satirlar[] = "set system login user {$kullanici} class super-user";
            $satirlar[] = "set system login user {$kullanici} authentication plain-text-password-value "
                .$this->quote((string) $input['admin_password']);
        }

        return $satirlar;
    }

    /**
     * Panelin cihazla konuşabilmesi için gereken servisler.
     *
     * NETCONF olmadan panel bu cihaza bir daha yazamaz; şablonun en kritik
     * satırı budur ve isteğe bağlı değildir.
     *
     * @return list<string>
     */
    private function services(): array
    {
        return [
            'set system services ssh',
            'set system services netconf ssh',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function snmp(array $input): array
    {
        if (blank($input['snmp_community'] ?? null)) {
            return [];
        }

        return [
            'set snmp community '.$this->quote((string) $input['snmp_community']).' authorization read-only',
        ];
    }

    /**
     * Her commit sonrası yapılandırmayı yedek sunucusuna gönderme.
     *
     * Adres kimlik içerir ve ayarlarda şifreli durur; şablon onu buradan
     * okur, ekrandan istemez.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function archival(array $input): array
    {
        $adres = (string) ($input['archival_site'] ?? config('archival.site'));

        if (! config('archival.enabled') || blank($adres)) {
            return [];
        }

        return [
            'set system archival configuration transfer-on-commit',
            'set system archival configuration archive-sites '.$this->quote($adres),
        ];
    }

    /**
     * Junos'ta boşluk, "@" ve "/" içeren değerler tırnaklanır; tırnak
     * unutulursa satır sessizce yanlış ayrıştırılır.
     */
    private function quote(string $value): string
    {
        return '"'.str_replace('"', '', Str::squish($value)).'"';
    }
}
