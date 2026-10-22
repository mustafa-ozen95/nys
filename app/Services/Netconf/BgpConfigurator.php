<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpNlri;
use App\Enums\IpFamily;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;

/**
 * BGP yapılandırmasını cihaza yazar.
 *
 * Junos'ta "merge" YALNIZCA EKLER: bir alanı boşaltmak istediğinizde onu
 * göndermemeniz yetmez, cihazdaki satır olduğu yerde kalır. Bu yüzden
 * güncelleme iki belgedir ve TEK commit'te uygulanır:
 *
 *   1. yönetilen bütün isteğe bağlı alanlar delete="delete" ile kaldırılır
 *   2. yeni değerler yazılır
 *
 * Grubun kendisi silinmez — silinseydi altındaki komşular da giderdi. Silinen
 * yalnızca grubun ayarlarıdır.
 *
 * Cihazda olmayan bir satırı silmek "statement not found" uyarısı üretir; bu
 * uyarı işi durdurmaz ve kayda geçer (bkz. NetconfSession).
 *
 * BGP lisansı olmayan kasalarda cihaz her commit'te "mgd: requires 'bgp'
 * license" uyarısı verir. Uyarının severity'si "warning"dir, commit başarılı
 * olur ve BGP çalışır; iş bu yüzden durdurulmaz.
 */
class BgpConfigurator
{
    /**
     * Güncellemede önce silinip sonra yeniden yazılan grup alanları. Sıra
     * önemsizdir ama liste eksiksiz olmalı: burada olmayan bir alan panelden
     * boşaltılamaz.
     *
     * @var list<string>
     */
    private const GROUP_RESET = [
        'peer-as',
        'local-as',
        'local-address',
        'authentication-key',
        'hold-time',
        'cluster',
        'graceful-restart',
        'remove-private',
        'as-override',
        'advertise-inactive',
        'multipath',
        'damping',
        'out-delay',
        'bfd-liveness-detection',
        'allow',
        'multihop',
        'log-updown',
        'passive',
        'import',
        'export',
        'description',
    ];

    /**
     * @var list<string>
     */
    private const NEIGHBOR_RESET = [
        'description',
        'peer-as',
        'local-address',
        'authentication-key',
        'hold-time',
        'passive',
        'multihop',
        'import',
        'export',
    ];

    public function __construct(
        private ConfigurationWriter $writer,
        private BgpSynchronizer $bgp,
    ) {}

    /**
     * Cihazın kendi AS numarası. eBGP bu olmadan çalışmaz.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function setAutonomousSystem(NetworkSwitch $switch, string $asn): array
    {
        $configuration = '<configuration><routing-options><autonomous-system>'
            .'<as-number>'.$this->escape($asn).'</as-number>'
            .'</autonomous-system></routing-options></configuration>';

        return $this->apply($switch, $configuration, "AS numarasi ayarlandi: {$asn}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function createGroup(NetworkSwitch $switch, array $attributes): array
    {
        $name = (string) $attributes['name'];

        return $this->apply(
            $switch,
            $this->wrap($this->groupElement($attributes)),
            "bgp grubu eklendi: {$name}",
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function updateGroup(NetworkSwitch $switch, BgpGroup $group, array $attributes): array
    {
        $documents = [
            $this->wrap($this->resetElement($group->name, self::GROUP_RESET)),
            $this->wrap($this->groupElement(['name' => $group->name] + $attributes)),
        ];

        return $this->apply($switch, $documents, "bgp grubu degistirildi: {$group->name}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteGroup(NetworkSwitch $switch, BgpGroup $group): array
    {
        $element = '<group delete="delete"><name>'.$this->escape($group->name).'</name></group>';

        return $this->apply($switch, $this->wrap($element), "bgp grubu silindi: {$group->name}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function createNeighbor(NetworkSwitch $switch, BgpGroup $group, array $attributes): array
    {
        $address = (string) $attributes['address'];

        $element = '<group><name>'.$this->escape($group->name).'</name>'
            .$this->neighborElement($attributes).'</group>';

        return $this->apply($switch, $this->wrap($element), "bgp komsusu eklendi: {$group->name} · {$address}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function updateNeighbor(NetworkSwitch $switch, BgpNeighbor $neighbor, array $attributes): array
    {
        $groupName = $this->escape($neighbor->group->name);
        $address = $this->escape($neighbor->address);

        $reset = '';

        foreach (self::NEIGHBOR_RESET as $field) {
            $reset .= "<{$field} delete=\"delete\"/>";
        }

        $documents = [
            $this->wrap("<group><name>{$groupName}</name><neighbor><name>{$address}</name>{$reset}</neighbor></group>"),
            $this->wrap(
                "<group><name>{$groupName}</name>"
                .$this->neighborElement(['address' => $neighbor->address] + $attributes)
                .'</group>'
            ),
        ];

        return $this->apply($switch, $documents, "bgp komsusu degistirildi: {$neighbor->address}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteNeighbor(NetworkSwitch $switch, BgpNeighbor $neighbor): array
    {
        $element = '<group><name>'.$this->escape($neighbor->group->name).'</name>'
            .'<neighbor delete="delete"><name>'.$this->escape($neighbor->address).'</name></neighbor>'
            .'</group>';

        return $this->apply($switch, $this->wrap($element), "bgp komsusu silindi: {$neighbor->address}");
    }

    /**
     * Grubun yönetilen ayarlarını siler. Grubun KENDİSİ silinmez: altındaki
     * komşular korunmalı.
     *
     * @param  list<string>  $fields
     */
    private function resetElement(string $name, array $fields): string
    {
        $body = '';

        foreach ($fields as $field) {
            $body .= "<{$field} delete=\"delete\"/>";
        }

        return '<group><name>'.$this->escape($name).'</name>'.$body.$this->familyResetElement().'</group>';
    }

    /**
     * Aileler tek tek silinir.
     *
     * Kapsayıcıyı silmek (<family delete="delete"/>) cihazda SESSİZCE hiçbir
     * şey yapmıyor: hata da uyarı da vermiyor, aile olduğu gibi kalıyor;
     * kaldırılmak istenen inet6 ailesi ve eski teardown ayarı yerinde kalır.
     * Alt elemanlar adlarıyla silindiğinde doğru çalışıyor.
     */
    private function familyResetElement(): string
    {
        $body = '';

        foreach (IpFamily::cases() as $family) {
            $body .= '<'.$family->value.' delete="delete"/>';
        }

        return "<family>{$body}</family>";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function groupElement(array $attributes): string
    {
        $body = '<name>'.$this->escape((string) $attributes['name']).'</name>';
        $body .= '<type>'.$this->escape($this->value($attributes['type'])).'</type>';

        foreach (['peer-as' => 'peer_asn', 'local-address' => 'local_address', 'description' => 'description'] as $element => $key) {
            $value = $attributes[$key] ?? null;

            if ($this->filled($value)) {
                $body .= "<{$element}>".$this->escape((string) $value)."</{$element}>";
            }
        }

        /*
         * Sayısal ayarlar: verilmezse satır hiç yazılmaz ve Junos kendi
         * varsayılanını uygular. Sıfır anlamlı bir değer değildir.
         */
        foreach (['hold-time' => 'hold_time', 'out-delay' => 'out_delay'] as $element => $key) {
            if ($this->filled($attributes[$key] ?? null)) {
                $body .= "<{$element}>".(int) $attributes[$key]."</{$element}>";
            }
        }

        /*
         * Oturum anahtarı olduğu gibi gider: cihazdan "$9$..." biçiminde
         * okunmuş olabilir ve Junos kendi şifrelediği değeri kabul eder.
         */
        if ($this->filled($attributes['authentication_key'] ?? null)) {
            $body .= '<authentication-key>'.$this->escape((string) $attributes['authentication_key']).'</authentication-key>';
        }

        // Route reflector küme kimliği; yalnızca iBGP grupta anlamlıdır.
        if ($this->filled($attributes['cluster'] ?? null)) {
            $body .= '<cluster>'.$this->escape((string) $attributes['cluster']).'</cluster>';
        }

        $body .= $this->gracefulRestartElement($attributes['graceful_restart'] ?? null);

        /*
         * BFD: en küçük aralık verilmemişse düğüm hiç yazılmaz. Boş bir
         * bfd-liveness-detection düğümü cihazda commit hatası üretir.
         */
        if ($this->filled($attributes['bfd_minimum_interval'] ?? null)) {
            $body .= '<bfd-liveness-detection><minimum-interval>'
                .(int) $attributes['bfd_minimum_interval']
                .'</minimum-interval></bfd-liveness-detection>';
        }

        // Dinamik komşu: verilen ağdan gelen oturum kabul edilir.
        foreach ($attributes['allow_prefixes'] ?? [] as $prefix) {
            if ($this->filled($prefix)) {
                $body .= '<allow>'.$this->escape((string) $prefix).'</allow>';
            }
        }

        // local-as düz bir değer değil, alt elemanlı bir düğümdür.
        if ($this->filled($attributes['local_asn'] ?? null)) {
            $body .= '<local-as><as-number>'.$this->escape((string) $attributes['local_asn']).'</as-number></local-as>';
        }

        if (($attributes['multihop'] ?? false) === true) {
            $ttl = $attributes['multihop_ttl'] ?? null;

            // ttl verilmezse Junos kendi varsayılanını kullanır; boş eleman
            // yazmak "multihop açık, ttl belirtilmemiş" demektir.
            $body .= $this->filled($ttl)
                ? '<multihop><ttl>'.(int) $ttl.'</ttl></multihop>'
                : '<multihop/>';
        }

        $flags = [
            'log-updown' => 'log_updown',
            'passive' => 'passive',
            'remove-private' => 'remove_private',
            'as-override' => 'as_override',
            'advertise-inactive' => 'advertise_inactive',
            'multipath' => 'multipath',
            'damping' => 'damping',
        ];

        foreach ($flags as $element => $key) {
            if (($attributes[$key] ?? false) === true) {
                $body .= "<{$element}/>";
            }
        }

        foreach (['import' => 'import_policies', 'export' => 'export_policies'] as $element => $key) {
            foreach ($attributes[$key] ?? [] as $policy) {
                if ($this->filled($policy)) {
                    $body .= "<{$element}>".$this->escape((string) $policy)."</{$element}>";
                }
            }
        }

        $families = $this->familyElement($attributes['families'] ?? []);

        return '<group'.$this->inactiveAttribute($attributes['is_inactive'] ?? null).'>'
            .$body.$families.'</group>';
    }

    /**
     * graceful-restart üç durumludur. "default" hiçbir şey yazmaz: cihazın
     * genel ayarı geçerli kalsın. "disable" ile hiç yazmamak aynı şey
     * değildir — genel ayarı açık olan cihazda grup açık kalırdı.
     */
    private function gracefulRestartElement(mixed $value): string
    {
        return match ($this->value($value ?? BgpGracefulRestart::Inherit)) {
            BgpGracefulRestart::Enabled->value => '<graceful-restart/>',
            BgpGracefulRestart::Disabled->value => '<graceful-restart><disable/></graceful-restart>',
            default => '',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $families
     */
    private function familyElement(array $families): string
    {
        if ($families === []) {
            return '';
        }

        $body = '';

        foreach ($families as $family) {
            $name = $this->value($family['family'] ?? IpFamily::Inet);
            $nlri = $this->value($family['nlri'] ?? BgpNlri::Any);

            $body .= "<{$name}><{$nlri}>".$this->prefixLimitElement($family)."</{$nlri}></{$name}>";
        }

        return "<family>{$body}</family>";
    }

    /**
     * @param  array<string, mixed>  $family
     */
    private function prefixLimitElement(array $family): string
    {
        $maximum = $family['prefix_limit_maximum'] ?? null;

        // Sınır yoksa prefix-limit düğümü hiç yazılmaz; aile yine de açılır.
        if (! $this->filled($maximum)) {
            return '';
        }

        $body = '<maximum>'.(int) $maximum.'</maximum>';

        if (($family['teardown'] ?? false) === true) {
            $teardown = '';

            if ($this->filled($family['teardown_threshold'] ?? null)) {
                $teardown .= '<limit-threshold>'.(int) $family['teardown_threshold'].'</limit-threshold>';
            }

            /*
             * "forever" BOŞ bir idle-timeout elemanıdır; dakikalı hâlinde
             * altında <timeout> bulunur. İkisi aynı anda yazılamaz.
             */
            if (($family['idle_timeout_forever'] ?? false) === true) {
                $teardown .= '<idle-timeout/>';
            } elseif ($this->filled($family['idle_timeout_minutes'] ?? null)) {
                $teardown .= '<idle-timeout><timeout>'.(int) $family['idle_timeout_minutes'].'</timeout></idle-timeout>';
            }

            $body .= "<teardown>{$teardown}</teardown>";
        }

        return "<prefix-limit>{$body}</prefix-limit>";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function neighborElement(array $attributes): string
    {
        $body = '<name>'.$this->escape((string) $attributes['address']).'</name>';

        $texts = [
            'description' => 'description',
            'peer-as' => 'peer_asn',
            'local-address' => 'local_address',
            'authentication-key' => 'authentication_key',
        ];

        foreach ($texts as $element => $key) {
            $value = $attributes[$key] ?? null;

            if ($this->filled($value)) {
                $body .= "<{$element}>".$this->escape((string) $value)."</{$element}>";
            }
        }

        if ($this->filled($attributes['hold_time'] ?? null)) {
            $body .= '<hold-time>'.(int) $attributes['hold_time'].'</hold-time>';
        }

        if (($attributes['passive'] ?? false) === true) {
            $body .= '<passive/>';
        }

        if (($attributes['multihop'] ?? false) === true) {
            $ttl = $attributes['multihop_ttl'] ?? null;

            $body .= $this->filled($ttl)
                ? '<multihop><ttl>'.(int) $ttl.'</ttl></multihop>'
                : '<multihop/>';
        }

        foreach (['import' => 'import_policies', 'export' => 'export_policies'] as $element => $key) {
            foreach ($attributes[$key] ?? [] as $policy) {
                if ($this->filled($policy)) {
                    $body .= "<{$element}>".$this->escape((string) $policy)."</{$element}>";
                }
            }
        }

        return '<neighbor'.$this->inactiveAttribute($attributes['is_inactive'] ?? null).'>'
            .$body.'</neighbor>';
    }

    private function wrap(string $body): string
    {
        return "<configuration><protocols><bgp>{$body}</bgp></protocols></configuration>";
    }

    /**
     * @param  string|list<string>  $configuration
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(NetworkSwitch $switch, string|array $configuration, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $configuration,
            $summary,
            fn (NetconfSession $session) => $this->bgp->sync($switch, $session),
        );
    }

    private function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * "deactivate" işareti.
     *
     * Junos bir nesneyi SİLMEDEN devre dışı bırakabilir ve işareti gövdede
     * değil ÖZNİTELİKTE taşır. Geri açmak için active="active" yazılır.
     *
     * Öznitelik hiç yazılmazsa merge cihazdaki durumu KORUR (test1'de
     * ölçüldü); bu yüzden "bilinmiyor" hâli sessizce doğru davranır ve
     * alanı göndermeyen eski bir form devre dışı bir grubu açmaz.
     */
    private function inactiveAttribute(?bool $isInactive): string
    {
        return match ($isInactive) {
            true => ' inactive="inactive"',
            false => ' active="active"',
            default => '',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
