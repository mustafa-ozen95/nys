<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use App\Enums\CommunityOperation;
use App\Enums\PolicyAction;
use App\Enums\PolicyValueOperation;
use SimpleXMLElement;

/**
 * Bir politikanın ya da teriminin "then" eylemleri.
 *
 * İki tür eylem vardır ve karıştırılmamalıdır:
 *
 * - SONLANDIRICI eylem (accept, reject, next policy, next term): zinciri
 *   bitirir ya da devreder. PolicyAction'da durur.
 * - DEĞİŞTİRİCİ eylemler (local-preference, metric, community add,
 *   as-path-prepend...): rotayı değiştirir, değerlendirme devam eder.
 *
 * Politikanın KENDİ "then"i, terimlerin hepsi düştüğünde uygulanan
 * VARSAYILAN EYLEMDİR:
 *
 *   term A { from { prefix-list-filter LISTE orlonger; } then accept; }
 *   then reject;
 *
 * Bütün BGP süzgeçlerinin standart biçimi budur. Panel bu satırı okumazsa
 * güncellemede siler ve politika "gerisini reddet"ten "gerisini de al"a
 * döner (test1'de ölçüldü).
 *
 * Okuma kuralı PolicyMatch ile aynıdır: çözümlenemeyen çocuk artığa düşer ve
 * geri yazılırken ham hâliyle basılır.
 */
final class PolicyThen
{
    /**
     * İşlemli sayısal eylemler: Junos bunlarda add/subtract kabul eder.
     *
     * @var array<string, string>
     */
    private const VALUES = [
        'local_preference' => 'local-preference',
        'metric' => 'metric',
        'metric2' => 'metric2',
        'preference' => 'preference',
    ];

    /**
     * Düz metin eylemler: dizi anahtarı => Junos elemanı.
     *
     * @var array<string, string>
     */
    private const TEXTS = [
        'as_path_prepend' => 'as-path-prepend',
        'as_path_expand' => 'as-path-expand',
        'next_hop' => 'next-hop',
        'origin' => 'origin',
        'tag' => 'tag',
        'load_balance' => 'load-balance',
        'install_nexthop' => 'install-nexthop',
        'forwarding_class' => 'forwarding-class',
        'damping' => 'damping',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return array_merge(
            ['action' => null, 'communities' => [], 'residual' => []],
            array_fill_keys(array_keys(self::VALUES), null),
            array_fill_keys(array_keys(self::TEXTS), null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(?SimpleXMLElement $then): array
    {
        $actions = self::blank();

        if ($then === null) {
            return $actions;
        }

        $known = [];

        $actions['action'] = self::action($then, $known)?->value;

        foreach (self::VALUES as $key => $element) {
            $value = self::readValue($then, $element);

            if ($value !== null) {
                $actions[$key] = $value;
                $known[] = $element;
            }
        }

        foreach (self::TEXTS as $key => $element) {
            $value = XmlNode::text($then, $element);

            if ($value !== null) {
                $actions[$key] = $value;
                $known[] = $element;
            }
        }

        $actions['communities'] = self::readCommunities($then, $known);
        $actions['residual'] = ResidualXml::collect($then, $known);

        return $actions;
    }

    /**
     * @param  array<string, mixed>|null  $actions
     */
    public static function emit(?array $actions): string
    {
        if ($actions === null) {
            return '';
        }

        $body = '';

        foreach (self::VALUES as $key => $element) {
            $body .= self::emitValue($element, $actions[$key] ?? null);
        }

        foreach (self::TEXTS as $key => $element) {
            $value = trim((string) ($actions[$key] ?? ''));

            if ($value !== '') {
                $body .= "<{$element}>".XmlNode::escape($value)."</{$element}>";
            }
        }

        $body .= self::emitCommunities($actions['communities'] ?? []);
        $body .= ResidualXml::emit($actions['residual'] ?? null);

        // Sonlandırıcı eylem en sona yazılır; okurken de göze böyle çarpar.
        $body .= self::emitAction($actions['action'] ?? null);

        return $body === '' ? '' : "<then>{$body}</then>";
    }

    /**
     * @param  array<string, mixed>|null  $actions
     */
    public static function isEmpty(?array $actions): bool
    {
        return self::emit($actions) === '';
    }

    /**
     * @param  array<string, mixed>|null  $actions
     * @return list<string>
     */
    public static function summary(?array $actions): array
    {
        if ($actions === null) {
            return [];
        }

        $fragments = [];

        foreach (self::VALUES as $key => $element) {
            $value = $actions[$key] ?? null;

            if (is_array($value)) {
                $operation = PolicyValueOperation::tryFrom((string) ($value['operation'] ?? 'set'));
                $extra = $operation === null || $operation === PolicyValueOperation::Set ? '' : $operation->value.' ';
                $fragments[] = "{$element} {$extra}".($value['value'] ?? '');
            }
        }

        foreach ($actions['communities'] ?? [] as $community) {
            $fragments[] = 'community '.($community['operation'] ?? '').' '.($community['name'] ?? '');
        }

        foreach (self::TEXTS as $key => $element) {
            if (filled($actions[$key] ?? null)) {
                $fragments[] = "{$element} {$actions[$key]}";
            }
        }

        foreach (ResidualXml::names($actions['residual'] ?? null) as $name) {
            $fragments[] = $name;
        }

        $action = PolicyAction::tryFrom((string) ($actions['action'] ?? ''));

        if ($action !== null) {
            $fragments[] = $action->label();
        }

        return $fragments;
    }

    /**
     * Sonlandırıcı eylem.
     *
     * Junos'ta "next" bir ARGÜMAN alır (`then next policy;`), yani XML'de
     * <next>policy</next> olur. Panel yalnızca bu biçimi yazar; okuyucu
     * <next-policy/> biçimiyle kalmış satırları da tanır.
     *
     * @param  list<string>  $taninan
     */
    private static function action(SimpleXMLElement $then, array &$known): ?PolicyAction
    {
        $next = XmlNode::text($then, 'next');

        if ($next !== null) {
            $action = match ($next) {
                'policy' => PolicyAction::NextPolicy,
                'term' => PolicyAction::NextTerm,
                default => null,
            };

            // Tanınmayan argüman çözümlenmiş sayılmaz; ham hâliyle korunur.
            if ($action !== null) {
                $known[] = 'next';
            }

            return $action;
        }

        foreach (PolicyAction::cases() as $action) {
            if (XmlNode::has($then, $action->value)) {
                $known[] = $action->value;

                return $action;
            }
        }

        return null;
    }

    private static function emitAction(?string $action): string
    {
        $case = PolicyAction::tryFrom((string) $action);

        if ($case === null) {
            return '';
        }

        $argument = $case->nextArgument();

        return $argument === null ? '<'.$case->value.'/>' : "<next>{$argument}</next>";
    }

    /**
     * "then local-preference [add|subtract] <sayı>".
     *
     * Junos bu eylemleri işleç alabildikleri için kapsayıcı olarak yazar:
     * <local-preference><add/><local-preference>10</local-preference></...>.
     * Bazı sürümler düz metin de döndürebildiği için okuyucu ikisini de
     * tanır ve gördüğü biçimi (nested) saklayıp geri yazarken korur.
     *
     * @return array{operation: string, value: int, nested: bool}|null
     */
    private static function readValue(SimpleXMLElement $then, string $element): ?array
    {
        $node = XmlNode::first($then, $element);

        if ($node === null) {
            return null;
        }

        $inner = XmlNode::number($node, $element);

        if ($inner !== null) {
            $operation = XmlNode::firstFlag($node, [
                PolicyValueOperation::Add->value,
                PolicyValueOperation::Subtract->value,
            ]) ?? PolicyValueOperation::Set->value;

            return ['operation' => $operation, 'value' => $inner, 'nested' => true];
        }

        $flat = trim((string) $node);

        if ($flat === '' || ! ctype_digit($flat)) {
            // Çözümlenemedi; artığa düşsün ve ham hâliyle korunsun.
            return null;
        }

        return ['operation' => PolicyValueOperation::Set->value, 'value' => (int) $flat, 'nested' => false];
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private static function emitValue(string $element, ?array $value): string
    {
        if ($value === null || ! filled($value['value'] ?? null)) {
            return '';
        }

        $number = (int) $value['value'];
        $nested = (bool) ($value['nested'] ?? true);

        if (! $nested) {
            return "<{$element}>{$number}</{$element}>";
        }

        $operation = PolicyValueOperation::tryFrom((string) ($value['operation'] ?? 'set')) ?? PolicyValueOperation::Set;
        $mark = $operation->element();
        $inner = ($mark === null ? '' : "<{$mark}/>")."<{$element}>{$number}</{$element}>";

        return XmlNode::wrap($element, $inner);
    }

    /**
     * "then community (add|set|delete) <ad>".
     *
     * Biçim cihazdan okunanın aynısıdır (ör. omurga-1):
     * <community><delete/><community-name>topluluk-a</community-name></community>
     *
     * @param  list<string>  $taninan
     * @return list<array{operation: string, name: string}>
     */
    private static function readCommunities(SimpleXMLElement $then, array &$known): array
    {
        $nodes = XmlNode::children($then, 'community');
        $communities = [];
        $parsed = 0;

        foreach ($nodes as $node) {
            $operation = XmlNode::firstFlag($node, array_column(CommunityOperation::cases(), 'value'));
            $names = XmlNode::texts($node, 'community-name');

            if ($operation === null || $names === []) {
                continue;
            }

            $parsed++;

            foreach ($names as $name) {
                $communities[] = ['operation' => $operation, 'name' => $name];
            }
        }

        if ($nodes !== [] && $parsed === count($nodes)) {
            $known[] = 'community';
        }

        return $communities;
    }

    /**
     * @param  list<array<string, mixed>>  $communities
     */
    private static function emitCommunities(array $communities): string
    {
        $body = '';

        foreach ($communities as $community) {
            $name = trim((string) ($community['name'] ?? ''));
            $operation = CommunityOperation::tryFrom((string) ($community['operation'] ?? ''));

            if ($name === '' || $operation === null) {
                continue;
            }

            $body .= XmlNode::wrap(
                'community',
                '<'.$operation->value.'/><community-name>'.XmlNode::escape($name).'</community-name>',
            );
        }

        return $body;
    }
}
