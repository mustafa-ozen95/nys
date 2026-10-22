<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\ActivityChannel;
use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\SnmpIdentityWriter;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Cihaza yazma yolu. Sahte taşıma kullanılır; gerçek cihaza bağlanılmaz.
 */
final class SnmpIdentityWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_cihaza_yazilir_ve_commit_onaylanir(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->successfulFlow());

        $result = app(SnmpIdentityWriter::class)->rename($switch, 'test1');

        $this->assertTrue($result['committed']);
        $this->assertStringContainsString('test1', $result['diff']);

        $written = implode("\n", $transport->written);

        /*
         * Sıra güvenliğin kendisi: KİLİT, adayın temiz olduğunun kontrolü,
         * yükleme, fark, kontrol, geri alınabilir commit; ardından ayrı
         * oturumda onay.
         *
         * Özel aday (private) KULLANILMAZ: Junos onun içinde "commit
         * confirmed"i kabul etmiyor (test4'te ölçüldü).
         */
        $this->assertStringContainsString('<lock-configuration/>', $written);
        $this->assertStringNotContainsString('<private/>', $written);
        $this->assertStringContainsString('<unlock-configuration/>', $written);
        $this->assertStringContainsString('<load-configuration action="merge" format="xml">', $written);
        $this->assertStringContainsString('<name>test1</name>', $written);
        $this->assertStringContainsString('compare="rollback" rollback="0"', $written);
        // Ayrı bir "commit check" YAPILMAZ: doğrulamayı commit confirmed
        // zaten yapıyor ve cihazın en pahalı adımı o (ELS'te on saniyeyi
        // aşabilir).
        $this->assertStringNotContainsString('<check/>', $written);
        $this->assertStringContainsString('<confirmed/><confirm-timeout>2</confirm-timeout>', $written);

        // İki oturum açıldı ve ikisi de kapandı.
        $this->assertSame(2, $transport->closeCount);
    }

    /**
     * Cihaz zaten istenen durumdaysa commit hiç atılmaz.
     */
    public function test_fark_yoksa_commit_atilmaz(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport([
            $this->hello(),
            $this->ok(),                 // lock-configuration
            $this->diff(''),             // aday temiz
            $this->ok(),                 // load-configuration
            $this->diff(''),             // fark yok
            $this->ok(),                 // unlock-configuration
        ]);

        $result = app(SnmpIdentityWriter::class)->rename($switch, 'test1');

        $this->assertFalse($result['committed']);
        $this->assertSame('', $result['diff']);

        $written = implode("\n", $transport->written);
        $this->assertStringNotContainsString('confirm-timeout', $written);
        $this->assertSame(1, $transport->closeCount);
    }

    /**
     * Junos uyarıları rpc-error olarak döner ama işi durdurmamalı.
     */
    public function test_cihaz_uyarisi_isi_durdurmaz(): void
    {
        $switch = $this->switch();
        $flow = $this->successfulFlow();
        $flow[1] = $this->warning('uncommitted changes will be discarded on exit');
        $this->fakeTransport($flow);

        $result = app(SnmpIdentityWriter::class)->rename($switch, 'test1');

        $this->assertTrue($result['committed']);
        $this->assertContains('uncommitted changes will be discarded on exit', $result['warnings']);
    }

    /**
     * Paylaşılan adayda BAŞKASININ bekleyen değişikliği varsa yazma
     * REDDEDİLİR.
     *
     * Kilit yeni değişiklik girmesini engeller ama zaten duranı kaldırmaz;
     * commit atılsaydı panelin hiç görmediği, kullanıcının onaylamadığı
     * satırlar da yayına girerdi. "private" aday bunu doğal olarak
     * sağlıyordu — kilide geçince koruma açıkça yazılmak zorunda.
     */
    public function test_adayda_bekleyen_degisiklik_varsa_yazilmaz(): void
    {
        $switch = $this->switch();

        $transport = $this->fakeTransport([
            $this->hello(),
            $this->ok(),                                  // lock-configuration
            $this->diff('[edit system]\n+  host-name baskasi;'),  // BAŞKASININ değişikliği
            $this->ok(),                                  // unlock-configuration
        ]);

        try {
            app(SnmpIdentityWriter::class)->rename($switch, 'test1');
            $this->fail('Kirli adaya yazılmamalıydı.');
        } catch (NetconfException $exception) {
            $this->assertStringContainsString('kaydedilmemiş', $exception->getMessage());
        }

        $written = implode("\n", $transport->written);

        $this->assertStringNotContainsString('<load-configuration', $written, 'Kirli adaya yükleme yapıldı.');
        $this->assertStringNotContainsString('confirm-timeout', $written, 'Kirli adayda commit atıldı.');
        $this->assertStringContainsString('<unlock-configuration/>', $written, 'Kilit bırakılmadı.');
    }

    public function test_cihaz_hatasi_istisnaya_cevrilir(): void
    {
        $switch = $this->switch();
        $this->fakeTransport([
            $this->hello(),
            $this->ok(),                 // lock-configuration
            $this->diff(''),             // aday temiz
            $this->error('syntax error: snmp'),
            $this->ok(),                 // unlock-configuration
        ]);

        $this->expectException(NetconfException::class);

        app(SnmpIdentityWriter::class)->rename($switch, 'test1');
    }

    public function test_panelden_yazma_yetkisi_olan_kullanici_yazabilir(): void
    {
        $switch = $this->switch();
        $this->fakeTransport($this->successfulFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.snmp-identity', $switch), ['name' => 'test1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'configured',
            'channel' => ActivityChannel::Config->value,
            'subject_id' => $switch->id,
        ]);
    }

    /**
     * "update" envanteri düzenler; cihaza yazmaz. Bu ayrım kaybolursa kaydı
     * düzeltebilen herkes cihazın kendisini değiştirebilir.
     */
    public function test_duzenleme_yetkisi_cihaza_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->successfulFlow());

        $this->actingAs($this->operator(['view', 'update']))
            ->put(route('switches.snmp-identity', $switch), ['name' => 'test1'])
            ->assertForbidden();

        $this->assertNull($transport->connection);
    }

    public function test_gecersiz_ad_cihaza_gitmez(): void
    {
        $switch = $this->switch();
        $transport = $this->fakeTransport($this->successfulFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.snmp-identity', $switch), ['name' => 'kotu ad; commit'])
            ->assertSessionHasErrors('name');

        $this->assertNull($transport->connection);
    }

    /**
     * NETCONF kimliği yoksa yazma yolu yoktur.
     */
    public function test_netconf_kimligi_olmayan_switche_yazilamaz(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.196']);
        $this->fakeTransport($this->successfulFlow());

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.snmp-identity', $switch), ['name' => 'test2'])
            ->assertForbidden();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'jn32-test',
            'management_ip' => '198.18.20.195',
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    /**
     * @param  list<string>  $actions
     */
    private function operator(array $actions): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', $actions);

        /*
         * Cihaz nesnelerinin (port, VLAN, route...) kendi yetkileri var;
         * switch yetkisi onları kapsamıyor. "configure" cihazın kendi
         * ayarlarıdır ve testin niyeti "cihaza yazabilen kullanıcı"dır;
         * "update" ise envanter kaydını düzenlemektir ve cihaza yazdırmaz.
         */
        in_array('configure', $actions, true)
            ? $this->grantDeviceWrite($user)
            : $this->grantDeviceView($user);

        return $user->fresh();
    }

    /**
     * Oturum 1: hello, open, load, fark, check, commit confirmed, close.
     * Oturum 2: hello, open, commit, close.
     *
     * @return list<string>
     */
    private function successfulFlow(): array
    {
        return [
            $this->hello(),
            $this->ok(),                 // lock-configuration
            $this->diff(''),             // aday temiz mi (yükleme ÖNCESİ)
            $this->ok(),                 // load-configuration
            $this->diff("[edit snmp]\n-  name sw1.Juniper-EX3200;\n+  name test1;"),
            $this->ok(),                 // commit confirmed
            $this->ok(),                 // unlock-configuration
            $this->hello(),              // onay oturumu
            $this->ok(),                 // lock-configuration
            $this->ok(),                 // commit
            $this->ok(),                 // unlock-configuration
        ];
    }

    /**
     * @param  list<string>  $responses
     */
    private function fakeTransport(array $responses): FakeNetconfTransport
    {
        $transport = new FakeNetconfTransport($responses);
        $this->app->instance(NetconfTransport::class, $transport);

        return $transport;
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }

    private function ok(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><ok/></rpc-reply>'."\n]]>]]>";
    }

    private function diff(string $text): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration-information>'
            .'<configuration-output>'.htmlspecialchars($text, ENT_XML1).'</configuration-output>'
            .'</configuration-information></rpc-reply>'."\n]]>]]>";
    }

    private function warning(string $message): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><rpc-error>'
            .'<error-severity>warning</error-severity><error-message>'.$message.'</error-message>'
            .'</rpc-error></rpc-reply>'."\n]]>]]>";
    }

    private function error(string $message): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><rpc-error>'
            .'<error-severity>error</error-severity><error-message>'.$message.'</error-message>'
            .'</rpc-error></rpc-reply>'."\n]]>]]>";
    }
}
