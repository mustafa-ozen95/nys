<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialProtocol;
use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchCredential extends Model
{
    /** @use HasFactory<SwitchCredentialFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'protocol',
        'username',
        'password',
        'community',
        'auth_key',
        'port',
    ];

    protected $hidden = [
        'password',
        'community',
        'auth_key',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => CredentialProtocol::class,
            'password' => 'encrypted',
            'community' => 'encrypted',
            'auth_key' => 'encrypted',
            'port' => 'integer',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }
}
