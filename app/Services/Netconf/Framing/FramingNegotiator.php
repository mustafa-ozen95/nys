<?php

declare(strict_types=1);

namespace App\Services\Netconf\Framing;

/**
 * Cihazın ve istemcinin bildirdiği yeteneklerden ortak en yüksek çerçeveleme
 * sürümünü seçer.
 *
 * Yeni bir sürüm desteklenecekse MessageFraming uygulayan sınıf yazılır,
 * self::SUPPORTED listesinin başına eklenir ve config/netconf.php'deki
 * yetenek listesine kimliği yazılır. Oturum kodu değişmez.
 */
final class FramingNegotiator
{
    /**
     * En yeniden en eskiye. İlk uyan seçilir.
     *
     * @var list<class-string<MessageFraming>>
     */
    private const SUPPORTED = [
        ChunkedFraming::class,
        EndOfMessageFraming::class,
    ];

    /**
     * @param  list<string>  $deviceCapabilities  Cihazın hello mesajından
     * @param  list<string>  $clientCapabilities  config/netconf.php'den
     */
    public function negotiate(array $deviceCapabilities, array $clientCapabilities): MessageFraming
    {
        foreach (self::SUPPORTED as $class) {
            /** @var MessageFraming $framing */
            $framing = new $class;
            $capability = $framing->capability();

            if (in_array($capability, $deviceCapabilities, true) && in_array($capability, $clientCapabilities, true)) {
                return $framing;
            }
        }

        // Cihaz hiçbir base yeteneği bildirmediyse en eskisi varsayılır:
        // RFC 6242 öncesi davranış budur ve eski JunOS'lar bu şekilde çalışır.
        return new EndOfMessageFraming;
    }
}
