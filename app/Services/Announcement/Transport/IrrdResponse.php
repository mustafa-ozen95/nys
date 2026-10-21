<?php

declare(strict_types=1);

namespace App\Services\Announcement\Transport;

/**
 * IRRd yanıt akışının çözümlenmesi.
 *
 * Soketten AYRI durur: çerçeveleme kuralı burada, tek başına sınanabilir.
 * (Aynı ayrım NETCONF tarafında da var — bkz. Services/Netconf/Framing.)
 *
 *     A<n>\n<n bayt veri>C\n   başarılı, veri var
 *     D\n                      anahtar bulunamadı
 *     F <mesaj>\n              sorgu hatası
 *
 * Uzunluk önekine UYULUR, ayırıcı aranmaz: RPSL gövdesi "C" ile başlayan
 * satırlar (`country:`, `changed:`) içerir ve satır arayan bir okuyucu
 * nesnenin ortasında biterdi.
 */
final class IrrdResponse
{
    /**
     * Yanıt akışını sorgu SIRASINA göre böler.
     *
     * @param  array<int, string>  $queries
     * @return array<string, ?string> Kayıt yoksa null
     *
     * @throws IrrdException
     */
    public static function split(string $stream, array $queries): array
    {
        $objects = [];
        $offset = 0;

        foreach ($queries as $query) {
            $header = self::line($stream, $offset);

            $objects[$query] = match ($header[0] ?? '') {
                'A' => self::payload($stream, $offset, (int) substr($header, 1)),
                'D' => null,
                default => throw new IrrdException("IRR sunucusu sorguyu reddetti: {$header}"),
            };
        }

        return $objects;
    }

    /**
     * Sıradaki satırı okur ve imleci satır sonunun ötesine taşır.
     */
    private static function line(string $stream, int &$offset): string
    {
        $break = strpos($stream, "\n", $offset);

        if ($break === false) {
            throw new IrrdException('IRR yanıtı eksik kaldı.');
        }

        $line = rtrim(substr($stream, $offset, $break - $offset), "\r");
        $offset = $break + 1;

        return $line;
    }

    /**
     * Uzunluğu bildirilen gövdeyi okur ve sonlandırıcı satırı atlar.
     */
    private static function payload(string $stream, int &$offset, int $length): string
    {
        if ($offset + $length > strlen($stream)) {
            throw new IrrdException('IRR yanıtı bildirilen uzunluktan kısa geldi.');
        }

        $payload = substr($stream, $offset, $length);
        $offset += $length;

        self::line($stream, $offset);

        return $payload;
    }
}
