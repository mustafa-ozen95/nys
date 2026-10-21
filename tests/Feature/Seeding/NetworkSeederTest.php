<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use App\Enums\CredentialProtocol;
use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;
use App\Models\NetworkSwitch;
use Database\Seeders\LocalInventory;
use Database\Seeders\NetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NetworkSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Envanter DEPODA DURMAZ; testler kendi dosyalarını kurar.
     *
     * Gerçek dosyaya bakılsaydı test, koştuğu makinenin envanterine göre
     * salınırdı — kimsenin makinesinde aynı sonucu vermezdi. Burada kaynak
     * geçici bir dizine bağlanır ve içeriği testin kendisi yazar.
     */
    private string $dizin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/nys-envanter-'.bin2hex(random_bytes(6));
        mkdir($this->dizin);

        LocalInventory::dizinAyarla($this->dizin);
    }

    protected function tearDown(): void
    {
        LocalInventory::dizinAyarla(null);

        array_map(unlink(...), glob($this->dizin.'/*') ?: []);
        @rmdir($this->dizin);

        parent::tearDown();
    }

    /** Testin kullanacağı envanteri yazar. */
    private function envanter(): void
    {
        file_put_contents($this->dizin.'/inventory.php', <<<'PHP'
            <?php

            use App\Enums\SwitchLocation;
            use App\Enums\SwitchRole;

            return [
                'switchler' => [
                    [
                        'hostname' => 'sw-core',
                        'management_ip' => '192.168.10.11',
                        'uplink_ip' => '192.168.20.1',
                        'role' => SwitchRole::Core,
                        'location' => SwitchLocation::Plus,
                        'username' => 'admin',
                        'password' => 'ornek-parola-16',
                        'community' => 'ornek-community',
                    ],
                    [
                        'hostname' => 'sw-erisim',
                        'management_ip' => '192.168.10.12',
                        'uplink_ip' => '192.168.20.5',
                        'role' => SwitchRole::Access,
                        'location' => SwitchLocation::Plus,
                        'username' => 'admin',
                        'password' => 'ornek-parola-16',
                        'community' => 'ornek-community',
                    ],
                ],
            ];
            PHP);
    }

    /**
     * VERİ YOKSA HİÇBİR ŞEY YAZILMAZ.
     *
     * Envanter depodan çıkarıldı; depoyu klonlayan bir makinede yerel dosya
     * olmaz. Seeder o durumda uydurma kayıt üretmemeli — üretse, yeni bir
     * kuruluma ait olmayan switch'ler bırakırdı.
     */
    public function test_dosya_yokken_hicbir_sey_yazmaz(): void
    {
        $this->seed(NetworkSeeder::class);

        $this->assertSame(0, NetworkSwitch::count());
    }

    /** Dosya varsa envanter ONDAN kurulur. */
    public function test_envanteri_yerel_dosyadan_kurar(): void
    {
        $this->envanter();

        $this->seed(NetworkSeeder::class);

        $this->assertSame(2, NetworkSwitch::count());

        $core = NetworkSwitch::where('management_ip', '192.168.10.11')->sole();
        $this->assertSame('sw-core', $core->hostname);
        $this->assertSame(SwitchRole::Core, $core->role);
        $this->assertSame(SwitchLocation::Plus, $core->location);
        $this->assertSame('192.168.20.1', $core->uplink_ip);
    }

    /**
     * Envanterde OLMAYAN cihaz envantere GİRMEZ.
     *
     * Panelden uzak tutulması gereken bir cihaz envantere sızarsa argümansız
     * eşitleme komutları onu da hedef alır ve o cihaza bağlanılır. Seeder
     * yalnızca kendisine verileni yazmalı.
     */
    public function test_verilmeyen_cihaz_envantere_girmez(): void
    {
        $this->envanter();

        $this->seed(NetworkSeeder::class);

        $this->assertDatabaseMissing('switches', ['management_ip' => '198.18.30.39']);
        $this->assertDatabaseMissing('switches', ['hostname' => 'sw-test-1']);
    }

    public function test_her_switch_ssh_snmp_ve_netconf_kimligi_alir(): void
    {
        $this->envanter();

        $this->seed(NetworkSeeder::class);

        $this->assertSame(2, NetworkSwitch::count());

        foreach (NetworkSwitch::with('credentials')->get() as $switch) {
            $this->assertEqualsCanonicalizing(
                [CredentialProtocol::Ssh, CredentialProtocol::SnmpV2c, CredentialProtocol::Netconf],
                $switch->credentials->pluck('protocol')->all(),
                "{$switch->hostname} kimlikleri eksik",
            );

            $this->assertSame(830, $switch->netconfCredential->port);
        }
    }

    public function test_olcum_alanlari_bos_birakilir(): void
    {
        $this->envanter();

        $this->seed(NetworkSeeder::class);

        $this->assertSame(2, NetworkSwitch::count());

        foreach (NetworkSwitch::all() as $switch) {
            $this->assertNull($switch->model);
            $this->assertNull($switch->serial_number);
            $this->assertNull($switch->junos_version);
            $this->assertNull($switch->zabbix_host_id);
        }
    }

    public function test_tekrar_calistirildiginda_kayit_cogaltmaz(): void
    {
        $this->envanter();

        $this->seed(NetworkSeeder::class);
        $this->seed(NetworkSeeder::class);

        $this->assertSame(2, NetworkSwitch::count());
        $this->assertSame(6, NetworkSwitch::with('credentials')->get()->sum(
            fn (NetworkSwitch $switch): int => $switch->credentials->count(),
        ));
    }
}
