<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchAnalyzerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir port aynalama (analyzer) oturumu.
 *
 * Seçilen kaynakların trafiği kopyalanıp tek bir hedefe gönderilir: ya bir
 * ARAYÜZE ya bir VLAN'a. İkisi birlikte verilemez (Junos kuralı) ve kaynak
 * portun kendisi hedef olamaz.
 */
class SwitchAnalyzer extends Model
{
    /** @use HasFactory<SwitchAnalyzerFactory> */
    use HasFactory;

    use HasUuid;

    /*
     * "N korunan satır" hesabı bütün cihaz nesnelerinde aynıdır.
     */
    use PreservesDeviceLines;

    /**
     * Korunan ham satırların yol adları.
     *
     * Analyzer düğümü bütün olarak yeniden kurulduğu için panelin tanımadığı
     * çocuklar HER SEVİYEDE ayrı ayrı saklanmak zorunda: "output" altındaki
     * bilinmeyen bir satır ile "input/ingress" altındaki karışırsa geri
     * yazarken yanlış yere basılır ve cihaz reddeder.
     */
    public const RESIDUAL_ROOT = '';

    public const RESIDUAL_INPUT = 'input';

    public const RESIDUAL_INGRESS = 'input/ingress';

    public const RESIDUAL_EGRESS = 'input/egress';

    public const RESIDUAL_OUTPUT = 'output';

    public const RESIDUAL_OUTPUT_VLAN = 'output/vlan';

    protected $fillable = [
        'switch_id', 'name', 'ratio', 'loss_priority',
        'output_interface', 'output_vlan',
        'ingress_interfaces', 'ingress_vlans',
        'egress_interfaces', 'egress_vlans',
        'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ratio' => 'integer',
            'ingress_interfaces' => 'array',
            'ingress_vlans' => 'array',
            'egress_interfaces' => 'array',
            'egress_vlans' => 'array',
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Belirli bir seviyede korunan ham satırlar.
     *
     * @return list<string>|null
     */
    public function residualAt(string $path): ?array
    {
        return ($this->residual_xml ?? [])[$path] ?? null;
    }

    /**
     * Aynalanan trafiğin gittiği yer.
     *
     * @return Attribute<string, never>
     */
    protected function outputLabel(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            filled($this->output_interface) => (string) $this->output_interface,
            filled($this->output_vlan) => "VLAN {$this->output_vlan}",

            // Hedefsiz analyzer cihazda tanımlı olabilir; hiçbir şey kopyalanmaz.
            default => 'hedef yok',
        });
    }

    /**
     * Aynalanan kaynakların tek satırlık özeti.
     *
     * Blade hesap yapmasın diye burada durur (bkz. .ai/rules/arayuz.md).
     *
     * @return Attribute<string, never>
     */
    protected function sourceSummary(): Attribute
    {
        return Attribute::get(function (): string {
            $parcalar = array_filter([
                $this->listed('giriş', $this->ingress_interfaces),
                $this->listed('çıkış', $this->egress_interfaces),
                $this->listed('giriş VLAN', $this->ingress_vlans),
                $this->listed('çıkış VLAN', $this->egress_vlans),
            ]);

            return $parcalar === [] ? 'kaynak seçilmemiş' : implode(' · ', $parcalar);
        });
    }

    /**
     * Cihazdan kopyalanacak hiçbir kaynak seçilmemişse aynalama çalışmaz.
     *
     * @return Attribute<bool, never>
     */
    protected function isIdle(): Attribute
    {
        return Attribute::get(fn (): bool => $this->sources() === []);
    }

    /**
     * Bütün kaynakların düz listesi.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return [
            ...($this->ingress_interfaces ?? []),
            ...($this->egress_interfaces ?? []),
            ...($this->ingress_vlans ?? []),
            ...($this->egress_vlans ?? []),
        ];
    }

    /**
     * Korunan satırlar altı ayrı seviyede durur; kullanıcı için hepsi tek bir
     * gerçektir: "bu aynalamada panelin göstermediği N satır var".
     *
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return array_values($this->residual_xml ?? []);
    }

    /**
     * @param  ?array<int, string>  $values
     */
    private function listed(string $label, ?array $values): ?string
    {
        return $values === null || $values === []
            ? null
            : $label.' '.implode(', ', $values);
    }
}
