<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;

/**
 * Port ayarlarını cihaza yazar.
 *
 * Risk sınıfları (bkz. .ai/rules/netconf.md):
 *
 * - Düşük: description, disable. PFE'ye dokunmaz, trafiği etkilemez.
 *   (disable portu kapatır — kasıtlıdır, ama komşu portları etkilemez.)
 * - Orta: ether-options hız/auto-negotiation, PoE. Link yeniden anlaşır,
 *   o portta kısa bir kesinti olur.
 * - Yüksek: port-mode ve VLAN üyeliği. eswd ve PFE yeniden programlanır;
 *   EX3200/4200'de çökme ve link flap riski buradadır.
 *
 * Bütün değişiklikler TEK commit'te gider: yarım uygulanmış bir port ayarı
 * (mod değişti ama VLAN üyeliği eski) cihazı tutarsız bırakırdı.
 *
 * VLAN üyeliği değiştirilirken önce liste silinir, sonra yenisi yazılır.
 * Junos'ta "merge" bir listeyi değiştirmez, üstüne ekler; V05 üyesi olan bir
 * porta V01 yazılsa port ikisinin de üyesi olurdu.
 *
 * Etiketsiz VLAN port moduna göre farklı yazılır:
 *
 * - Access portta üyelik zaten tektir ve o üye etiketsizdir; ayrı bir alan
 *   yoktur.
 * - Trunk portta etiketsiz VLAN `native-vlan-id` ile arayüz seviyesinde
 *   belirtilir ve VLAN üye listesinde de durur. Yalnızca üye listesine
 *   yazılsaydı etiketli olurdu.
 */
class PortConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private PortSynchronizer $ports,
        private IpAddressSynchronizer $addresses,
    ) {}

    /**
     * @param  array{description?: ?string, enabled?: bool, speed?: ?string, autonegotiation?: bool, poe?: bool, mode?: PortMode, vlans?: list<string>, untagged?: ?string, native_vlan_tag?: ?int}  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, SwitchPort $port, array $settings): array
    {
        /*
         * ROUTED bir port anahtarlamaya döndürülüyorsa inet ailesi AYNI
         * belgede silinmeli. Cihaz aksi hâlde reddediyor: "When
         * ethernet-switching family is configured on an interface, no other
         * family type can be configured on the same interface."
         *
         * Bunsuz porta bir kez IP verildikten sonra panelden access/trunk'a
         * geri dönülemiyordu (jn32-test üzerinde commit check ile bulundu).
         */
        /*
         * Karar ADRES KAYDINA değil, PORTUN MODUNA bakar.
         *
         * Cihazın reddetme sebebi ailenin VARLIĞIDIR, adresin değil: CLI'dan
         * son adres silinip geride boş bir "family inet;" kalmış port
         * envanterde adressiz ama hâlâ routed görünür (okuyucu modu tam da
         * aileye bakarak belirliyor). Koşul adrese bakınca böyle bir port
         * panelden bir daha anahtarlamaya döndürülemiyordu; tek çıkış CLI'dı.
         */
        /*
         * YÖNLENDİRMEYE dönerken inet DÜŞMEZ, kurulur. Koşul yalnızca
         * anahtarlamaya geçişte geçerlidir; aksi hâlde "routed" seçen
         * kullanıcı aynı belgede aileyi hem silmiş hem yazmış olurdu.
         */
        if (($settings['mode'] ?? null) instanceof PortMode
            && $settings['mode'] !== PortMode::Routed
            && ($port->port_mode === PortMode::Routed || $port->ipAddresses()->exists())) {
            $settings['drop_inet'] = true;
        }

        $documents = $this->documents(
            $port->name,
            $settings,
            $switch->schema_profile,
            $this->vlanSideClearList($port, $settings),
        );

        if ($documents === []) {
            return ['diff' => '', 'committed' => false, 'warnings' => []];
        }

        return $this->writer->apply(
            $switch,
            $documents,
            "port {$port->name}: ".$this->summary($settings),
            function (NetconfSession $session) use ($switch): void {
                $this->ports->sync($switch, $session);

                /*
                 * Adres envanteri de tazelenir: port ekranından adres
                 * verilebiliyor ve yalnızca portları okumak, ekranın cihazda
                 * duran adresi göstermemesine yol açardı. Aynı oturumda
                 * yapılır; ikinci bir oturum cihazda bir mgd süreci daha
                 * demek olurdu.
                 */
                $this->addresses->sync($switch, $session);
            },
        );
    }

    /**
     * Bir arayüzün ayarlarını kuran belgeler.
     *
     * Ayrı bir metottur çünkü LAG ekranı ae'nin açıklamasını, port modunu ve
     * VLAN üyeliğini toplama ayarlarıyla AYNI commit'te yazar; iki ayrı commit
     * atılsaydı arayüz bir an tutarsız kalırdı.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function documents(
        string $portName,
        array $settings,
        ?JunosSchemaProfile $profile = null,
        array $vlanSideClear = [],
    ): array {
        // Bilinmiyorsa ELS ÖNCESİ varsayılır: lehçe varsayılanı budur.
        $profile ??= JunosSchemaProfile::PreEls;

        $documents = [];

        // Üyelik değişiyorsa önce mevcut liste silinir.
        if (array_key_exists('vlans', $settings) || array_key_exists('untagged', $settings)) {
            $documents[] = $this->wrap($this->interfaceElement($portName, $this->vlanDeleteElement()));

            $cleanup = $this->vlanSideCleanupDocument($portName, $vlanSideClear);

            if ($cleanup !== null) {
                $documents[] = $cleanup;
            }
        }

        $body = $this->interfaceElement($portName, $this->interfaceBody($settings, $profile))
            .$this->poeElement($portName, $settings);

        // Hiçbir alan gelmediyse cihaza boş yükleme gönderilmez.
        if ($body !== '') {
            $documents[] = $this->wrap($body);
        }

        return $documents;
    }

    /**
     * Bir portun VLAN üyeliğini kuran belgeler.
     *
     * VLAN ekranı da buradan geçer: oradan bir porta VLAN atamak, portun
     * üyeliğini DEĞİŞTİRMEK demektir. Access portta iki üye olamaz — cihaz
     * commit check'te "Access interface has more than one vlan member" diyerek
     * reddeder.
     *
     * @param  list<string>  $members  VLAN adları
     * @return list<string>
     */
    public function membershipDocuments(string $portName, array $members, array $previous = []): array
    {
        $documents = [$this->wrap($this->interfaceElement($portName, $this->vlanDeleteElement()))];

        $cleanup = $this->vlanSideCleanupDocument($portName, [...$members, ...$previous]);

        if ($cleanup !== null) {
            $documents[] = $cleanup;
        }

        if ($members !== []) {
            $body = '<unit><name>0</name><family><ethernet-switching><vlan>'.implode('', array_map(
                fn (string $name): string => '<members>'.$this->escape($name).'</members>',
                $members,
            )).'</vlan></ethernet-switching></family></unit>';

            $documents[] = $this->wrap($this->interfaceElement($portName, $body));
        }

        return $documents;
    }

    /**
     * Üyeliğin İKİNCİ YOLUNU siler: "vlans <ad> interface <port>.0".
     *
     * Panel üyeliği arayüz tarafına yazar. İkinci yol bırakılsaydı iki sonuç
     * doğardı ve ikisi de sessizdi:
     *
     *  - Panelden VLAN'ı KALDIRMAK hiçbir şey yapmazdı; port cihazda üye
     *    kalır, panel "kaldırıldı" derdi.
     *  - Porta BAŞKA bir VLAN vermek access portta cihaz reddine düşerdi:
     *    "has more than one vlan member" (test1'de ölçüldü).
     *
     * Olmayan bir satırı silmek cihazda hata değildir (ölçüldü); bu yüzden
     * liste geniş tutulabilir ve fark çıktısında yalnızca gerçek silmeler
     * görünür.
     *
     * @param  list<string>  $vlanNames
     */
    private function vlanSideCleanupDocument(string $portName, array $vlanNames): ?string
    {
        $names = array_values(array_unique(array_filter($vlanNames, static fn (string $name): bool => $name !== '')));

        if ($names === []) {
            return null;
        }

        $unit = $this->escape("{$portName}.0");

        $body = implode('', array_map(
            fn (string $name): string => '<vlan><name>'.$this->escape($name).'</name>'
                ."<interface delete=\"delete\"><name>{$unit}</name></interface></vlan>",
            $names,
        ));

        return $this->wrap("<vlans>{$body}</vlans>");
    }

    /**
     * Port ekranından üyelik yazılırken temizlenecek VLAN adları: portun
     * ŞU ANKİ üyelikleri ve yazılacak YENİ üyeler.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function vlanSideClearList(SwitchPort $port, array $settings): array
    {
        if (! array_key_exists('vlans', $settings) && ! array_key_exists('untagged', $settings)) {
            return [];
        }

        return array_values(array_unique(array_filter([
            ...$port->vlans->pluck('name')->all(),
            ...($settings['vlans'] ?? []),
            $settings['untagged'] ?? null,
        ])));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function interfaceBody(array $settings, JunosSchemaProfile $profile): string
    {
        $body = '';

        if (array_key_exists('description', $settings)) {
            $body .= $settings['description'] === null || $settings['description'] === ''
                ? '<description delete="delete"/>'
                : '<description>'.$this->escape($settings['description']).'</description>';
        }

        if (array_key_exists('enabled', $settings)) {
            $body .= $settings['enabled'] ? '<disable delete="delete"/>' : '<disable/>';
        }

        /*
         * MTU verilmezse satır silinir; cihazın varsayılanına döner.
         * Aralık cihazın kendi cevabıdır: 256..9216 (jn32-test, 12.3R12.4).
         */
        if (array_key_exists('mtu', $settings)) {
            $body .= $settings['mtu'] === null
                ? '<mtu delete="delete"/>'
                : '<mtu>'.((int) $settings['mtu']).'</mtu>';
        }

        $body .= $this->nativeVlan($settings);
        $body .= $this->etherOptions($settings, $profile);
        $body .= $this->unitElement($settings, $profile);

        return $body;
    }

    /**
     * Portun mantıksal birimi: ya ANAHTARLAMA ya YÖNLENDİRME.
     *
     * İkisi aynı arayüzde olamaz — cihaz "When ethernet-switching family is
     * configured on an interface, no other family type can be configured on
     * the same interface" diyor. Bu yüzden mod değişimi iki yönlü ve
     * simetrik olmak zorunda:
     *
     *   access/trunk seçilince  → inet kalkar, ethernet-switching yazılır
     *   routed seçilince        → ethernet-switching kalkar, inet yazılır
     *
     * İKİNCİ YÖN EKSİKTİ. Panel bir portu anahtarlamaya çevirebiliyor ama
     * geri döndüremiyordu: mod listesinde "routed" hiç yoktu ve tek çıkış,
     * IP adresleri ekranından adres vermekti. Adresin Adresleme'de bir bloğu
     * yoksa o yol da kapalıydı; port panelden bir daha kurulamıyordu.
     *
     * Adressiz "family inet" cihazca kabul ediliyor (test1, commit check):
     * port yönlendirmeye döner, adres sonra verilir.
     *
     * @param  array<string, mixed>  $settings
     */
    private function unitElement(array $settings, JunosSchemaProfile $profile): string
    {
        return ($settings['mode'] ?? null) === PortMode::Routed
            ? $this->routedElement($settings)
            : $this->switchingElement($settings, $profile);
    }

    /**
     * Yönlendirme birimi: anahtarlama ailesi kalkar, inet ailesi kurulur.
     *
     * Var olan adresler KORUNUR — "inet" düğümü merge ile yazılıyor, altındaki
     * adreslere dokunulmuyor. Zaten routed olan bir portun ayarını
     * değiştirmek adreslerini silmemeli.
     *
     * @param  array<string, mixed>  $settings
     */
    private function routedElement(array $settings): string
    {
        $inet = $this->filterElement($settings);

        /*
         * Adres AYNI BELGEDE yazılır: portu yönlendirmeye çevirip adresi
         * ikinci bir commit'te vermek, arada portu adressiz bırakırdı.
         * Adres verilmezse satır hiç yazılmaz ve cihazdaki adreslere
         * dokunulmaz — merge yalnızca ekler.
         */
        if (filled($settings['ip_address'] ?? null)) {
            $inet .= '<address><name>'.$this->escape((string) $settings['ip_address']).'</name></address>';
        }

        return '<unit><name>0</name><family>'
            .'<ethernet-switching delete="delete"/>'
            ."<inet>{$inet}</inet>"
            .'</family></unit>';
    }

    /**
     * Arayüze bağlı firewall filtresi.
     *
     * Bağlama okunuyordu ama YAZILAMIYORDU: mod değişiminde silinen bir
     * "filter input KORUMA" satırı panelden bir daha kurulamıyordu. Cihazda
     * doğrulandı — "family inet" altında input ve output birlikte kabul
     * ediliyor; "family ethernet-switching" altında ise bu biçim YOK
     * (syntax error).
     *
     * @param  array<string, mixed>  $settings
     */
    private function filterElement(array $settings): string
    {
        if (! array_key_exists('input_filter', $settings) && ! array_key_exists('output_filter', $settings)) {
            return '';
        }

        $body = '';

        foreach (['input' => 'input_filter', 'output' => 'output_filter'] as $direction => $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }

            $body .= filled($settings[$key])
                ? "<{$direction}><filter-name>".$this->escape((string) $settings[$key])."</filter-name></{$direction}>"
                : "<{$direction} delete=\"delete\"/>";
        }

        return $body === '' ? '' : "<filter>{$body}</filter>";
    }

    /**
     * Trunk portta etiketsiz VLAN'ın etiketi arayüz seviyesinde durur.
     * Access portta ya da etiketsiz VLAN seçilmediğinde satır kaldırılır.
     *
     * @param  array<string, mixed>  $settings
     */
    private function nativeVlan(array $settings): string
    {
        if (! array_key_exists('native_vlan_tag', $settings)) {
            return '';
        }

        $tag = $settings['native_vlan_tag'];

        return $tag === null
            ? '<native-vlan-id delete="delete"/>'
            : '<native-vlan-id>'.((int) $tag).'</native-vlan-id>';
    }

    /**
     * Hız ve auto-negotiation aynı "speed" düğümünde durur; ikisi birden
     * yazılamaz, biri seçilir. replace ile eski değer temizlenir.
     *
     * @param  array<string, mixed>  $settings
     */
    private function etherOptions(array $settings, JunosSchemaProfile $profile): string
    {
        $hasSpeed = array_key_exists('speed', $settings) || array_key_exists('autonegotiation', $settings);
        $hasDuplex = array_key_exists('duplex', $settings);

        if (! $hasSpeed && ! $hasDuplex) {
            return '';
        }

        /*
         * ELS'te hız ether-options ALTINDA DEĞİL, arayüzün kendi altında ve
         * düz metindir. Eski biçim ELS cihazda "syntax error" veriyor ve port
         * formu hızı her kayıtta gönderdiği için, bu ayrım olmadan ELS bir
         * switchte hiçbir port düzenlemesi çalışmıyordu (test4'te ölçüldü).
         *
         * Duplex her iki şemada da ether-options altında kabul ediliyor;
         * orası değişmiyor.
         */
        if ($profile->usesPlainSpeed()) {
            return ($hasSpeed ? $this->elsSpeedElement($settings, $profile) : '')
                .($hasDuplex ? '<ether-options>'.$this->linkModeElement($settings).'</ether-options>' : '');
        }

        /*
         * ELS öncesinde ikisi de "ether-options" altındadır ve TEK elemanda
         * gitmeli: ayrı ayrı gönderilirse ikinci belge birincinin yazdığını
         * değil, yalnızca kendi alanını taşır ve okuması zorlaşır.
         */
        return '<ether-options>'
            .($hasSpeed ? $this->speedElement($settings) : '')
            .($hasDuplex ? $this->linkModeElement($settings) : '')
            .'</ether-options>';
    }

    /**
     * ELS'te hız: interfaces <ad> { speed 100m; }
     *
     * auto-negotiation ayrı bir satırdır ("ether-options auto-negotiation");
     * açıkken hız satırı silinir, cihaz kendi anlaşır. Kapatılıp hız
     * verilmezse cihaz reddeder — "Configure interfaces <ad> speed, as auto
     * negotiation is disabled" — bu yüzden hız yoksa satır silinir ve
     * auto-negotiation da yazılmaz.
     *
     * @param  array<string, mixed>  $settings
     */
    private function elsSpeedElement(array $settings, JunosSchemaProfile $profile): string
    {
        if (($settings['autonegotiation'] ?? null) === true) {
            return '<speed delete="delete"/><ether-options><auto-negotiation/></ether-options>';
        }

        $speed = $settings['speed'] ?? null;
        // Değerler lehçenin sorusudur, servisin değil.
        $elements = $profile->dialect()->speedValues();

        if ($speed !== null && isset($elements[(string) $speed])) {
            return '<speed>'.$this->escape($elements[(string) $speed]).'</speed>';
        }

        return '<speed delete="delete"/>';
    }

    /**
     * Hız ile auto-negotiation aynı "speed" düğümünde durur; ikisi birden
     * yazılamaz, biri seçilir. replace ile eski değer temizlenir.
     *
     * @param  array<string, mixed>  $settings
     */
    private function speedElement(array $settings): string
    {
        $speed = $settings['speed'] ?? null;
        $auto = $settings['autonegotiation'] ?? null;
        $elements = config('netconf.speeds');

        if ($speed !== null && isset($elements[(string) $speed])) {
            return '<speed replace="replace"><'.$elements[(string) $speed].'/></speed>';
        }

        if ($auto === true) {
            return '<speed replace="replace"><auto-negotiation/></speed>';
        }

        return '<speed delete="delete"/>';
    }

    /**
     * Duplex ("link-mode"). Boş bırakmak satırı SİLER: cihaz kendi seçer.
     *
     * İki kısıt cihazda doğrulandı (jn32-test, 12.3R12.4):
     *
     *  1. Half-duplex yalnızca 10m/100m ile geçerlidir — "Half duplex is an
     *     invalid configuration when speed is 10G/1G/Auto". Bu doğrulamada
     *     durur (SwitchPortRequest).
     *  2. Half-duplex AKIŞ KONTROLÜYLE birlikte olmaz — "Half duplex and
     *     flow control enable is an invalid configuration." 802.3x duraklatma
     *     zaten yalnızca full-duplex'te anlamlıdır. Bu yüzden half seçilince
     *     "no-flow-control" AYNI belgede yazılır; kullanıcıdan ayrıca
     *     istenmez, çünkü seçeneği yoktur.
     *
     * Full'e ya da otomatiğe dönerken akış kontrolüne DOKUNULMAZ: kapalı
     * akış kontrolü full-duplex portta olağan bir ayardır ve sessizce geri
     * açmak kullanıcının kurmadığı bir değişiklik olurdu.
     *
     * @param  array<string, mixed>  $settings
     */
    private function linkModeElement(array $settings): string
    {
        $modes = config('netconf.link_modes');
        $duplex = $settings['duplex'] ?? null;

        if ($duplex === null || ! isset($modes[(string) $duplex])) {
            return '<link-mode delete="delete"/>';
        }

        $flow = (string) $duplex === 'half' ? '<no-flow-control/>' : '';

        return $flow.'<link-mode>'.$modes[(string) $duplex].'</link-mode>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function switchingElement(array $settings, JunosSchemaProfile $profile): string
    {
        $body = '';

        /*
         * Anahtarlama modunun etiketi şema ailesine bağlıdır: ELS öncesinde
         * "port-mode", ELS'te "interface-mode". Profil cihaza sorularak
         * bulunur (bkz. SchemaProbe); bilinmiyorsa ELS ÖNCESİ varsayılır —
         * lehçe varsayılanı budur (bkz. JunosDialect).
         */
        $tag = $profile->switchingModeElement();

        if (array_key_exists('mode', $settings) && $settings['mode'] instanceof PortMode) {
            $body .= match ($settings['mode']) {
                PortMode::Trunk => "<{$tag}>trunk</{$tag}>",
                PortMode::Access => "<{$tag}>access</{$tag}>",
                default => "<{$tag} delete=\"delete\"/>",
            };
        }

        $members = $this->members($settings);

        // Üyelik boş bırakılabilir: port hiçbir VLAN'a ait olmaz. Silme
        // belgesi zaten gönderildiği için burada bir şey yazılmaz.
        if ($members !== []) {
            $body .= '<vlan>'.implode('', array_map(
                fn (string $name): string => '<members>'.$this->escape($name).'</members>',
                $members,
            )).'</vlan>';
        }

        if ($body === '') {
            return '';
        }

        // Anahtarlamaya dönerken inet ailesi aynı belgede kalkar.
        $inet = ($settings['drop_inet'] ?? false) === true ? '<inet delete="delete"/>' : '';

        return '<unit><name>0</name><family>'.$inet
            .'<ethernet-switching>'.$body.'</ethernet-switching></family></unit>';
    }

    /**
     * Etiketsiz VLAN üye listesinde de durur; trunk portta ayrıca
     * native-vlan-id ile işaretlenir.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function members(array $settings): array
    {
        $members = array_values($settings['vlans'] ?? []);
        $untagged = $settings['untagged'] ?? null;

        if ($untagged !== null && ! in_array($untagged, $members, true)) {
            $members[] = $untagged;
        }

        return $members;
    }

    private function vlanDeleteElement(): string
    {
        return '<unit><name>0</name><family><ethernet-switching><vlan delete="delete"/></ethernet-switching></family></unit>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function poeElement(string $name, array $settings): string
    {
        if (! array_key_exists('poe', $settings)) {
            return '';
        }

        // Junos'ta PoE varsayılan olarak açıktır; açmak, kapatma satırını
        // silmek demektir. Satır yoksa cihaz uyarı verir, hata değil.
        $body = $settings['poe']
            ? '<interface delete="delete"><name>'.$this->escape($name).'</name></interface>'
            : '<interface><name>'.$this->escape($name).'</name><disable/></interface>';

        return "<poe>{$body}</poe>";
    }

    private function interfaceElement(string $name, string $body): string
    {
        return $body === ''
            ? ''
            : '<interfaces><interface><name>'.$this->escape($name).'</name>'.$body.'</interface></interfaces>';
    }

    private function wrap(string $body): string
    {
        return "<configuration>{$body}</configuration>";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function summary(array $settings): string
    {
        return implode(', ', array_keys($settings)) ?: 'degisiklik yok';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
