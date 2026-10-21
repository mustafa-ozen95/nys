<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;

/**
 * Cihazın kendi ayarlarını yazar: sistem adı, saat dilimi, isim sunucuları,
 * NTP ve günlük hedefleri.
 *
 * LİSTE ALANLARINDA silme AÇIKÇA yapılır. Junos merge yalnızca ekler; bir
 * DNS sunucusu listeden çıkarıldığında yalnızca yeni listeyi yazmak eskisini
 * bırakırdı. Silinecekler kayıttaki eski değerlerden hesaplanır ve adlarıyla
 * silinir — adsız silme bu Junos'ta sözdizimi hatası veriyor (archive-sites
 * ile aynı tuzak).
 *
 * Hepsi tek commit'te gider: cihaz bir an yarım ayarla kalmaz.
 */
class DeviceSettingsWriter
{
    public function __construct(
        private ConfigurationWriter $writer,
        private DeviceSettingsSynchronizer $settings,
    ) {}

    /**
     * @param  array{hostname?: ?string, time_zone?: ?string, name_servers?: list<string>, ntp_servers?: list<string>, syslog_hosts?: list<string>, alarm_ethernet_link_down?: ?string, alarm_management_link_down?: ?string}  $values
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, array $values): array
    {
        $body = '';

        if (array_key_exists('hostname', $values) && filled($values['hostname'])) {
            $body .= '<host-name>'.$this->escape((string) $values['hostname']).'</host-name>';
        }

        if (array_key_exists('time_zone', $values)) {
            $body .= filled($values['time_zone'])
                ? '<time-zone>'.$this->escape((string) $values['time_zone']).'</time-zone>'
                : '<time-zone delete="delete"/>';
        }

        $body .= $this->list('name-server', $values['name_servers'] ?? null, $switch->name_servers ?? []);
        $body .= $this->ntp($values, $switch);
        $body .= $this->nested('syslog', 'host', $values['syslog_hosts'] ?? null, $switch->syslog_hosts ?? [], true);

        $alarm = $this->alarms($values);
        $protokol = $this->protocols($values, $switch);

        if ($body === '' && $alarm === '' && $protokol === '') {
            return ['diff' => '', 'committed' => false, 'warnings' => []];
        }

        /*
         * Alarmlar "chassis" altındadır, geri kalanı "system" altında; ikisi
         * TEK belgede gider ki cihaz bir an yarım ayarla kalmasın.
         */
        $configuration = '<configuration>'
            .($body === '' ? '' : "<system>{$body}</system>")
            .($alarm === '' ? '' : "<chassis><alarm>{$alarm}</alarm></chassis>")
            .($protokol === '' ? '' : "<protocols>{$protokol}</protocols>")
            .'</configuration>';

        $sonuc = $this->writer->apply(
            $switch,
            $configuration,
            'cihaz ayarlari guncellendi',
            fn (NetconfSession $session) => $this->settings->sync($switch, $session),
        );

        /*
         * Envanterdeki ad ancak CİHAZ kabul ettikten sonra güncellenir; bu
         * yüzden denetleyicide değil burada (kuyruktaki yazmanın içinde)
         * yapılır. Cihaz reddederse panel kendini cihazdan farklı bir adla
         * göstermemeli.
         */
        if ($sonuc['committed'] && filled($values['hostname'] ?? null)) {
            $switch->update(['hostname' => (string) $values['hostname']]);
        }

        return $sonuc;
    }

    /**
     * Protokol anahtarları: lldp, lldp-med, igmp-snooping.
     *
     * Erişim switchlerinde üçü de sıkça tanımlıdır ("interface all",
     * "vlan all"); panel onları gösterip yönetebilmeli. İki lehçede de kabul
     * edildiği ölçüldü (test2 ve test4); lehçe dallanması gerekmiyor.
     *
     * KAPSAM DEĞİŞTİRİLİRKEN ÖNCE SİLİNİR: Junos merge yalnızca ekler, bir
     * arayüzü listeden çıkarmak "replace" ister. Kapatmak ise protokolü
     * tamamen siler.
     *
     * @param  array<string, mixed>  $values
     */
    private function protocols(array $values, NetworkSwitch $switch): string
    {
        $eslesme = [
            'lldp_interfaces' => ['lldp', 'interface'],
            'lldp_med_interfaces' => ['lldp-med', 'interface'],
            'igmp_snooping_vlans' => ['igmp-snooping', 'vlan'],
        ];

        $body = '';

        foreach ($eslesme as $alan => [$protokol, $cocuk]) {
            if (! array_key_exists($alan, $values)) {
                continue;
            }

            $kapsam = $values[$alan];

            if ($kapsam === null || $kapsam === []) {
                // Kapatmak protokolü SİLER; boş bir düğüm bırakmak değil.
                $body .= "<{$protokol} delete=\"delete\"/>";

                continue;
            }

            $icerik = '';

            foreach ($kapsam as $ad) {
                $icerik .= "<{$cocuk}><name>".$this->escape((string) $ad)."</name></{$cocuk}>";
            }

            $body .= "<{$protokol} replace=\"replace\">{$icerik}</{$protokol}>";
        }

        return $body;
    }

    /**
     * NTP: sunucular ve AÇILIŞ sunucusu.
     *
     * İkisi farklı iş yapar. "server" ntpd'yi çalıştırır ve saati yavaşça
     * kaydırır; ama ntpd fark 1000 saniyeyi aşınca saati adımlamayı REDDEDER
     * (panic eşiği). Saati yıllarca geride kalmış bir cihazı "server" tek
     * başına asla düzeltemez.
     *
     * "boot-server" açılışta ntpd'den ÖNCE çalışır ve saati bir hamlede
     * yerine oturtur — fark ne olursa olsun. İkisi birlikte kullanılır.
     *
     * İki lehçede de kabul edildiği ölçüldü (test2 ve test4).
     *
     * @param  array<string, mixed>  $values
     */
    private function ntp(array $values, NetworkSwitch $switch): string
    {
        $body = $this->nested('ntp', 'server', $values['ntp_servers'] ?? null, $switch->ntp_servers ?? []);

        if (! array_key_exists('ntp_boot_server', $values)) {
            return $body;
        }

        $acilis = filled($values['ntp_boot_server'])
            ? '<boot-server>'.$this->escape((string) $values['ntp_boot_server']).'</boot-server>'
            : '<boot-server delete="delete"/>';

        /*
         * "ntp" kapsayıcısı iki kez emit edilebilir; Junos ikisini birleştirir.
         * Tek düğümde toplamak nested()'ı yeniden yazmayı gerektirirdi ve
         * kazancı yok.
         */
        return $body."<ntp>{$acilis}</ntp>";
    }

    /**
     * Şasi alarmları.
     *
     * Boş bırakmak satırı SİLER; cihaz kendi varsayılanına döner. Değerler
     * cihazın kabul ettikleriyle sınırlıdır: geçersiz bir seviye "invalid
     * value" ile reddediliyor (jn32-test ile doğrulandı).
     *
     * @param  array<string, mixed>  $values
     */
    private function alarms(array $values): string
    {
        $eslesme = [
            'ethernet' => 'alarm_ethernet_link_down',
            'management-ethernet' => 'alarm_management_link_down',
        ];

        $body = '';

        foreach ($eslesme as $element => $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $body .= filled($values[$key])
                ? "<{$element}><link-down>".$this->escape((string) $values[$key])."</link-down></{$element}>"
                : "<{$element}><link-down delete=\"delete\"/></{$element}>";
        }

        return $body;
    }

    /**
     * Doğrudan "system" altındaki adlandırılmış liste (name-server).
     *
     * @param  ?list<string>  $yeni
     * @param  list<string>  $eski
     */
    private function list(string $element, ?array $yeni, array $eski): string
    {
        if ($yeni === null) {
            return '';
        }

        $body = '';

        foreach (array_diff($eski, $yeni) as $silinecek) {
            $body .= "<{$element} delete=\"delete\"><name>".$this->escape($silinecek)."</name></{$element}>";
        }

        foreach ($yeni as $deger) {
            $body .= "<{$element}><name>".$this->escape($deger)."</name></{$element}>";
        }

        return $body;
    }

    /**
     * Bir kapsayıcının altındaki adlandırılmış liste (ntp/server,
     * syslog/host).
     *
     * @param  ?list<string>  $yeni
     * @param  list<string>  $eski
     * @param  bool  $withContents  syslog hedefi hangi kayıtları alacağını
     *                              da söylemek zorundadır; "any notice"
     *                              olmadan satır anlamsızdır
     */
    private function nested(string $parent, string $element, ?array $yeni, array $eski, bool $withContents = false): string
    {
        if ($yeni === null) {
            return '';
        }

        $body = '';

        foreach (array_diff($eski, $yeni) as $silinecek) {
            $body .= "<{$element} delete=\"delete\"><name>".$this->escape($silinecek)."</name></{$element}>";
        }

        foreach ($yeni as $deger) {
            $icerik = $withContents ? '<contents><name>any</name><notice/></contents>' : '';
            $body .= "<{$element}><name>".$this->escape($deger)."</name>{$icerik}</{$element}>";
        }

        return $body === '' ? '' : "<{$parent}>{$body}</{$parent}>";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
