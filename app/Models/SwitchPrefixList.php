<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeVariant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchPrefixListFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SwitchPrefixList extends Model
{
    /** @use HasFactory<SwitchPrefixListFactory> */
    use HasFactory;

    use HasUuid;

    /*
     * "N korunan satır" bilgisi üç alanda da aynı işi yapıyor; hesabı ortak
     * trait'te durur.
     */
    use PreservesDeviceLines;

    protected $fillable = [
        'egress_interfaces',
        'apply_paths',
        'residual_xml',
        'is_manageable',
        'switch_id',
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'egress_interfaces' => 'array',
            'apply_paths' => 'array',
            'residual_xml' => 'array',
            'is_manageable' => 'boolean',
        ];
    }

    /**
     * Liste cihazda DİNAMİK doldurulmuş mu?
     *
     * apply-path ifadesi olan listenin girdileri yapılandırmada tek tek
     * yazılı değildir; panel onları göremez. Ekranın "bu liste boş, üzerinden
     * hiçbir trafik geçemez" uyarısı bu durumda YANLIŞTIR ve olmayan bir
     * tehlikeyi haber verir.
     *
     * @return Attribute<bool, never>
     */
    protected function isDynamic(): Attribute
    {
        return Attribute::get(fn (): bool => ($this->apply_paths ?? []) !== []);
    }

    /**
     * Panelin tanımadığı satırlar tek yerde durur: listenin kendi çocukları.
     *
     * Liste sil+yeniden yaz EDİLMEDİĞİ için bu satırlar cihazda zaten yerinde
     * kalır; saklanmalarının sebebi "panel bu listenin tamamını göstermiyor"
     * diyebilmektir.
     *
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [$this->residual_xml];
    }

    /**
     * Çıkış bekçisi rozeti.
     *
     * Blade enum seçimi yapmasın diye burada durur; diğer modellerde de
     * rozet kararı modelden gelir.
     *
     * @return Attribute<BadgeVariant, never>
     */
    protected function egressBadge(): Attribute
    {
        return Attribute::get(fn (): BadgeVariant => BadgeVariant::Orange);
    }

    /**
     * Bu liste bir uplinkin çıkışında bekçilik ediyor mu?
     *
     * Ediyorsa, listede olmayan bir blok o uplinkten DIŞARI ÇIKAMAZ — anons
     * listesine girmiş olması yetmez.
     *
     * @return Attribute<bool, never>
     */
    protected function isEgressGuard(): Attribute
    {
        return Attribute::get(fn (): bool => ($this->egress_interfaces ?? []) !== []);
    }

    /**
     * Bu listeye anons edilen adres blokları.
     *
     * Liste silinmeden önce bakılır: blok hâlâ ona atıf yapıyorsa cihaz
     * commit'i reddeder (bkz. DependencyGuard).
     *
     * @return HasMany<AddressBlock, $this>
     */
    public function addressBlocks(): HasMany
    {
        return $this->hasMany(AddressBlock::class, 'switch_prefix_list_id');
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }

    /** @return HasMany<SwitchPrefixListEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(SwitchPrefixListEntry::class);
    }
}
