<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchAnalyzer;
use Illuminate\Support\Collection;

/**
 * Port aynalama ekranının hazır verisi.
 *
 * Seçenek listeleri TEK yerde kurulur çünkü doğrulama da aynı listeye bakar:
 * arayüzde gizlenen bir seçenek sunucuda da reddedilmeli (bkz.
 * .ai/rules/junos.md — "Arayüzdeki her kısıt sunucuda da vardır").
 *
 * İki şey bilinçlidir:
 *
 * 1. Aynalama MANTIKSAL arayüz ister (ge-0/0/21.0), envanterdeki port adı ise
 *    fiziksel (ge-0/0/21). Birim burada eklenir; iki yerde eklenseydi biri
 *    unutulduğunda cihaz "arayüz yok" derdi.
 * 2. Cihazda duran ama listede olmayan bir değer (elle yazılmış bir VLAN,
 *    sökülmüş bir modülün portu) kaydın KENDİ seçeneklerine eklenir. Yoksa
 *    kullanıcı o aynalamayı düzenlediğinde seçenek işaretsiz gelir ve
 *    dokunmadığı kaynak sessizce silinirdi.
 */
class PortMirroringViewService
{
    /**
     * @return array<string, mixed>
     */
    public function screen(NetworkSwitch $switch): array
    {
        $analyzers = $switch->analyzers()->orderBy('name')->get();

        /*
         * Listeler BİR KEZ okunur. Her kayıt için yeniden sorulsaydı üç
         * aynalama üç kat port ve VLAN sorgusu demek olurdu.
         */
        $kaynaklar = $this->sourceInterfaceOptions($switch);
        $cikislar = $this->outputInterfaceOptions($switch);
        $vlanlar = $this->vlanOptions($switch);

        return [
            'analyzers' => $analyzers,

            /*
             * Kutu listeleri ile açılır listeler AYNI kaynaktan gelir ama
             * açılır listede "yok" seçeneği de bulunur; Blade bunu kendisi
             * eklemez (bkz. .ai/rules/interface.md — hesap Blade'de yapılmaz).
             */
            'sourceInterfaceOptions' => $kaynaklar,
            'vlanOptions' => $vlanlar,
            'outputInterfaceOptions' => $this->withNone($cikislar),
            'outputVlanOptions' => $this->withNone($vlanlar),
            'analyzerOptions' => $this->perAnalyzer($analyzers, $kaynaklar, $cikislar, $vlanlar),
        ];
    }

    /**
     * Kaynak olarak seçilebilecek arayüzler.
     *
     * BÜTÜN portlar sunulur, yalnızca anahtarlama modundakiler değil: cihazın
     * kaynak portu reddettiği ÖLÇÜLMEDİ ve olmayan bir kısıt uydurmak
     * kullanıcının geçerli bir yapılandırmayı yazamamasına yol açar (bkz.
     * .ai/rules/junos.md). Anahtarlama dışındaki port yine de işaretlenir;
     * cihaz reddederse commit check'te reddeder ve kalıcı bir değişiklik
     * olmaz.
     *
     * "all" cihazın kabul ettiği sanal addır ve listede DURUR: cihazda öyle
     * yazılmış bir aynalama düzenlenirken seçenek bulunamazsa kaynak
     * kaybolurdu.
     *
     * @return array<string, string>
     */
    public function sourceInterfaceOptions(NetworkSwitch $switch, ?SwitchAnalyzer $analyzer = null): array
    {
        $options = ['all' => 'Bütün portlar (all)'];

        foreach ($switch->ports()->orderBy('if_index')->get(['name', 'port_mode']) as $port) {
            $logical = $this->logical((string) $port->name);

            $options[$logical] = $this->isSwitching($port->port_mode)
                ? $logical
                : "{$logical} (anahtarlama dışı)";
        }

        return $analyzer === null
            ? $options
            : $this->withStored($options, [
                ...($analyzer->ingress_interfaces ?? []),
                ...($analyzer->egress_interfaces ?? []),
            ]);
    }

    /**
     * Port anahtarlama ailesinde mi? Çıkış hedefi için cihazın ŞARTI budur.
     */
    public function isSwitching(?PortMode $mode): bool
    {
        return in_array($mode, [PortMode::Access, PortMode::Trunk], true);
    }

    /**
     * Çıkış hedefi olabilecek arayüzler.
     *
     * Yalnızca ANAHTARLAMA modundaki portlar: cihaz aksini reddediyor —
     * "Analyzer output interface ge-0/0/21.0 should be configured with family
     * ethernet-switching" (test1'de ölçüldü).
     *
     * @return array<string, string>
     */
    public function outputInterfaceOptions(NetworkSwitch $switch, ?SwitchAnalyzer $analyzer = null): array
    {
        $options = $switch->ports()
            ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
            ->orderBy('if_index')
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$this->logical($name) => $this->logical($name)])
            ->all();

        return $analyzer === null
            ? $options
            : $this->withStored($options, array_filter([$analyzer->output_interface]));
    }

    /**
     * @return array<string, string>
     */
    public function vlanOptions(NetworkSwitch $switch, ?SwitchAnalyzer $analyzer = null): array
    {
        $options = $switch->vlans()
            ->orderBy('vlan_tag')
            ->get()
            ->mapWithKeys(fn ($vlan): array => [$vlan->name => $vlan->name.' ('.($vlan->vlan_tag ?? 'etiketsiz').')'])
            ->all();

        return $analyzer === null
            ? $options
            : $this->withStored($options, array_filter([
                ...($analyzer->ingress_vlans ?? []),
                ...($analyzer->egress_vlans ?? []),
                $analyzer->output_vlan,
            ]));
    }

    /**
     * Fiziksel port adını mantıksal arayüze çevirir: ge-0/0/5 → ge-0/0/5.0.
     *
     * Zaten birim taşıyan ad ("ge-0/0/5.0") ve sanal ad ("all") olduğu gibi
     * kalır.
     */
    public function logical(string $name): string
    {
        return $name === 'all' || str_contains($name, '.') ? $name : "{$name}.0";
    }

    /**
     * Her kaydın kendi seçenek kümesi: listede olmayan ama cihazda duran
     * değerler de işaretlenebilsin.
     *
     * @param  Collection<int, SwitchAnalyzer>  $analyzers
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $outputs
     * @param  array<string, string>  $vlans
     * @return array<string, array{sources: array<string, string>, outputs: array<string, string>, vlans: array<string, string>, outputVlans: array<string, string>}>
     */
    private function perAnalyzer(Collection $analyzers, array $sources, array $outputs, array $vlans): array
    {
        return $analyzers->mapWithKeys(fn (SwitchAnalyzer $analyzer): array => [
            $analyzer->uuid => [
                'sources' => $this->withStored($sources, [
                    ...($analyzer->ingress_interfaces ?? []),
                    ...($analyzer->egress_interfaces ?? []),
                ]),
                'outputs' => $this->withNone(
                    $this->withStored($outputs, array_filter([$analyzer->output_interface])),
                ),
                'vlans' => $this->withStored($vlans, array_filter([
                    ...($analyzer->ingress_vlans ?? []),
                    ...($analyzer->egress_vlans ?? []),
                ])),
                'outputVlans' => $this->withNone($this->withStored($vlans, array_filter([
                    ...($analyzer->ingress_vlans ?? []),
                    ...($analyzer->egress_vlans ?? []),
                    $analyzer->output_vlan,
                ]))),
            ],
        ])->all();
    }

    /**
     * Açılır listenin "yok" seçeneği: hedef boş bırakılabilir.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private function withNone(array $options): array
    {
        return ['' => 'Yok'] + $options;
    }

    /**
     * @param  array<string, string>  $options
     * @param  array<int, string>  $stored
     * @return array<string, string>
     */
    private function withStored(array $options, array $stored): array
    {
        foreach ($stored as $value) {
            if ($value !== '' && ! array_key_exists($value, $options)) {
                $options[$value] = "{$value} (cihazda tanımlı)";
            }
        }

        return $options;
    }
}
