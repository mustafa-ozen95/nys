<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\Transport\NetconfTransport;
use Closure;

/**
 * Bir switch için NETCONF oturumu açar, verilen işi çalıştırır ve oturumu her
 * durumda kapatır.
 *
 * Cihazda her oturum bir mgd süreci fork'lar; oturum açık kalırsa RE belleği
 * dolar. Bu yüzden oturum yalnızca burada açılır ve finally ile kapatılır;
 * çağıran taraf kapatmayı unutamaz.
 *
 * Kullanım — bütün RPC'ler TEK oturumda:
 *
 *     $client->run($switch, function (NetconfSession $session) {
 *         $routes = $session->rpc('<get-route-information/>');
 *         $config = $session->rpc('<get-configuration/>');
 *
 *         return [$routes, $config];
 *     });
 */
final class NetconfClient
{
    public function __construct(
        private readonly NetconfTransport $transport,
        private readonly FramingNegotiator $negotiator,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(NetconfSession): TReturn  $callback
     * @return TReturn
     *
     * @throws NetconfException
     */
    public function run(NetworkSwitch $switch, Closure $callback): mixed
    {
        return $this->runOn(NetconfTarget::fromSwitch($switch), $callback);
    }

    /**
     * Envanterde olmayan bir cihazda da çalışır: hedef yalnızca adres ve
     * kimlik taşır.
     *
     * @template TReturn
     *
     * @param  Closure(NetconfSession): TReturn  $callback
     * @return TReturn
     *
     * @throws NetconfException
     */
    public function runOn(NetconfTarget $target, Closure $callback): mixed
    {
        $this->transport->open(
            $target->host,
            $target->port,
            $target->username,
            $target->password,
            (int) config('netconf.timeout'),
        );

        $session = new NetconfSession($this->transport, $this->negotiator);

        try {
            $session->handshake(config('netconf.capabilities'));

            return $callback($session);
        } finally {
            // Hata da olsa oturum kapanır; cihazda mgd asılı kalmamalı.
            $session->close();
        }
    }
}
