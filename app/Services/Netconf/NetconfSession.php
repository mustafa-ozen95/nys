<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Services\Netconf\Framing\EndOfMessageFraming;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\Framing\MessageFraming;
use App\Services\Netconf\Transport\NetconfTransport;
use SimpleXMLElement;
use Throwable;

/**
 * Açık bir NETCONF oturumu: hello değişimi, RPC gönderimi, kapanış.
 *
 * Oturum kurmak pahalıdır (cihazda mgd fork'lanır), RPC göndermek ucuzdur.
 * Bu yüzden çağıran taraf bir oturum açıp bütün RPC'lerini onun içinde
 * çalıştırmalıdır; her RPC için yeni oturum açılmaz.
 */
final class NetconfSession
{
    private const BASE_NAMESPACE = 'urn:ietf:params:xml:ns:netconf:base:1.0';

    private MessageFraming $framing;

    private int $messageId = 0;

    /** @var list<string> */
    private array $deviceCapabilities = [];

    /** @var list<string> Cihazın döndürdüğü uyarılar (severity: warning) */
    private array $warnings = [];

    /** @var list<RpcError> Uyarılar, yapısı bozulmadan */
    private array $warningDetails = [];

    /** Son gönderilen RPC'nin kök elemanı; hata kaydına "hangi çağrı" yazılır. */
    private ?string $lastRpc = null;

    public function __construct(
        private readonly NetconfTransport $transport,
        private readonly FramingNegotiator $negotiator,
    ) {
        // Hello daima 1.0 biçiminde konuşulur; hangi sürümde anlaşıldığı
        // ancak hello değişiminden sonra bilinebilir (RFC 6242, bölüm 4.1).
        $this->framing = new EndOfMessageFraming;
    }

    /**
     * Cihazın hello'sunu okur, kendi hello'sunu gönderir ve çerçeveleme
     * sürümünü belirler.
     *
     * @param  list<string>  $clientCapabilities
     *
     * @throws NetconfException
     */
    public function handshake(array $clientCapabilities): void
    {
        $hello = new EndOfMessageFraming;

        $greeting = $this->parse($hello->read($this->transport));

        $this->deviceCapabilities = array_values(array_map(
            static fn (SimpleXMLElement $node): string => trim((string) $node),
            $greeting->xpath('//*[local-name()="capability"]') ?: [],
        ));

        $this->transport->write($hello->encode($this->helloMessage($clientCapabilities)));

        $this->framing = $this->negotiator->negotiate($this->deviceCapabilities, $clientCapabilities);
    }

    /**
     * Bir RPC gönderir ve yanıtın gövdesini döndürür.
     *
     * @param  string  $body  <rpc> etiketinin içine konacak XML
     *
     * @throws NetconfException Cihaz rpc-error döndürürse
     */
    public function rpc(string $body): SimpleXMLElement
    {
        $this->messageId++;

        $request = sprintf(
            '<rpc xmlns="%s" message-id="%d">%s</rpc>',
            self::BASE_NAMESPACE,
            $this->messageId,
            $body,
        );

        $this->lastRpc = $this->rpcName($body);

        $this->transport->write($this->framing->encode($request));

        $reply = $this->parse($this->framing->read($this->transport));

        $this->guardAgainstError($reply);

        return $reply;
    }

    /**
     * Oturumu kapatır. Kapatma RPC'si başarısız olsa da taşıma katmanı
     * kapatılır: cihazda mgd süreci asılı kalmamalı.
     */
    public function close(): void
    {
        try {
            $this->transport->write($this->framing->encode(sprintf(
                '<rpc xmlns="%s" message-id="%d"><close-session/></rpc>',
                self::BASE_NAMESPACE,
                ++$this->messageId,
            )));
        } catch (Throwable) {
            // Bağlantı zaten kopmuşsa kapatma mesajı gönderilemez; sorun değil.
        } finally {
            $this->transport->close();
        }
    }

    /**
     * Cihazın bildirdiği yetenekler.
     *
     * @return list<string>
     */
    public function deviceCapabilities(): array
    {
        return $this->deviceCapabilities;
    }

    /**
     * Anlaşılan çerçeveleme sürümü. Hangi cihazla hangi sürümün konuşulduğu
     * loglanabilsin diye açıktır.
     */
    public function framingCapability(): string
    {
        return $this->framing->capability();
    }

    /**
     * Oturum boyunca cihazın döndürdüğü uyarılar. İşi durdurmazlar ama
     * yapılandırma değişikliklerinde kayda geçirilmeleri gerekir.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function helloMessage(array $capabilities): string
    {
        $lines = array_map(
            static fn (string $capability): string => '<capability>'.htmlspecialchars($capability, ENT_XML1).'</capability>',
            $capabilities,
        );

        return sprintf(
            '<hello xmlns="%s"><capabilities>%s</capabilities></hello>',
            self::BASE_NAMESPACE,
            implode('', $lines),
        );
    }

    /**
     * @throws NetconfException
     */
    /**
     * Cihazdan gelen XML GÜVENİLMEZ veridir.
     *
     * Cihaz ele geçirilmiş, taklit edilmiş ya da araya girilmiş olabilir;
     * çözümleyici bunu varsayarak çalışır.
     *
     * DOCTYPE bildirimi REDDEDİLİR. NETCONF yanıtında doküman türü bildirimi
     * hiç bulunmaz (bütün fixture'larda ve gerçek cihaz çıktılarında yok);
     * kabul etmek karşılığında hiçbir şey kazanmadan varlık genişletmesine
     * kapı açardı. Ölçüldü: dış varlıklar zaten yüklenmiyor (XXE yok), ama
     * İÇ varlıklar genişletiliyordu — libxml 10 MB'ta durduruyor, yine de
     * saldırganın belirlediği bir iş yükü.
     *
     * LIBXML_NONET ağ erişimini de kapatır: bir DTD ya da şema referansı
     * çözümleyiciyi dışarı çıkarmamalı.
     */
    private function parse(string $xml): SimpleXMLElement
    {
        if ($xml === '') {
            throw new NetconfException('Cihazdan boş NETCONF yanıtı geldi.');
        }

        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new NetconfException(
                'NETCONF yanıtında doküman türü bildirimi var; böyle bir yanıt kabul edilmez.'
            );
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($element === false) {
            throw new NetconfException('NETCONF yanıtı çözümlenemedi.');
        }

        return $element;
    }

    /**
     * @throws NetconfException
     */
    private function guardAgainstError(SimpleXMLElement $reply): void
    {
        $nodes = $reply->xpath('//*[local-name()="rpc-error"]') ?: [];

        if ($nodes === []) {
            return;
        }

        $fatal = [];

        foreach ($nodes as $node) {
            $error = RpcError::fromXml($node);

            /*
             * Junos uyarıları da rpc-error olarak döner. "configure private"a
             * her girişte gelen "uncommitted changes will be discarded on
             * exit" bunlardan biridir ve işi durdurmamalı; yalnızca severity
             * "error" olanlar istisnaya çevrilir.
             */
            if ($error->isWarning()) {
                $this->warnings[] = $error->message;
                $this->warningDetails[] = $error;

                continue;
            }

            $fatal[] = $error;
        }

        if ($fatal !== []) {
            throw NetconfException::fromRpcErrors($fatal, $this->lastRpc);
        }
    }

    /**
     * Uyarılar, yapısı bozulmadan. Kayıt için; ekran kısa metni kullanır.
     *
     * @return list<RpcError>
     */
    public function warningDetails(): array
    {
        return $this->warningDetails;
    }

    /**
     * Gönderilen RPC'nin kök elemanı — "hangi çağrıda düştü" sorusunun cevabı.
     */
    private function rpcName(string $body): ?string
    {
        return preg_match('/^\s*<\s*([A-Za-z0-9_.:-]+)/', $body, $eslesme) === 1
            ? $eslesme[1]
            : null;
    }
}
