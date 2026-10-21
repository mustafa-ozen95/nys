<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\ConnectionProtocol;
use App\Exceptions\RemoteSessionException;
use App\Models\NetworkSwitch;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;

/**
 * Switch veya sunucuyu uzak oturum hedefine çevirir.
 *
 * Eksik kimlik bilgisi burada yakalanır: oturum açma denemesi yapılmadan
 * anlaşılır bir hata verilir.
 */
class TargetResolver
{
    public function resolve(Model $subject): RemoteTarget
    {
        return match (true) {
            $subject instanceof NetworkSwitch => $this->fromNetworkSwitch($subject),
            $subject instanceof Server => $this->fromServer($subject),
            default => throw new RemoteSessionException('Bu kayıt için uzak oturum açılamaz.'),
        };
    }

    private function fromNetworkSwitch(NetworkSwitch $switch): RemoteTarget
    {
        $credential = $switch->sshCredential;

        if ($credential === null || blank($credential->username) || blank($credential->password)) {
            throw new RemoteSessionException("{$switch->hostname} için SSH kimlik bilgisi tanımlı değil.");
        }

        return new RemoteTarget(
            subject: $switch,
            label: $switch->hostname,
            host: $switch->management_ip,
            protocol: ConnectionProtocol::Ssh,
            port: $credential->port,
            username: $credential->username,
            password: $credential->password,
        );
    }

    private function fromServer(Server $server): RemoteTarget
    {
        if (blank($server->connection_username) || blank($server->connection_password)) {
            throw new RemoteSessionException("{$server->short_name} için erişim bilgisi tanımlı değil.");
        }

        return new RemoteTarget(
            subject: $server,
            label: $server->short_name,
            host: $server->ip_address,
            protocol: $server->connection_protocol,
            port: $server->connection_port,
            username: $server->connection_username,
            password: $server->connection_password,
        );
    }
}
