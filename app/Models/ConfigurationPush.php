<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihaza atılmış bir config.
 *
 * Gövde ve fark şifreli saklanır: şablon çıktısı root ve admin parolalarını,
 * yedek sunucusunun FTP kimliğini içerir. Elle yapıştırılan metin de parola
 * taşıyabilir. İkisi de listede basılmaz; yalnızca açıkça istendiğinde
 * gösterilir.
 */
class ConfigurationPush extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'switch_id',
        'target_host',
        'target_label',
        'mode',
        'source',
        'body',
        'diff',
        'committed',
        'warnings',
    ];

    /**
     * Listede ve JSON yanıtlarda gövde görünmez.
     *
     * @var list<string>
     */
    protected $hidden = [
        'body',
        'diff',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'diff' => 'encrypted',
            'warnings' => 'array',
            'committed' => 'boolean',
        ];
    }

    /**
     * Geçmişte yalnızca kullanıcının erişebildiği cihazların satırları görünür.
     *
     * Ekranın cihaz listesi zaten süzülüyordu ama GEÇMİŞ tablosu süzülmüyordu:
     * bir switche config yükleme yetkisi olan, bütün cihazların ne zaman kim
     * tarafından yazıldığını — envanter dışı cihazlar dahil — görebiliyordu.
     *
     * Envanterde olmayan cihaza yalnızca yönetici yazabilir (bkz.
     * provisionExternalDevice); o satırları da yalnızca yönetici görür.
     *
     * @param  Builder<static>  $query
     */
    /*
     * AYNI KURALIN İKİNCİ YOLU: tekil kayıt için aynı soruyu
     * ConfigurationPushPolicy::view() yanıtlar. İkisi ayrışamaz.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            /*
             * İKİ YETKİ BİRDEN: cihazı GÖRME ve ona YAZMA. Policy de aynısını
             * soruyor (permitsPrivileged önce view'ı arar). Yalnızca
             * 'provision' aransaydı, cihazı göremeyen ama yazma yetkisi
             * verilmiş biri listede kayıt görür, detayına girince 403 alırdı.
             */
            $query->whereIn('switch_id', NetworkSwitch::query()->visibleTo($user, 'view')->select('id'))
                ->whereIn('switch_id', NetworkSwitch::query()->visibleTo($user, 'provision')->select('id'));

            if ($user->isAdmin()) {
                $query->orWhereNull('switch_id');
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }
}
