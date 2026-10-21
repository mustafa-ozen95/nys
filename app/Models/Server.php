<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionProtocol;
use App\Enums\ServerOs;
use App\Enums\ServerStatus;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'short_name',
        'long_name',
        'ip_address',
        'os_type',
        'os_version',
        'connection_protocol',
        'connection_port',
        'connection_username',
        'connection_password',
        'location',
        'status',
        'notes',
    ];

    protected $hidden = [
        'connection_password',
    ];

    protected function casts(): array
    {
        return [
            'os_type' => ServerOs::class,
            'connection_protocol' => ConnectionProtocol::class,
            'status' => ServerStatus::class,
            'connection_password' => 'encrypted',
            'connection_port' => 'integer',
        ];
    }

    /**
     * Bu sunucunun parola değiştirme turları.
     *
     * @return HasMany<ServerPasswordRotation, $this>
     */
    public function passwordRotations(): HasMany
    {
        return $this->hasMany(ServerPasswordRotation::class)->latest('id');
    }

    /**
     * En son tur — ekranın "şu an ne durumda" sorusunun cevabı.
     *
     * @return HasOne<ServerPasswordRotation, $this>
     */
    public function latestPasswordRotation(): HasOne
    {
        return $this->hasOne(ServerPasswordRotation::class)->latestOfMany();
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('short_name', 'like', "%{$value}%")
                ->orWhere('long_name', 'like', "%{$value}%")
                ->orWhere('ip_address', 'like', "%{$value}%")
        ));
    }
}
