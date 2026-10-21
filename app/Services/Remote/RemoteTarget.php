<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\ConnectionProtocol;
use Illuminate\Database\Eloquent\Model;

/**
 * Uzak oturum hedefi.
 *
 * Switch ve sunucu farklı tablolarda durur ama oturum açarken ihtiyaç duyulan
 * bilgi aynıdır; bu nesne ikisini tek biçime indirger.
 */
final readonly class RemoteTarget
{
    public function __construct(
        public Model $subject,
        public string $label,
        public string $host,
        public ConnectionProtocol $protocol,
        public int $port,
        public string $username,
        public string $password,
    ) {}

    public function isSsh(): bool
    {
        return $this->protocol === ConnectionProtocol::Ssh;
    }
}
