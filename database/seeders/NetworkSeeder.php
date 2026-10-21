<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CredentialProtocol;
use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;
use App\Models\NetworkSwitch;
use Illuminate\Database\Seeder;

/**
 * Switch envanteri: yalnızca yerel envanter dosyasında verilen cihazlar.
 *
 * Buraya uydurma kayıt eklenmez. Ölçüm alanları (durum, cpu, bellek, sıcaklık,
 * uptime, model, seri numarası) bilinçli olarak boş bırakılır; onları eşitleme
 * doldurur.
 *
 * Dosyada olmayan cihaz yazılmaz: argümansız eşitleme komutları envanterdeki
 * her cihazı hedef alır, panelden uzak tutulması gereken bir cihaz envantere
 * sızarsa ona da bağlanılırdı.
 *
 * Erişim parolaları YEREL DOSYADADIR (yerel/envanter.php, .gitignore'da).
 * Veritabanına şifreli yazılır ve hiçbir ekranda basılmazlar.
 */
class NetworkSeeder extends Seeder
{
    /**
     * Envanter YEREL DOSYADAN gelir (yerel/envanter.php): adresler ve
     * parolalar depoda durmaz.
     *
     * @return list<array{hostname: string, management_ip: string, uplink_ip: string, role: SwitchRole, location: SwitchLocation, username: string, password: string, community: string}>
     */
    private function switchler(): array
    {
        /** @var list<array{hostname: string, management_ip: string, uplink_ip: string, role: SwitchRole, location: SwitchLocation, username: string, password: string, community: string}> */
        return YerelEnvanter::al('switchler');
    }

    public function run(): void
    {
        foreach ($this->switchler() as $entry) {
            $switch = NetworkSwitch::updateOrCreate(
                ['management_ip' => $entry['management_ip']],
                [
                    'hostname' => $entry['hostname'],
                    'uplink_ip' => $entry['uplink_ip'],
                    'role' => $entry['role'],
                    'location' => $entry['location'],
                ],
            );

            $switch->credentials()->updateOrCreate(
                ['protocol' => CredentialProtocol::Ssh],
                ['username' => $entry['username'], 'password' => $entry['password'], 'port' => 22],
            );

            $switch->credentials()->updateOrCreate(
                ['protocol' => CredentialProtocol::SnmpV2c],
                ['community' => $entry['community'], 'port' => 161],
            );

            $switch->credentials()->updateOrCreate(
                ['protocol' => CredentialProtocol::Netconf],
                ['username' => $entry['username'], 'password' => $entry['password'], 'port' => 830],
            );
        }
    }
}
