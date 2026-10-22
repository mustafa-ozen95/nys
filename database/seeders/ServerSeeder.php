<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectionProtocol;
use App\Enums\ServerOs;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Seeder;

/**
 * Sunucu envanteri.
 *
 * Adresler ve erişim parolaları YEREL DOSYADADIR (local/inventory.php,
 * .gitignore'da): depoya erişebilen herkesin görebileceği bir yerde durmazlar.
 * Veritabanına şifreli yazılırlar ve hiçbir ekranda basılmazlar.
 */
class ServerSeeder extends Seeder
{
    /**
     * Tüm sunucular aynı konumda duruyor.
     */
    private const LOCATION = 'Omurga-1';

    /**
     * Linux sunucuların tamamı aynı dağıtımı çalıştırıyor.
     */
    private const LINUX_VERSION = 'Ubuntu 24.04.4 LTS';

    public function run(): void
    {
        $credentials = $this->credentials();

        foreach ($this->servers() as $server) {
            $key = "{$server['ip_address']}:{$server['connection_port']}";

            Server::updateOrCreate(
                ['ip_address' => $server['ip_address'], 'connection_port' => $server['connection_port']],
                $server + array_filter(['connection_password' => $credentials[$key] ?? null]),
            );
        }
    }

    /**
     * Sunucu envanteri YEREL DOSYADAN gelir (local/inventory.php).
     *
     * @return array<int, array<string, mixed>>
     */
    private function servers(): array
    {
        return array_map(
            fn (array $row): array => $row['tur'] === 'linux'
                ? $this->linux($row['kisa'], $row['uzun'], $row['adres'], $row['port'], $row['kullanici'])
                : $this->windows($row['kisa'], $row['uzun'], $row['adres'], $row['port'], $row['kullanici'], $row['surum']),
            LocalInventory::take('sunucular'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function linux(
        string $short,
        string $long,
        string $address,
        int $port,
        string $username,
    ): array {
        return [
            'short_name' => $short,
            'long_name' => $long,
            'ip_address' => $address,
            'os_type' => ServerOs::Linux,
            'os_version' => self::LINUX_VERSION,
            'connection_protocol' => ConnectionProtocol::Ssh,
            'connection_port' => $port,
            'connection_username' => $username,
            'location' => self::LOCATION,
            'status' => ServerStatus::Online,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function windows(
        string $short,
        string $long,
        string $address,
        int $port,
        string $username,
        string $version,
    ): array {
        return [
            'short_name' => $short,
            'long_name' => $long,
            'ip_address' => $address,
            'os_type' => ServerOs::Windows,
            'os_version' => $version,
            'connection_protocol' => ConnectionProtocol::Rdp,
            'connection_port' => $port,
            'connection_username' => $username,
            'location' => self::LOCATION,
            'status' => ServerStatus::Online,
        ];
    }

    /**
     * Erişim parolaları YEREL DOSYADAN gelir (local/inventory.php).
     *
     * @return array<string, string> "adres:port" => parola
     */
    private function credentials(): array
    {
        /** @var array<string, string> */
        return LocalInventory::take('sunucu_parolalari');
    }
}
