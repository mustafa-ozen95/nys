<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BgpNlri;
use App\Enums\IpFamily;
use Database\Factories\BgpGroupFamilyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir grubun taşıdığı adres ailesi ve o ailenin prefix sınırı.
 *
 * Kayıt varsa grup o aileyi taşıyor demektir; sınır alanları boş olabilir
 * (aile açık ama sınırsız).
 */
class BgpGroupFamily extends Model
{
    /** @use HasFactory<BgpGroupFamilyFactory> */
    use HasFactory;

    protected $fillable = [
        'bgp_group_id',
        'family',
        'nlri',
        'prefix_limit_maximum',
        'teardown',
        'teardown_threshold',
        'idle_timeout_forever',
        'idle_timeout_minutes',
    ];

    protected function casts(): array
    {
        return [
            'family' => IpFamily::class,
            'nlri' => BgpNlri::class,
            'prefix_limit_maximum' => 'integer',
            'teardown' => 'boolean',
            'teardown_threshold' => 'integer',
            'idle_timeout_forever' => 'boolean',
            'idle_timeout_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<BgpGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BgpGroup::class, 'bgp_group_id');
    }

    /**
     * Sınırın ekrandaki tek satırlık özeti: "200 · %100'de düşür · kapalı kal".
     *
     * @return Attribute<string, never>
     */
    protected function limitSummary(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->prefix_limit_maximum === null) {
                return 'Sınırsız';
            }

            $parcalar = ["en fazla {$this->prefix_limit_maximum} prefix"];

            if ($this->teardown) {
                /*
                 * Junos'ta oturum SINIR AŞILINCA düşer; teardown'a verilen
                 * yüzde ise UYARININ hangi noktada başlayacağıdır (Juniper
                 * belgesi). Cihaz değeri 1..100 aralığıyla sınırlıyor, yani
                 * mutlak prefix sayısı değil yüzdedir — 150 denendiğinde
                 * "Value 150 is not within range (1..100)" diyor.
                 *
                 * Yüzdenin kaç prefixe denk geldiği de yazılır: "%90" tek
                 * başına kaç prefix olduğunu söylemiyordu.
                 */
                $parcalar[] = $this->teardown_threshold === null
                    ? 'sınırda düşür'
                    : sprintf(
                        '%%%d (%d prefix) uyar, sınırda düşür',
                        $this->teardown_threshold,
                        (int) round($this->prefix_limit_maximum * $this->teardown_threshold / 100),
                    );
            }

            if ($this->idle_timeout_forever) {
                $parcalar[] = 'kapalı kal';
            } elseif ($this->idle_timeout_minutes !== null) {
                $parcalar[] = "{$this->idle_timeout_minutes} dk sonra dene";
            }

            return implode(' · ', $parcalar);
        });
    }
}
