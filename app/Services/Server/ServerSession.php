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
        public readonly mixed $tutamac,
        public readonly string $kullanici,
        public readonly string $parola,
        private $kapatici,
    ) {}

    public function kapat(): void
    {
        ($this->kapatici)();
    }
}
