<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JunosUserClass;
use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchLoginUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihaz üzerindeki yerel bir giriş hesabı.
 *
 * PAROLA SAKLANMAZ: cihaz kendi özetini tutar, paneldeki kopyası hiçbir işe
 * yaramaz ve yalnızca çalınabilecek bir sır daha olurdu. Panel yalnızca
 * "parolası var mı" bilgisini tutar.
 *
 * Ad cihazda hesabın KİMLİĞİDİR; değiştirmek silip yeniden yaratmak
 * demektir, o yüzden panel adı düzenletmez.
 */
class SwitchLoginUser extends Model
{
    /** @use HasFactory<SwitchLoginUserFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'name',
        'full_name',
        'uid',
        'class',
        'has_password',
    ];

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'has_password' => 'boolean',
        ];
    }

    /**
     * Bilinen sınıf; cihazda elle tanımlanmış özel bir sınıfsa null.
     *
     * @return Attribute<?JunosUserClass, never>
     */
    protected function junosClass(): Attribute
    {
        return Attribute::get(fn (): ?JunosUserClass => JunosUserClass::tryFrom((string) $this->class));
    }

    /**
     * Panelin cihaza bağlanmak için kullandığı hesap mı?
     *
     * Bu hesap silinemez ve yetkisi düşürülemez: panel bir daha
     * bağlanamazdı. Geri alınabilir commit kurtarır ama iki dakikalık
     * kesinti ve gereksiz bir korku demektir.
     *
     * @return Attribute<bool, never>
     */
    protected function isPanelAccount(): Attribute
    {
        return Attribute::get(function (): bool {
            $switch = $this->switch;

            if ($switch === null) {
                return false;
            }

            $credential = $switch->netconfCredential ?? $switch->sshCredential;

            return $credential?->username !== null
                && strcasecmp($credential->username, (string) $this->name) === 0;
        });
    }

    /**
     * Listede tek satırda okunan özet: tam ad, uid ve parola durumu.
     *
     * Blade'de üç ayrı alanı birleştirmek yerine burada hazırlanır; ekran
     * hazır veriyi basar.
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(function (): string {
            $parcalar = array_filter([
                $this->full_name,
                $this->uid === null ? null : "uid {$this->uid}",
                $this->has_password ? 'parolalı' : 'parolasız',
            ]);

            return implode(' · ', $parcalar);
        });
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }
}
