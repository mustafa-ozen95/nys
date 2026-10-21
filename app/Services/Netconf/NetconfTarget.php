<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;

/**
 * NETCONF oturumu açılacak hedef.
 *
 * Envanterdeki bir switch olabilir; envanterde OLMAYAN, yalnızca yönetim
 * erişim bilgisine sahip olduğumuz bir cihaz da olabilir. İkisini tek biçime
 * indirger, böylece yazma yolu "kayıtlı mı değil mi" sorusunu sormaz.
 *
 * Parola burada düz metindir ve hiçbir yere yazılmaz: nesne istek boyunca
 * yaşar, kayda geçen tek şey hangi hedefe yazıldığıdır.
 */
readonly class NetconfTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public string $label,
        public ?int $switchId = null,
    ) {}

    public static function fromSwitch(NetworkSwitch $switch): self
    {
        $credential = $switch->netconfCredential ?? $switch->sshCredential;

        if ($credential?->username === null || $credential->password === null) {
            throw new NetconfException("{$switch->hostname} için NETCONF kimliği tanımlı değil.");
        }

        return new self(
            host: (string) $switch->management_ip,
            // SSH kimliğine düşüldüyse onun portu 22'dir; NETCONF oraya bakmaz.
            port: $switch->netconfCredential?->port ?? (int) config('netconf.port'),
            username: $credential->username,
            password: $credential->password,
            label: (string) $switch->hostname,
            switchId: $switch->id,
        );
    }

    /**
     * Yazma kilidinin anahtarı.
     *
     * Envanterdeki cihaz için kimliği, dış cihaz için adresi kullanılır:
     * aynı cihaza iki yoldan aynı anda yazılmasın. Biçim SwitchWriteLock'un
     * kendi ürettiğiyle BİREBİR aynı olmalı — ayrışırsa aynı switch iki
     * farklı kilit altında görünür ve kilit hiçbir şeyi korumaz.
     */
    public function lockKey(): string
    {
        return $this->switchId === null
            ? "netconf:{$this->host}:{$this->port}:write"
            : "switch:{$this->switchId}:write";
    }
}
