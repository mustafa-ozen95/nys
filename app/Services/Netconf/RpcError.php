<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use SimpleXMLElement;

/**
 * Cihazın döndürdüğü TEK bir rpc-error, yapısı bozulmadan.
 *
 * Önceden bütün hatalar tek dizeye iniyordu ("A | B") ve her hatanın kendi
 * severity'si, yolu, etiketi ve hangi süreçten geldiği kayboluyordu. Oysa
 * sorunu bulan şey tam olarak o alanlar: "hangi satırda" (error-path),
 * "hangi kural" (error-tag) ve "hangi daemon" (source-daemon).
 *
 * Junos alanları RFC 6241'in üstüne kendi eklerini de koyar; hepsi
 * ad-alanından bağımsız okunur.
 */
final class RpcError
{
    public function __construct(
        public readonly string $message,
        public readonly string $severity = 'error',
        public readonly ?string $tag = null,
        public readonly ?string $type = null,
        public readonly ?string $path = null,
        public readonly ?string $daemon = null,
        public readonly ?string $element = null,
    ) {}

    public static function fromXml(SimpleXMLElement $node): self
    {
        return new self(
            message: self::text($node, 'error-message') ?? 'bilinmeyen hata',
            severity: mb_strtolower(self::text($node, 'error-severity') ?? 'error'),
            tag: self::text($node, 'error-tag'),
            type: self::text($node, 'error-type'),
            path: self::text($node, 'error-path'),
            daemon: self::text($node, 'source-daemon'),
            element: self::text($node, 'bad-element'),
        );
    }

    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    /**
     * Kullanıcıya gösterilecek tek satır: mesaj + varsa yeri.
     */
    public function line(): string
    {
        $place = $this->path !== null ? ' ('.trim(preg_replace('/\s+/', ' ', $this->path) ?? '').')' : '';

        return $this->message.$place;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'severity' => $this->severity,
            'tag' => $this->tag,
            'type' => $this->type,
            'path' => $this->path,
            'daemon' => $this->daemon,
            'element' => $this->element,
        ];
    }

    private static function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath('.//*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
