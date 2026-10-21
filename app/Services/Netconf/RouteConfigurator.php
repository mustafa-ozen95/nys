<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\RouteProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoute;
use App\Support\Netconf\ResidualXml;

/**
 * Statik route'ları cihaza yazar.
 *
 * YALNIZCA statik route yazılabilir. Direct, Local, OSPF ve BGP yolları cihaz
 * tarafından öğrenilir; yapılandırmada karşılıkları yoktur ve panelden
 * değiştirilemezler. Bunun dışındaki HER statik route yönetilebilir.
 *
 * Yazma "replace" ile yapılır: route düğümü olduğu gibi yeniden kurulur.
 * Sil+ekle ikilisi yerine tek eleman kullanmak, aradaki anlık boşluğu da
 * ortadan kaldırır (cihazda doğrulandı).
 *
 * PANELİN TANIMADIĞI SATIRLAR KAYBOLMAZ: okurken ham XML olarak saklanan
 * çocuk düğümler (residual_xml) yeniden yazılırken aynen basılır. Panel
 * anladığını yazıp anlamadığını silseydi böyle route'ları "salt okunur" ilan
 * etmek zorunda kalırdı.
 *
 * Commit onaylandıktan sonra route tablosu cihazdan geri okunur ve envanter
 * ondan yazılır. Böylece panel niyeti değil, cihazın gerçeğini gösterir.
 */
class RouteConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private RouteSynchronizer $routes,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function create(NetworkSwitch $switch, string $destination, int $prefixLength, array $settings = []): array
    {
        return $this->apply(
            $switch,
            $settings['rib'] ?? null,
            $this->routeElement($destination, $prefixLength, $settings),
            "route eklendi: {$destination}/{$prefixLength}",
            $settings['routing_instance'] ?? null,
        );
    }

    /**
     * Değişiklik tek commit'tir. Hedef ya da RIB değiştiyse eski satır aynı
     * yüklemede silinir; yoksa "replace" zaten yerine yazar.
     *
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function replace(
        NetworkSwitch $switch,
        SwitchRoute $route,
        string $destination,
        int $prefixLength,
        array $settings = [],
    ): array {
        $rib = $settings['rib'] ?? null;
        $instance = $settings['routing_instance'] ?? null;

        /*
         * TABLO da taşınma sebebidir: route ana tablodan bir yönlendirme
         * tablosuna (ya da tersine) geçtiyse eski satır silinmezse cihazda
         * İKİ yerde birden durur ve hangisinin geçerli olduğu belirsizleşir.
         */
        $tasindi = $route->cidr !== "{$destination}/{$prefixLength}"
            || $route->rib !== $rib
            || $route->routing_instance !== $instance;

        $documents = [];

        if ($tasindi) {
            $documents[] = $this->wrap(
                $route->rib,
                $this->deleteElement($route->destination, $route->prefix_length),
                $route->routing_instance,
            );
        }

        $documents[] = $this->wrap($rib, $this->routeElement($destination, $prefixLength, $settings), $instance);

        return $this->send($switch, $documents, "route degistirildi: {$destination}/{$prefixLength}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchRoute $route): array
    {
        return $this->apply(
            $switch,
            $route->rib,
            $this->deleteElement($route->destination, $route->prefix_length),
            "route silindi: {$route->cidr}",
            $route->routing_instance,
        );
    }

    /**
     * Yalnızca STATİK route yazılabilir; öğrenilen yolların yapılandırmada
     * karşılığı yoktur.
     *
     * "Panelin anlamadığı alan var" diye bir engel YOKTUR: anlaşılmayan
     * satırlar ham hâlleriyle korunur ve geri yazılır.
     */
    public function isWritable(SwitchRoute $route): bool
    {
        return $route->protocol === RouteProtocol::Static;
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(
        NetworkSwitch $switch,
        ?string $rib,
        string $routeElement,
        string $summary,
        ?string $instance = null,
    ): array {
        return $this->send($switch, [$this->wrap($rib, $routeElement, $instance)], $summary);
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
            fn (NetconfSession $session) => $this->routes->sync($switch, $session),
        );
    }

    /**
     * Route'u doğru RIB'in altına sarar.
     *
     * Varsayılan tabloda "routing-options static", adlandırılmış tabloda
     * "routing-options rib <ad> static" olur. IPv6 statik route'ların yeri
     * ikincisidir (rib inet6.0) ve cihazda doğrulandı.
     */
    private function wrap(?string $rib, string $routeElement, ?string $instance = null): string
    {
        $static = "<static>{$routeElement}</static>";

        if ($rib !== null && $rib !== '') {
            $ad = $this->escape($rib);
            $static = "<rib><name>{$ad}</name>{$static}</rib>";
        }

        $govde = "<routing-options>{$static}</routing-options>";

        /*
         * Route bir yönlendirme tablosunun İÇİNDE olabilir. FBF tipik olarak
         * böyle kurulur: firewall terimi trafiği tabloya atar ve tablonun
         * kendi varsayılan yolu orada durur. Ana tablonun
         * altına yazılsaydı yol yanlış tabloya girer, yönlendirme sessizce
         * çalışmazdı.
         */
        if ($instance !== null && $instance !== '') {
            $ad = $this->escape($instance);
            $govde = "<routing-instances><instance><name>{$ad}</name>{$govde}</instance></routing-instances>";

            return "<configuration>{$govde}</configuration>";
        }

        return "<configuration>{$govde}</configuration>";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function routeElement(string $destination, int $prefixLength, array $settings): string
    {
        $name = $this->escape("{$destination}/{$prefixLength}");
        $body = "<name>{$name}</name>";

        $body .= $this->nextHopElements($settings);
        $body .= $this->qualifiedElements($settings['qualified_next_hops'] ?? []);
        $body .= $this->numberElements($settings);
        $body .= $this->listElements('community', $settings['communities'] ?? []);

        if (filled($settings['next_table'] ?? null)) {
            $body .= '<next-table>'.$this->escape((string) $settings['next_table']).'</next-table>';
        }

        $body .= $this->flagElements($settings['flags'] ?? []);

        // Panelin tanımadığı satırlar: olduğu gibi geri.
        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        /*
         * "replace" düğümü olduğu gibi yeniden kurar: panelden kaldırılan bir
         * alan cihazda da kalkar. Merge kullanılsaydı boşaltılan preference
         * cihazda olduğu yerde kalır, kullanıcı sildiğini sanırdı.
         */
        return "<route replace=\"replace\">{$body}</route>";
    }

    /**
     * Sonraki atlamalar.
     *
     * "Discard", "Reject" ve "Receive" birer atlama değil, yolun kendisinin
     * ne yaptığıdır ve okuyucu onları da bu alanda saklar.
     *
     * @param  array<string, mixed>  $settings
     */
    private function nextHopElements(array $settings): string
    {
        /** @var list<string> $hops */
        $hops = $settings['next_hops'] ?? array_filter([$settings['next_hop'] ?? null]);

        $davranislar = ['discard' => 'discard', 'reject' => 'reject', 'receive' => 'receive'];
        $body = '';

        foreach ($hops as $hop) {
            $anahtar = mb_strtolower(trim((string) $hop));

            if (isset($davranislar[$anahtar])) {
                $body .= '<'.$davranislar[$anahtar].'/>';

                continue;
            }

            if (trim((string) $hop) !== '') {
                $body .= '<next-hop>'.$this->escape((string) $hop).'</next-hop>';
            }
        }

        return $body;
    }

    /**
     * @param  list<array<string, mixed>>  $hops
     */
    private function qualifiedElements(array $hops): string
    {
        $body = '';

        foreach ($hops as $hop) {
            if (blank($hop['name'] ?? null)) {
                continue;
            }

            $ic = '<name>'.$this->escape((string) $hop['name']).'</name>';

            foreach (['preference', 'metric'] as $alan) {
                if (filled($hop[$alan] ?? null)) {
                    $ic .= "<{$alan}>".(int) $hop[$alan]."</{$alan}>";
                }
            }

            if (filled($hop['interface'] ?? null)) {
                $ic .= '<interface>'.$this->escape((string) $hop['interface']).'</interface>';
            }

            $body .= "<qualified-next-hop>{$ic}</qualified-next-hop>";
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function numberElements(array $settings): string
    {
        $body = '';

        foreach (['preference', 'metric', 'tag', 'tag2'] as $alan) {
            if (filled($settings[$alan] ?? null)) {
                $body .= "<{$alan}>".(int) $settings[$alan]."</{$alan}>";
            }
        }

        return $body;
    }

    /**
     * @param  list<string>  $values
     */
    private function listElements(string $element, array $values): string
    {
        $body = '';

        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                $body .= "<{$element}>".$this->escape((string) $value)."</{$element}>";
            }
        }

        return $body;
    }

    /**
     * @param  list<string>  $flags
     */
    private function flagElements(array $flags): string
    {
        $body = '';

        foreach ($flags as $flag) {
            if (trim((string) $flag) !== '') {
                $body .= '<'.$this->escape((string) $flag).'/>';
            }
        }

        return $body;
    }

    private function deleteElement(string $destination, int $prefixLength): string
    {
        $name = $this->escape("{$destination}/{$prefixLength}");

        return "<route delete=\"delete\"><name>{$name}</name></route>";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
