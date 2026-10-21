<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

final class NetconfClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_netconf_kimligiyle_baglanir_ve_sonucu_dondurur(): void
    {
        $switch = $this->switchWithNetconf();
        $transport = $this->fakeTransport([$this->hello(), $this->okReply()]);

        $result = app(NetconfClient::class)->run($switch, fn (NetconfSession $session): string => (string) $session
            ->rpc('<get-software-information/>')
            ->xpath('//*[local-name()="host-name"]')[0]);

        $this->assertSame('sw-test.ornek.test', $result);
        $this->assertSame('198.18.20.195', $transport->connection['host']);
        $this->assertSame(830, $transport->connection['port']);
        $this->assertSame('admin', $transport->connection['username']);
    }

    /**
     * NETCONF kimliği tanımlı değilse SSH kimliğine düşülür — aynı hesap
     * kullanılıyor. Ama SSH portu 22'dir; bağlantı yine 830'a kurulmalı.
     */
    public function test_netconf_kimligi_yoksa_ssh_kimligine_duser_ama_porta_dusmez(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.196']);
        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Ssh,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 22,
        ]);

        $transport = $this->fakeTransport([$this->hello(), $this->okReply()]);

        app(NetconfClient::class)->run($switch, fn (NetconfSession $session) => $session->rpc('<get-software-information/>'));

        $this->assertSame(830, $transport->connection['port']);
        $this->assertSame('admin', $transport->connection['username']);
    }

    /**
     * Cihazda her oturum bir mgd süreci fork'lar. İş patlasa da oturum
     * kapanmalı, yoksa süreçler birikir ve RE belleği dolar.
     */
    public function test_is_hata_verse_de_oturum_kapatilir(): void
    {
        $switch = $this->switchWithNetconf();
        $transport = $this->fakeTransport([$this->hello()]);

        try {
            app(NetconfClient::class)->run($switch, function (): never {
                throw new RuntimeException('iş patladı');
            });
            $this->fail('İstisna bekleniyordu.');
        } catch (RuntimeException $exception) {
            $this->assertSame('iş patladı', $exception->getMessage());
        }

        $this->assertSame(1, $transport->closeCount);
        $this->assertFalse($transport->isOpen);
    }

    public function test_kimlik_yoksa_baglanti_denenmez(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.196']);
        $transport = $this->fakeTransport([]);

        $this->expectException(NetconfException::class);

        try {
            app(NetconfClient::class)->run($switch, fn (NetconfSession $session) => $session->rpc('<get-software-information/>'));
        } finally {
            $this->assertNull($transport->connection);
        }
    }

    private function switchWithNetconf(): NetworkSwitch
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
            .'<capability>urn:ietf:params:netconf:base:1.0</capability>'
            .'</capabilities><session-id>1</session-id></hello>'."\n]]>]]>";
    }

    private function okReply(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<software-information><host-name>sw-test.ornek.test</host-name></software-information>'
            .'</rpc-reply>'."\n]]>]]>";
    }
}
