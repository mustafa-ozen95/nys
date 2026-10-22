<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use SimpleXMLElement;

/**
 * Junos yapılandırma XML'i için küçük okuma/yazma yardımcıları.
 *
 * Aynı beş satır (local-name() xpath'i, trim, boşsa null) her okuyucuda
 * yeniden yazılıyordu. Ad alanı sorunu yüzünden düz `$node->child` çalışmaz:
 * Junos yapılandırması "http://xml.juniper.net/xnm/1.1/xnm" ad alanındadır ve
 * SimpleXML çocukları ancak local-name() ile bulunur.
 */
final class XmlNode
{
    /**
     * @return list<SimpleXMLElement>
     */
    /**
     * Nesne cihazda DEVRE DIŞI mı?
     *
     * Junos "deactivate" satırını, elemanın gövdesinde değil ÖZNİTELİĞİNDE
     * taşır: <group inactive="inactive">. Yapılandırma olduğu yerde durur ama
     * çalışmaz. Öznitelik okunmazsa panel devre dışı bir BGP oturumunu ETKİN
     * gösterir — canlı ağ durumu hakkında yanlış bilgi.
     *
     * (test1 üzerinde ölçüldü: get-configuration özniteliği aynen döndürüyor,
     * merge yazma onu koruyor.)
     */
    public static function isInactive(SimpleXMLElement $node): bool
    {
        return trim((string) ($node->attributes()['inactive'] ?? '')) !== '';
    }

    public static function children(SimpleXMLElement $node, string $element): array
    {
        return array_values($node->xpath('./*[local-name()="'.$element.'"]') ?: []);
    }

    public static function first(SimpleXMLElement $node, string $element): ?SimpleXMLElement
    {
        return self::children($node, $element)[0] ?? null;
    }

    public static function has(SimpleXMLElement $node, string $element): bool
    {
        return self::children($node, $element) !== [];
    }

    public static function text(SimpleXMLElement $node, string $element): ?string
    {
        $found = self::first($node, $element);
        $value = $found === null ? '' : trim((string) $found);

        return $value === '' ? null : $value;
    }

    public static function number(SimpleXMLElement $node, string $element): ?int
    {
        $value = self::text($node, $element);

        return $value === null || ! ctype_digit($value) ? null : (int) $value;
    }

    /**
     * Aynı adlı bütün çocukların metinleri (Junos "leaf-list").
     *
     * @return list<string>
     */
    public static function texts(SimpleXMLElement $node, string $element): array
    {
        $values = [];

        foreach (self::children($node, $element) as $child) {
            $value = trim((string) $child);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Kapsayıcı çocukların içindeki tek alan: <prefix-list><name>X</name>.
     *
     * @return list<string>
     */
    public static function childTexts(SimpleXMLElement $node, string $element, string $inner): array
    {
        $values = [];

        foreach (self::children($node, $element) as $child) {
            $value = self::text($child, $inner);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Verilen boş elemanlardan hangisi varsa onun adı: <orlonger/> gibi.
     *
     * @param  list<string>  $names
     */
    public static function firstFlag(SimpleXMLElement $node, array $names): ?string
    {
        foreach ($names as $name) {
            if (self::has($node, $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     */
    public static function leaves(string $element, array $values): string
    {
        $body = '';

        foreach ($values as $value) {
            $text = trim((string) $value);

            if ($text !== '') {
                $body .= "<{$element}>".self::escape($text)."</{$element}>";
            }
        }

        return $body;
    }

    public static function wrap(string $element, string $body): string
    {
        return "<{$element}>{$body}</{$element}>";
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
