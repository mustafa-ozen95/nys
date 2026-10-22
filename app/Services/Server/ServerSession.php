<?php

declare(strict_types=1);

namespace App\Services\Server;

/**
 * Sürücünün açık bağlantısı.
 *
 * Oturumu açan kimlik de taşınır: ayrıcalık yükseltme (sudo) parolayı yeniden
 * ister ve sürücü onu başka yerden bulamaz.
 */
class ServerSession
{
    /** @param  callable(): void  $kapatici */
    public function __construct(
        public readonly mixed $handle,
        public readonly string $user,
        public readonly string $password,
        private $closer,
    ) {}

    public function close(): void
    {
        ($this->closer)();
    }
}
