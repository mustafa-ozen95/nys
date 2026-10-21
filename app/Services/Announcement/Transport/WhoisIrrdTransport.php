<?php

declare(strict_types=1);

namespace App\Services\Announcement\Transport;

/**
 * IRRd'nin whois protokolü (port 43), TOPLU kipte.
 *
 * Sıradan `whois <prefix>` her sorgu için yeni bir TCP+banner turu ister ve
 * on prefix on tur eder. IRRd bunun yerine kalıcı bağlantı sunar: `!!` ile
 * açılır, komutlar ard arda yazılır, `!q` ile kapatılır. Ölçüm: beş prefix
 * TEK turda yaklaşık 0,6 saniye.
 *
 * Yanıt çerçevelemesi IrrdResponse'ta çözülür; burada yalnızca bayt taşınır.
 *
 * `!r<prefix>` TAM EŞLEŞMEDİR; kapsayan blokları getirmez. Bu kasıtlıdır:
 * 192.0.2.0/24 sorulduğunda AS64498'in 192.0.0.0/21 nesnesi de dönseydi
 * başkasının toplayıcı kaydı bizim prefiximizin yetkisi sayılırdı.
 */
final class WhoisIrrdTransport implements IrrdTransport
{
    private const READ_CHUNK = 8192;

    /**
     * @param  array<int, string>  $prefixes
     * @return array<string, ?string>
     */
    public function routeObjects(array $prefixes): array
    {
        if ($prefixes === []) {
            return [];
        }

        $host = (string) config('announcements.authorization.irr.host');
        $port = (int) config('announcements.authorization.irr.port');
        $timeout = (int) config('announcements.authorization.irr.timeout');

        $stream = @stream_socket_client("tcp://{$host}:{$port}", $code, $message, $timeout);

        if ($stream === false) {
            throw new IrrdException("{$host}:{$port} bağlantısı kurulamadı: {$message} ({$code})");
        }

        try {
            stream_set_timeout($stream, $timeout);

            $this->send($stream, $prefixes);

            return IrrdResponse::split($this->receive($stream, $timeout), $prefixes);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<int, string>  $prefixes
     */
    private function send($stream, array $prefixes): void
    {
        $commands = ['!!'];

        foreach ($prefixes as $prefix) {
            $commands[] = "!r{$prefix}";
        }

        $commands[] = '!q';

        if (@fwrite($stream, implode("\n", $commands)."\n") === false) {
            throw new IrrdException('IRR sunucusuna sorgu yazılamadı.');
        }
    }

    /**
     * @param  resource  $stream
     */
    private function receive($stream, int $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        $buffer = '';

        while (! feof($stream)) {
            $chunk = fread($stream, self::READ_CHUNK);

            if ($chunk === false || $chunk === '') {
                /*
                 * Sunucu `!q` sonrası kapatmadıysa okuma boş döner. Süre
                 * dolmuşsa elimizdekiyle devam edilir: yarım yanıt
                 * IrrdResponse'ta istisnaya, orada da "sorgulanamadı"ya
                 * dönüşür — eksik veri sessizce onay sayılmaz.
                 */
                if (stream_get_meta_data($stream)['timed_out'] || microtime(true) >= $deadline) {
                    break;
                }

                continue;
            }

            $buffer .= $chunk;
        }

        if ($buffer === '') {
            throw new IrrdException('IRR sunucusu boş yanıt döndürdü.');
        }

        return $buffer;
    }
}
