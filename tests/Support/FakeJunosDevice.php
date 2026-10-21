<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;

/**
 * İsteğe bakıp cevap veren sahte Junos cihazı.
 *
 * Sırayla yanıt kuyruğu tutan bir sahte taşıma, RPC sayısı her değiştiğinde
 * testleri kırıyordu; oysa testin ilgilendiği şey cihaza NE yazıldığı, kaç
 * RPC gittiği değil. Bu sınıf gelen RPC'ye göre karşılığını döndürür, böylece
 * testler okuma sırasından bağımsız kalır.
 *
 * Gerçek cihaza bağlanılmaz (bkz. .ai/rules/services.md); yanıtlar Junos
 * çıktılarının yapısını taklit eden sadeleştirilmiş örneklerdir.
 */
final class FakeJunosDevice implements NetconfTransport
{
    private const DELIMITER = "\n]]>]]>";

    /** @var list<string> Cihaza yazılan ham veriler */
    public array $written = [];

    public bool $isOpen = false;

    public int $closeCount = 0;

    /** @var array{host: string, port: int, username: string, password: string, timeout: int}|null */
    public ?array $connection = null;

    private bool $greeted = false;

    /**
     * Bu oturumda yapılandırma YÜKLENDİ mi?
     *
     * Gerçek cihazda "compare rollback 0" yükleme yapılmadan BOŞ döner: aday
     * ile yayındaki yapılandırma aynıdır. Sahte cihaz her zaman fark
     * döndürünce, yazma yolunun "adayda başkasının bekleyen değişikliği var
     * mı" koruması testte hep tetikleniyordu — sahte, olmayan bir durumu
     * anlatıyordu.
     */
    private bool $loaded = false;

    /**
     * @param  string  $diff  compare rollback 0 yanıtı; boş verilirse cihaz
     *                        zaten istenen durumdadır ve commit atılmaz
     * @param  list<array{name: string, admin: string, oper: string, index: int, speed?: string, duplex?: string, in_bps?: int, out_bps?: int}>  $interfaces
     * @param  array<string, int>  $vlans  VLAN adı => etiket
     * @param  array{cpu_idle?: int, memory?: int, temperature?: int, uptime?: int}|null  $metrics
     *                                                                                              Ölçüm RPC'lerinin (route-engine, environment)
     *                                                                                              yanıtı. Null verilirse cihaz gerçekçi
     *                                                                                              varsayılanlar döndürür.
     */
    public function __construct(
        private string $diff = '[edit interfaces]',
        private array $interfaces = [['name' => 'ge-0/0/5', 'admin' => 'up', 'oper' => 'down', 'index' => 530]],
        private array $vlans = [],
        private ?string $routeInformation = null,
        private ?string $staticConfig = null,
        private ?string $policyOptions = null,
        private ?string $commitInformation = null,
        private string $interfaceExtras = '',
        private ?string $bgpConfiguration = null,
        private ?string $bgpSummary = null,
        private ?string $autonomousSystem = null,
        private ?int $deviceCount = null,
        private ?array $metrics = null,

        /**
         * Verilirse load-configuration bu hatayla düşer. Şema sondası
         * (SchemaProbe) tam olarak bunu ölçüyor: bilinmeyen bir etiket
         * cihazda LOAD adımında "syntax error" veriyor.
         */
        private ?string $loadError = null,

        /**
         * Yalnızca BELİRLİ değerleri reddeden cihaz.
         *
         * Yetenek sondası tam olarak bunu ölçüyor: aynı alanın bir değeri
         * kabul edilirken öteki "invalid value" alıyor (EX2300'de speed 1g).
         * Tek bir loadError bunu anlatamaz.
         *
         * @var list<string> yükte geçerse yükleme düşer
         */
        private array $loadRejects = [],
    ) {}

    public function open(string $host, int $port, string $username, string $password, int $timeout): void
    {
        $this->connection = compact('host', 'port', 'username', 'password', 'timeout');
        $this->isOpen = true;
        $this->greeted = false;
        $this->loaded = false;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function readUntil(string $terminator): string
    {
        // Oturum önce cihazın hello mesajını okur.
        if (! $this->greeted) {
            $this->greeted = true;

            return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
                .'<capability>urn:ietf:params:netconf:base:1.0</capability>'
                .'</capabilities><session-id>1</session-id></hello>'.self::DELIMITER;
        }

        return $this->reply((string) end($this->written));
    }

    /**
     * base:1.0 konuşur; parçalı okuma bu sahtede hiç çağrılmaz.
     */
    public function readExactly(int $bytes): string
    {
        throw new NetconfException('Sahte cihaz base:1.0 konuşur; parçalı okuma istenmemeli.');
    }

    public function close(): void
    {
        $this->closeCount++;
        $this->isOpen = false;
    }

    /**
     * Cihaza yazılan her şey tek metin olarak; testler burada arar.
     */
    public function payload(): string
    {
        return implode("\n", $this->written);
    }

    private function reply(string $request): string
    {
        $this->guardTextLoad($request);

        if ($this->loadError !== null && str_contains($request, '<load-configuration')) {
            return $this->error($this->loadError);
        }

        if (str_contains($request, '<load-configuration')) {
            foreach ($this->loadRejects as $parca) {
                if (str_contains($request, $parca)) {
                    return $this->error('invalid value');
                }
            }
        }

        if (str_contains($request, '<load-configuration')) {
            $this->loaded = true;
        }

        return match (true) {
            str_contains($request, 'compare="rollback"') => $this->wrap(
                '<configuration-information><configuration-output>'
                .htmlspecialchars($this->loaded ? $this->diff : '', ENT_XML1)
                .'</configuration-output></configuration-information>'
            ),
            str_contains($request, 'get-software-information') => $this->wrap(
                '<software-information><host-name>jn32-test</host-name>'
                .'<product-model>ex4200-24t</product-model><package-information><name>junos</name>'
                .'<comment>JUNOS Base OS boot [12.3R12.4]</comment></package-information></software-information>'
            ),
            str_contains($request, 'get-chassis-inventory') => $this->wrap(
                '<chassis-inventory><chassis><name>Chassis</name>'
                .'<serial-number>TESTSN0000002</serial-number><description>EX4200-24T</description>'
                .'</chassis></chassis-inventory>'
            ),
            str_contains($request, 'get-route-engine-information') => $this->wrap(
                '<route-engine-information><route-engine>'
                ."<temperature junos:celsius=\"{$this->metric('temperature', 39)}\">39 degrees C</temperature>"
                ."<memory-buffer-utilization>{$this->metric('memory', 50)}</memory-buffer-utilization>"
                ."<cpu-idle>{$this->metric('cpu_idle', 94)}</cpu-idle>"
                .'<model>EX4200-24T, 8 POE</model><serial-number>TESTSN0000002</serial-number>'
                ."<up-time junos:seconds=\"{$this->metric('uptime', 43772)}\">12 hours</up-time>"
                .'</route-engine></route-engine-information>'
            ),
            str_contains($request, 'get-environment-information') => $this->wrap(
                '<environment-information><environment-item><name>FPC 0 CPU</name><class>Temp</class>'
                ."<temperature junos:celsius=\"{$this->metric('temperature', 39)}\">39 degrees C</temperature>"
                .'</environment-item></environment-information>'
            ),
            str_contains($request, 'get-interface-information') => $this->wrap(
                '<interface-information>'.$this->interfaceStatus().'</interface-information>'
            ),
            str_contains($request, 'get-route-information') => $this->routeInformation
                ?? $this->wrap('<route-information><route-table><table-name>inet.0</table-name></route-table></route-information>'),
            str_contains($request, 'get-commit-information') => $this->commitInformation
                ?? $this->wrap('<commit-information><commit-history><sequence-number>0</sequence-number>'
                    .'<user>admin</user><client>netconf</client></commit-history></commit-information>'),
            str_contains($request, '<interfaces/>') => $this->wrap(
                '<configuration>'.$this->interfaceConfig().$this->vlanConfig().$this->chassisConfig().'</configuration>'
            ),
            str_contains($request, '<routing-options/>') => $this->staticConfig
                ?? $this->wrap('<configuration><routing-options/></configuration>'),
            str_contains($request, '<policy-options/>') => $this->policyOptions
                ?? $this->wrap('<configuration><policy-options/></configuration>'),
            str_contains($request, '<autonomous-system/>') => $this->wrap(
                '<configuration><routing-options>'
                .($this->autonomousSystem === null ? '' : "<autonomous-system><as-number>{$this->autonomousSystem}</as-number></autonomous-system>")
                .'</routing-options></configuration>'
            ),
            str_contains($request, '<bgp/>') => $this->bgpConfiguration
                ?? $this->wrap('<configuration><protocols/></configuration>'),
            str_contains($request, 'get-bgp-summary-information') => $this->bgpSummary
                ?? $this->wrap('<bgp-information/>'),
            default => $this->wrap('<ok/>'),
        };
    }

    private function interfaceStatus(): string
    {
        return implode('', array_map(
            static fn (array $port): string => '<physical-interface>'
                ."<name>{$port['name']}</name><admin-status>{$port['admin']}</admin-status>"
                ."<oper-status>{$port['oper']}</oper-status><snmp-index>{$port['index']}</snmp-index>"
                .'<speed>'.($port['speed'] ?? 'Auto').'</speed>'
                .'<duplex>'.($port['duplex'] ?? 'Auto').'</duplex>'
                .'<ethernet-autonegotiation><link-partner-duplexity>full-duplex</link-partner-duplexity>'
                .'<link-partner-speed>1000 Mbps</link-partner-speed></ethernet-autonegotiation>'
                .'<traffic-statistics>'
                .'<input-bps>'.($port['in_bps'] ?? 0).'</input-bps>'
                .'<output-bps>'.($port['out_bps'] ?? 0).'</output-bps>'
                .'</traffic-statistics>'
                .'</physical-interface>',
            $this->interfaces,
        ));
    }

    private function metric(string $name, int $default): int
    {
        return $this->metrics[$name] ?? $default;
    }

    private function interfaceConfig(): string
    {
        return '<interfaces>'.implode('', array_map(
            static fn (array $port): string => "<interface><name>{$port['name']}</name></interface>",
            $this->interfaces,
        )).$this->interfaceExtras.'</interfaces>';
    }

    /**
     * "ae limiti": chassis aggregated-devices ethernet device-count.
     */
    private function chassisConfig(): string
    {
        if ($this->deviceCount === null) {
            return '';
        }

        return '<chassis><aggregated-devices><ethernet>'
            ."<device-count>{$this->deviceCount}</device-count>"
            .'</ethernet></aggregated-devices></chassis>';
    }

    private function vlanConfig(): string
    {
        if ($this->vlans === []) {
            return '';
        }

        $body = '';

        foreach ($this->vlans as $name => $tag) {
            $body .= "<vlan><name>{$name}</name><vlan-id>{$tag}</vlan-id></vlan>";
        }

        return "<vlans>{$body}</vlans>";
    }

    /**
     * junos: ön eki kök etikette bildirilir; gerçek cihaz da böyle yapar ve
     * sayısal nitelikler (celsius, seconds) ancak o zaman okunabilir.
     */
    /**
     * Metin yüklemesinde cihazın GERÇEK katılığını taklit eder.
     *
     * Junos (12.3R12.4) iki şeyi reddediyor; sahte cihaz bunları kabul
     * ederse testler yeşil kalırken üretim kırık kalır:
     *
     *   - CDATA: "internal communications error (tag '![CDATA[...')"
     *   - yanlış sarmalayıcı: set eylemi <configuration-set> ister,
     *     merge/override <configuration-text>.
     *
     * Bu yüzden kontrol burada durur: bir daha aynı hata sessizce geçemez.
     */
    private function guardTextLoad(string $request): void
    {
        if (! str_contains($request, 'load-configuration') || ! str_contains($request, 'format="text"')) {
            return;
        }

        if (str_contains($request, '<![CDATA[')) {
            throw new \RuntimeException(
                'Cihaz CDATA kabul etmiyor: metin XML kaçışıyla gönderilmeli.'
            );
        }

        $set = str_contains($request, 'action="set"');
        $beklenen = $set ? '<configuration-set>' : '<configuration-text>';

        if (! str_contains($request, $beklenen)) {
            throw new \RuntimeException(
                "Metin yüklemesi {$beklenen} bekliyor; eylem ile sarmalayıcı ayrışmış."
            );
        }
    }

    /**
     * Cihazın rpc-error yanıtı.
     */
    private function error(string $message): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0">'
            .'<rpc-error><error-severity>error</error-severity>'
            .'<error-message>'.htmlspecialchars($message, ENT_XML1).'</error-message>'
            .'</rpc-error></rpc-reply>'.self::DELIMITER;
    }

    private function wrap(string $body): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"'
            .' xmlns:junos="http://xml.juniper.net/junos/12.3R12/junos">'
            .$body.'</rpc-reply>'.self::DELIMITER;
    }
}
