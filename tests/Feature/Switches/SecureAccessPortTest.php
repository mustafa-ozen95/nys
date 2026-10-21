<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchSecureInterface;
use App\Models\SwitchSecureVlan;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * DHCP snooping ve port güvenliği panelden yönetilir.
 *
 * ethernet-switching-options altındaki bu düğüm cihazda olduğu gibi
 * panelde de yönetilir. Ölçülen tek biçim
 * "vlan all { examine-dhcp; }" (test1, commit check); geri kalanı belgeye
 * göre yazıldı ve cihazda doğrulanmayı bekliyor.
 */
final class SecureAccessPortTest extends TestCase
{
    use RefreshDatabase;

    public function test_vlan_korumasi_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'all',
                'examine_dhcp' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<ethernet-switching-options><secure-access-port>', $payload);
        $this->assertStringContainsString('<vlan replace="replace"><name>all</name><examine-dhcp/>', $payload);

        // Kapatılan koruma açıkça silinir; "yazmamak" cihazda kalmasına yol açardı.
        /*
         * Kapatılan bayrak SİLME MARKÖRÜYLE değil, hiç yazılmayarak kalkar:
         * düğüm "replace" ile yeniden kuruluyor ve cihaz iki değerli tek
         * ifadede silme markörünü reddediyor (test1'de ölçüldü).
         */
        $this->assertStringNotContainsString('<arp-inspection', $payload);
    }

    /**
     * Tanımsız VLAN adı cihazda commit'i düşürür; panel önce söyler.
     */
    public function test_tanimsiz_vlan_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'yok-boyle-vlan',
                'examine_dhcp' => '1',
            ])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection, 'Reddedilen istekte cihaza bağlanılmamalı.');
    }

    public function test_tanimli_vlan_kabul_edilir(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'v30', 'vlan_tag' => 30]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'v30',
                'examine_dhcp' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<name>v30</name>', $device->payload());
    }

    /**
     * IP kaynak koruması snooping tablosuna dayanır; tablo boşken o VLAN'daki
     * statik adresli her cihazın trafiği düşer. Koruma değil KESİNTİ olurdu.
     */
    public function test_snooping_kapaliyken_kaynak_korumasi_acilamaz(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'all',
                'examine_dhcp' => '0',
                'ip_source_guard' => '1',
            ])
            ->assertSessionHasErrors('ip_source_guard');

        $this->assertNull($device->connection);
    }

    /**
     * Güven durumu ÜÇ değerlidir ve üçü de AYRI bir yazma üretir.
     *
     * Üç durum üç ayrı testtir: Laravel yönlendiricisi controller nesnesini
     * rota üzerinde önbelleğe alıyor, dolayısıyla aynı test içinde ikinci bir
     * istek İLK isteğin sahte cihazına yazar ve test sessizce yanlış şeyi
     * ölçerdi.
     */
    public function test_guvenilir_arayuz_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'dhcp_trusted' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<dhcp-trusted/>', $device->payload());
        // "dhcp-trusted" ile "no-dhcp-trusted" TEK ifadedir; ikisi birden
        // gönderilirse cihaz sözdizimi hatası veriyor (ölçüldü).
        $this->assertStringNotContainsString('no-dhcp-trusted', $device->payload());
    }

    public function test_guvenilmez_arayuz_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'dhcp_trusted' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<no-dhcp-trusted/>', $device->payload());
        $this->assertStringNotContainsString('<dhcp-trusted/>', $device->payload());
    }

    /**
     * "Cihazın varsayılanı" seçildiğinde İKİ satır da silinir: panelin
     * yazmadığı bir satırın cihazda kalması, ekranın yalan söylemesi olurdu.
     */
    public function test_varsayilan_secildiginde_iki_satir_da_silinir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('<dhcp-trusted/>', $device->payload());
        // "dhcp-trusted" ile "no-dhcp-trusted" TEK ifadedir; ikisi birden
        // gönderilirse cihaz sözdizimi hatası veriyor (ölçüldü).
        $this->assertStringNotContainsString('no-dhcp-trusted', $device->payload());
    }

    /**
     * "shutdown" eylemi portu HİZMET DIŞI bırakır; kullanıcı bunu onay
     * penceresinde önce, sonuç mesajında bir kez daha görür.
     */
    public function test_mac_siniri_ve_eylemi_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'mac_limit' => 4,
                'mac_limit_action' => 'shutdown',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<mac-limit><limit>4</limit><action>shutdown</action></mac-limit>',
            $device->payload(),
        );

        $this->assertStringContainsString('port KAPANACAK', (string) session('status'));
    }

    /**
     * Eylem sınıra asılıdır; sınırsız gönderilen eylem sessizce düşerdi.
     */
    public function test_eylem_tek_basina_verilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'all',
                'examine_dhcp' => '1',
                'mac_move_action' => 'drop',
            ])
            ->assertSessionHasErrors('mac_move_action');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta bu kuralı vermiyor.
     */
    public function test_anahtarlama_disi_porta_verilemez(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/9', PortMode::Routed);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'dhcp_trusted' => '1',
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz arayüz adını BİRİMLİ tutar (ge-0/0/5.0) ve geri okumada öyle
     * kaydedilir. Birim soyulmasaydı cihazdan okunmuş bir satır bir daha
     * düzenlenemezdi: doğrulama "böyle bir port yok" derdi.
     */
    public function test_birimli_arayuz_adi_porta_baglanir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5.0',
                'mac_limit' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<name>ge-0/0/5.0</name>', $device->payload());
    }

    public function test_izinli_mac_listesi_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'allowed_macs' => "00:11:22:33:44:55\n00:11:22:33:44:66",
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<allowed-mac>00:11:22:33:44:55</allowed-mac>', $payload);
        $this->assertStringContainsString('<allowed-mac>00:11:22:33:44:66</allowed-mac>', $payload);
    }

    public function test_gecersiz_mac_reddedilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'allowed_macs' => 'kediler',
            ])
            ->assertSessionHasErrors('allowed_macs');

        $this->assertNull($device->connection);
    }

    /**
     * Panelin göstermediği satır düzenlemede KAYBOLMAZ.
     */
    public function test_korunan_ham_satir_geri_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');

        $rule = SwitchSecureInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/5',
            'residual_xml' => ['<static-ip><name>198.18.20.20</name></static-ip>'],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.secure-access.interface.update', [$switch, $rule]), ['mac_limit' => 3])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<limit>3</limit>', $payload);
        $this->assertStringContainsString(
            '<static-ip><name>198.18.20.20</name></static-ip>',
            $payload,
            'Korunan satır geri yazılmadı.',
        );
    }

    /**
     * Korunan satır ile silme markörü ÇAKIŞMAZ.
     *
     * Okuyucu ayrıştıramadığı bir sınırı ham hâliyle koruyor. Yazma yolu aynı
     * belgede hem "<mac-limit delete>" hem de korunan "<mac-limit ...>"
     * gönderseydi cihaza çelişkili bir istek giderdi.
     */
    public function test_korunan_satir_silme_markoruyle_catismaz(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');

        $rule = SwitchSecureInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/5',
            'mac_limit' => null,
            'residual_xml' => ['<mac-limit><uydurma-alan>4</uydurma-alan></mac-limit>'],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.secure-access.interface.update', [$switch, $rule]), ['dhcp_trusted' => '0'])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringNotContainsString('<mac-limit delete="delete"/>', $payload);
        $this->assertStringContainsString('<mac-limit><uydurma-alan>4</uydurma-alan></mac-limit>', $payload);
    }

    public function test_kural_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $rule = SwitchSecureVlan::factory()->for($switch, 'switch')->create(['vlan_name' => 'all']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.secure-access.vlan.destroy', [$switch, $rule]))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<vlan delete="delete"><name>all</name></vlan>', $device->payload());
    }

    /**
     * ELS'te tek bir "secure-access-port" düğümü YOKTUR; ayarlar üç ayrı
     * hiyerarşiye dağılmıştır ve üçü de test4'te (EX2300-24T, 24.4R2-S4)
     * ölçüldü. Panel artık oraya yazar.
     *
     * ANLAM DA TERSİNE DÖNER: ELS öncesinde "examine-dhcp" snooping'i AÇAR;
     * ELS'te düğümün varlığı zaten açar, kapatmak için "no-dhcp-snooping"
     * yazılır. Bunu görmeden yazılan bir panel, kapalı demek isterken açık
     * bırakırdı.
     */
    public function test_els_cihazda_vlan_ayari_dhcp_security_altina_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 30]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'ofis',
                'examine_dhcp' => '1',
                'arp_inspection' => '1',
                'mac_move_limit' => 5,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<forwarding-options><dhcp-security replace="replace">', $payload);
        $this->assertStringContainsString('<arp-inspection/>', $payload);
        $this->assertStringContainsString('<switch-options replace="replace"><mac-move-limit>5</mac-move-limit>', $payload);

        // Snooping AÇIK istendi: kapatma satırı yazılmamalı.
        $this->assertStringNotContainsString('<no-dhcp-snooping/>', $payload);
        $this->assertStringNotContainsString('secure-access-port', $payload);
    }

    public function test_els_cihazda_snooping_kapatmak_no_dhcp_snooping_yazar(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 30]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'ofis',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertStringContainsString('<no-dhcp-snooping/>', $device->payload());
    }

    /**
     * ELS "all" adını ayrılmış sözcük sayıyor: "Invalid vlan name specified.
     * Reserved word cannot be used" (test4 / EX2300 24.4R2-S4). ELS ÖNCESİ
     * kabul ediyor (test1'de ölçüldü) — kararı lehçe verir, panel değil.
     */
    public function test_els_cihazda_all_vlan_adi_reddedilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'all',
                'examine_dhcp' => '1',
            ])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection, 'Reddedilen istek cihaza gitmemeliydi.');
    }

    public function test_els_oncesi_cihazda_all_vlan_adi_kabul_edilir(): void
    {
        $switch = $this->switch();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'all',
                'examine_dhcp' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertStringContainsString('<name>all</name>', $device->payload());
    }

    /**
     * Reddedilen kısayol ELS ekranında hiç sunulmaz.
     */
    public function test_els_ekraninda_all_secenekleri_sunulmaz(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $this->actingAs($this->operator())
            ->get(route('switches.secure-access', $switch))
            ->assertOk()
            ->assertDontSee('Bütün VLAN’lar (all)')
            ->assertDontSee('Bütün portlar (all)');
    }

    public function test_els_cihazda_mac_siniri_switch_options_altina_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        $this->port($switch, 'ge-0/0/5');

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'mac_limit' => 3,
                'mac_limit_action' => 'shutdown',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<switch-options><interface replace="replace">', $payload);
        $this->assertStringContainsString(
            '<interface-mac-limit><limit>3</limit><packet-action>shutdown</packet-action></interface-mac-limit>',
            $payload,
        );
    }

    /**
     * ELS'te DHCP güveni ve izinli MAC listesi VLAN KAPSAMINDA yaşar
     * (dhcp-security group / switch-options static-mac). Panel bu yüzden
     * hangi VLAN olduğunu SORAR; VLAN'sız yazılamaz.
     */
    public function test_els_cihazda_vlan_kapsamli_alanlar_vlan_ister(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        $this->port($switch, 'ge-0/0/5');

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'dhcp_trusted' => '1',
            ])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection);
    }

    /**
     * VLAN verilince üç yere birden yazılır: switch geneli MAC sınırı,
     * VLAN'daki güven grubu ve VLAN'daki statik MAC listesi.
     *
     * Grup ARAYÜZ BAŞINA açılır; ortak bir gruba yazılsaydı bir portun
     * güvenini kaldırmak aynı gruptaki diğer portları da düşürürdü.
     */
    public function test_els_cihazda_vlan_verilince_uc_yere_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        $this->port($switch, 'ge-0/0/5');
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 30]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'vlan_name' => 'ofis',
                'dhcp_trusted' => '1',
                'mac_limit' => 3,
                'mac_limit_action' => 'shutdown',
                'allowed_macs' => '00:11:22:33:44:55',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<switch-options><interface replace="replace">', $payload);
        $this->assertStringContainsString('<interface-mac-limit><limit>3</limit>', $payload);

        $this->assertStringContainsString(
            '<group replace="replace"><name>guven-ge-0-0-5</name><overrides><trusted/></overrides>',
            $payload,
        );
        $this->assertStringContainsString('<static-mac><name>00:11:22:33:44:55</name></static-mac>', $payload);
    }

    /**
     * ELS'te "açıkça güvenilmez" diye bir işaret YOKTUR (no-dhcp-trusted
     * sözdizimi hatası veriyor): güven ya gruptadır ya değildir. Güven
     * kaldırılınca grup SİLİNİR.
     */
    public function test_els_cihazda_guven_kaldirilinca_grup_silinir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        $this->port($switch, 'ge-0/0/5');
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 30]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'vlan_name' => 'ofis',
                'dhcp_trusted' => '0',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<group delete="delete"><name>guven-ge-0-0-5</name></group>', $payload);
        $this->assertStringNotContainsString('no-dhcp-trusted', $payload);
    }

    public function test_els_cihazda_olmayan_vlan_reddedilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        $this->port($switch, 'ge-0/0/5');

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.secure-access.interface.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'vlan_name' => 'olmayan',
                'dhcp_trusted' => '1',
            ])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection);
    }

    public function test_ekran_kurallari_listeler(): void
    {
        $switch = $this->switch();
        SwitchSecureVlan::factory()->for($switch, 'switch')->create(['vlan_name' => 'all']);
        SwitchSecureInterface::factory()->for($switch, 'switch')->create(['interface_name' => 'ge-0/0/0.0']);

        $this->actingAs($this->operator())
            ->get(route('switches.secure-access', $switch))
            ->assertOk()
            ->assertSee('ge-0/0/0.0')
            ->assertSee('DHCP snooping');
    }

    public function test_yetkisiz_kullanici_yazamaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->post(route('switches.secure-access.vlan.store', $switch), [
                'vlan_name' => 'all',
                'examine_dhcp' => '1',
            ])
            ->assertForbidden();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit ethernet-switching-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function port(NetworkSwitch $switch, string $name, PortMode $mode = PortMode::Access): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'port_mode' => $mode,
        ]);
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
