<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zabbix API hatası.
 *
 * Job'lar bunu yakalamaz; kuyruk yeniden dener. AJAX uçlarında Exception
 * Handler 503'e çevirir, böylece grafik alınamadığında sayfanın geri kalanı
 * etkilenmez.
 */
class ZabbixApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $apiCode = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(array $error): self
    {
        $message = trim(($error['message'] ?? 'Zabbix hatası').' '.($error['data'] ?? ''));

        return new self($message, isset($error['code']) ? (int) $error['code'] : null);
    }

    public function render(): Response
    {
        return response()->json([
            'message' => 'İzleme sisteminden veri alınamadı.',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
