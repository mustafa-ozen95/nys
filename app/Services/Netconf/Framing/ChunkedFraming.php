<?php

declare(strict_types=1);

namespace App\Services\Netconf\Framing;

use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;

/**
 * NETCONF base:1.1 çerçevelemesi (RFC 6242, bölüm 4.2).
 *
 * Mesaj uzunluk önekli parçalara bölünür:
 *
 *     \n#<uzunluk>\n<veri>...\n##\n
 *
 * SINIRI UZUNLUK BELİRLER, ayırıcı değil. Fark önemsiz görünür ama değildir:
 * parça verisi "\n##\n" dizisini kendi içinde taşıyabilir. Junos yapılandırma
 * metni tam olarak bunu yapıyor — "## Last changed: ..." satırı her config
 * çıktısının başında var ve panel `load-configuration format="text"` ile
 * kullanıcının yapıştırdığı metni gönderip aynı metni `compare rollback 0`
 * farkında geri okuyor. Ayırıcıya bakan bir okuyucu mesajı orta yerinden
 * keser; sonra da uzunluğa güvenerek yanlış yere atlar.
 *
 * Bu yüzden okuma buradadır: başlık satırı okunur, uzunluk ayrıştırılır ve
 * TAM o kadar bayt tüketilir.
 *
 * JunOS 12.3 bu sürümü bildirmez, yalnızca 1.0 konuşur. ELS cihazlarda
 * (EX4300, QFX) ya da "rfc-compliant" açıkken devreye girer.
 */
final class ChunkedFraming implements MessageFraming
{
    public const DELIMITER = "\n##\n";

    /**
     * Tek parçanın kabul edilebilir en büyük uzunluğu.
     *
     * RFC üst sınırı 4294967295'tir. Bozuk bir başlık okunduğunda taşıma
     * gigabaytlarca bayt beklerdi; sınır o beklemeyi hataya çevirir.
     */
    private const MAX_CHUNK = 16 * 1024 * 1024;

    public function capability(): string
    {
        return 'urn:ietf:params:netconf:base:1.1';
    }

    public function encode(string $xml): string
    {
        $payload = trim($xml);

        return "\n#".strlen($payload)."\n".$payload.self::DELIMITER;
    }

    public function read(NetconfTransport $transport): string
    {
        $xml = '';

        while (true) {
            $header = $this->header($transport);

            if ($header === '##') {
                return trim($xml);
            }

            $xml .= $transport->readExactly($this->chunkSize($header));
        }
    }

    /**
     * Parça başlığı: "#<uzunluk>" ya da son parça işareti "##".
     *
     * Başlığın önünde satır sonu vardır ve o satır sonu bir önceki parçanın
     * ayırıcısıdır; boş satırlar atlanır.
     *
     * @throws NetconfException
     */
    private function header(NetconfTransport $transport): string
    {
        for ($deneme = 0; $deneme < 3; $deneme++) {
            $line = trim($transport->readUntil("\n"));

            if ($line !== '') {
                return $line;
            }
        }

        throw NetconfException::transport('NETCONF parça başlığı bulunamadı: çerçeve bozuk.', 'framing');
    }

    /**
     * @throws NetconfException
     */
    private function chunkSize(string $header): int
    {
        $length = substr($header, 1);

        if (! str_starts_with($header, '#') || ! ctype_digit($length) || (int) $length < 1) {
            throw NetconfException::transport("Geçersiz NETCONF parça başlığı: {$header}", 'framing');
        }

        if ((int) $length > self::MAX_CHUNK) {
            throw NetconfException::transport("NETCONF parçası kabul edilemeyecek kadar büyük: {$length} bayt.", 'framing');
        }

        return (int) $length;
    }

    /**
     * Tam bir çerçeveyi bellekten soyar.
     *
     * Okuma yolu read() üzerindendir; bu yöntem elde hazır duran bir
     * çerçeve için (kayıt, test) kalır ve aynı kurala uyar: uzunluğa güvenir.
     */
    public function decode(string $raw): string
    {
        $xml = '';
        $offset = 0;

        while (true) {
            $start = strpos($raw, "\n#", $offset);

            if ($start === false) {
                throw NetconfException::transport('NETCONF parçası bulunamadı: çerçeve bozuk.', 'framing');
            }

            // Son parça işareti: "\n##\n"
            if (str_starts_with(substr($raw, $start, 4), self::DELIMITER)) {
                return trim($xml);
            }

            $lengthEnd = strpos($raw, "\n", $start + 2);

            if ($lengthEnd === false) {
                throw NetconfException::transport('NETCONF parça uzunluğu okunamadı.', 'framing');
            }

            $length = substr($raw, $start + 2, $lengthEnd - $start - 2);

            if (! ctype_digit($length) || (int) $length < 1) {
                throw NetconfException::transport("Geçersiz NETCONF parça uzunluğu: {$length}", 'framing');
            }

            $xml .= substr($raw, $lengthEnd + 1, (int) $length);
            $offset = $lengthEnd + 1 + (int) $length;
        }
    }
}
