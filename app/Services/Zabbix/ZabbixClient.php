<?php

declare(strict_types=1);

namespace App\Services\Zabbix;

use App\Exceptions\ZabbixApiException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Zabbix JSON-RPC istemcisi.
 *
 * Tek giriş noktası call(); istek biçimi ve hata çevirisi başka yerde
 * tekrarlanmaz. try/catch yok: ağ hataları ve API hataları exception olarak
 * yukarı çıkar, kuyruk ve Exception Handler devralır.
 */
class ZabbixClient
{
    private const ENDPOINT = '/api_jsonrpc.php';

    public function __construct(private Factory $http) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<int|string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $payload = $this->request()->post(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ])->json();

        if (! is_array($payload)) {
            throw new ZabbixApiException("Zabbix beklenmeyen bir yanıt döndürdü: {$method}");
        }

        if (isset($payload['error'])) {
            throw ZabbixApiException::fromResponse($payload['error']);
        }

        return $payload['result'] ?? [];
    }

    public function isConfigured(): bool
    {
        return filled(config('zabbix.url')) && filled(config('zabbix.api_token'));
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('zabbix.url'), '/'))
            ->withToken((string) config('zabbix.api_token'))
            ->timeout((int) config('zabbix.timeout'))
            ->retry((int) config('zabbix.retry_times'), (int) config('zabbix.retry_sleep'))
            ->asJson()
            ->throw();
    }
}
