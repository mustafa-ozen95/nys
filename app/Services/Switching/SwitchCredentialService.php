<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;

/**
 * Switchin SSH, SNMP ve NETCONF kimlik bilgilerini yazar.
 *
 * Parola ve community alanı boş gelirse mevcut değer korunur; formda gizli
 * tutulan bir sırrın yanlışlıkla silinmesi böylece engellenir.
 *
 * NETCONF kimliği olmayan switch panelden YAPILANDIRILAMAZ: "configure"
 * yetkisi kimlik yoksa verilmez ve yazma düğmeleri hiç görünmez.
 */
class SwitchCredentialService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function sync(NetworkSwitch $switch, array $attributes): void
    {
        $this->syncSsh($switch, $attributes);
        $this->syncSnmp($switch, $attributes);
        $this->syncNetconf($switch, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncSsh(NetworkSwitch $switch, array $attributes): void
    {
        $username = $attributes['ssh_username'] ?? null;
        $password = $attributes['ssh_password'] ?? null;

        if ($username === null && $password === null) {
            return;
        }

        $switch->credentials()->updateOrCreate(
            ['protocol' => CredentialProtocol::Ssh],
            array_filter([
                'username' => $username,
                'password' => $password,
                'port' => $attributes['ssh_port'] ?? 22,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * NETCONF genelde SSH ile aynı hesabı kullanır; alan boş bırakılırsa SSH
     * kimliği 830 portuyla kopyalanır. Ayrı bir hesap gerekiyorsa formda
     * doldurulur.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncNetconf(NetworkSwitch $switch, array $attributes): void
    {
        if (($attributes['netconf_enabled'] ?? false) === false) {
            return;
        }

        $username = $attributes['netconf_username'] ?? $attributes['ssh_username'] ?? null;
        $password = $attributes['netconf_password'] ?? $attributes['ssh_password'] ?? null;

        if ($username === null && $password === null) {
            return;
        }

        $switch->credentials()->updateOrCreate(
            ['protocol' => CredentialProtocol::Netconf],
            array_filter([
                'username' => $username,
                'password' => $password,
                'port' => $attributes['netconf_port'] ?? config('netconf.port'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncSnmp(NetworkSwitch $switch, array $attributes): void
    {
        $community = $attributes['snmp_community'] ?? null;

        if ($community === null) {
            return;
        }

        $switch->credentials()->updateOrCreate(
            ['protocol' => CredentialProtocol::SnmpV2c],
            [
                'community' => $community,
                'port' => $attributes['snmp_port'] ?? 161,
            ],
        );
    }
}
