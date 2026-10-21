<?php

declare(strict_types=1);

namespace App\Http\Requests\Policy\Concerns;

use App\Enums\CommunityOperation;
use App\Enums\PolicyAction;
use App\Enums\PolicyValueOperation;
use App\Enums\RouteFilterMatchType;
use App\Models\SwitchAsPath;
use App\Models\SwitchCommunity;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPrefixList;
use App\Support\Netconf\PolicyMatch;
use App\Support\Netconf\PolicyThen;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Politika koşulları ve eylemleri için ortak doğrulama ve dönüştürme.
 *
 * Aynı yapı ÜÇ yerde geçer ve üçü de aynı kuralları uygulamak zorundadır:
 * terim ekleme, terim düzenleme ve politikanın KENDİ varsayılan davranışı.
 * Kurallar tek yerde durmazsa biri diğerinden habersiz gevşer.
 *
 * Cihazın kendi kuralları burada ÖNCEDEN söylenir; commit'e gitmeden.
 */
trait BuildsPolicyConditions
{
    /**
     * Metin listesi olarak girilen koşullar: alan => doğrulama kuralı.
     *
     * Junos bunları "leaf-list" olarak tutar (`from protocol [ bgp ospf ]`);
     * formda tek satıra virgülle yazılır ve burada diziye çevrilir.
     *
     * @var array<string, list<mixed>>
     */
    private const MATCH_LISTS = [
        'protocols' => ['string', 'max:32', 'regex:/^[a-z0-9-]+$/'],
        'neighbors' => ['ip'],
        'next_hops' => ['ip'],
        'interfaces' => ['string', 'max:64', 'regex:/^[A-Za-z0-9\/.:-]+$/'],
        'route_types' => ['string', 'max:16', 'regex:/^[a-z-]+$/'],
        'families' => ['string', 'max:16', 'regex:/^[a-z0-9-]+$/'],
        'areas' => ['string', 'max:32', 'regex:/^[0-9.]+$/'],
        'levels' => ['integer', 'between:1,2'],
        'tags' => ['integer', 'between:0,4294967295'],
        'ribs' => ['string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
    ];

    /**
     * İşleçli sayısal eylemler.
     *
     * @var list<string>
     */
    private const THEN_VALUES = ['local_preference', 'metric', 'metric2', 'preference'];

    /**
     * Düz metin eylemler: alan => doğrulama kuralı.
     *
     * @var array<string, list<mixed>>
     */
    private const THEN_TEXTS = [
        // "65000 65000" — boşlukla ayrılmış AS numaraları.
        'as_path_prepend' => ['string', 'max:128', 'regex:/^[0-9]+( [0-9]+)*$/'],
        'as_path_expand' => ['string', 'max:128', 'regex:/^[0-9]+( [0-9]+)*$/'],
        'next_hop' => ['string', 'max:64'],
        'origin' => ['string', 'max:16', 'regex:/^[a-z]+$/'],
        'tag' => ['integer', 'between:0,4294967295'],
        'load_balance' => ['string', 'max:32', 'regex:/^[a-z-]+$/'],
        'install_nexthop' => ['string', 'max:64'],
        'forwarding_class' => ['string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        'damping' => ['string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
    ];

    /**
     * Virgül/boşlukla yazılan listeleri diziye çevirir.
     *
     * Boş satırlar atılır: boş dize "exists" ve "ip" kurallarına takılırdı.
     *
     * @return array<string, mixed>
     */
    protected function normalizeConditions(string $key): array
    {
        $match = (array) $this->input($key, []);

        foreach (self::MATCH_LISTS as $field => $rules) {
            $match[$field] = $this->splitValues($match[$field] ?? null);
        }

        foreach (['prefix_lists', 'as_paths', 'communities'] as $field) {
            $match[$field] = array_values(array_filter(
                (array) ($match[$field] ?? []),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ));
        }

        // Tamamen boş satırlar formdan da gelir (tekrarlı alanların son satırı).
        $match['route_filters'] = array_values(array_filter(
            (array) ($match['route_filters'] ?? []),
            static fn (mixed $row): bool => is_array($row) && filled($row['address'] ?? null),
        ));

        return $match;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeActions(string $key): array
    {
        $then = (array) $this->input($key, []);

        $then['communities'] = array_values(array_filter(
            (array) ($then['communities'] ?? []),
            static fn (mixed $row): bool => is_array($row) && filled($row['name'] ?? null),
        ));

        return $then;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function conditionRules(string $key): array
    {
        $switchId = $this->route('switch')->id;

        $rules = [
            $key => ['array'],

            /*
             * Atıf yapılan tanımlar cihazda ÖNCEDEN bulunmalıdır; tanımsız
             * ada atıf commit'i düşürür ("referenced but not defined").
             */
            "{$key}.prefix_lists" => ['array', 'max:32'],
            "{$key}.prefix_lists.*" => [
                'string',
                Rule::exists(SwitchPrefixList::class, 'name')->where('switch_id', $switchId),
            ],
            "{$key}.as_paths" => ['array', 'max:32'],
            "{$key}.as_paths.*" => [
                'string',
                Rule::exists(SwitchAsPath::class, 'name')->where('switch_id', $switchId),
            ],
            "{$key}.communities" => ['array', 'max:32'],
            "{$key}.communities.*" => [
                'string',
                Rule::exists(SwitchCommunity::class, 'name')->where('switch_id', $switchId),
            ],

            "{$key}.route_filters" => ['array', 'max:64'],
            "{$key}.route_filters.*.address" => ['required', 'string', 'max:49'],
            "{$key}.route_filters.*.match_type" => ['required', Rule::enum(RouteFilterMatchType::class)],
            "{$key}.route_filters.*.value" => ['nullable', 'string', 'max:32', 'regex:/^[0-9\/.:-]+$/'],
        ];

        foreach (self::MATCH_LISTS as $field => $itemRules) {
            $rules["{$key}.{$field}"] = ['array', 'max:32'];
            $rules["{$key}.{$field}.*"] = $itemRules;
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function actionRules(string $key): array
    {
        $switchId = $this->route('switch')->id;

        $rules = [
            $key => ['array'],
            "{$key}.communities" => ['array', 'max:16'],
            "{$key}.communities.*.operation" => ['required', Rule::enum(CommunityOperation::class)],
            "{$key}.communities.*.name" => [
                'required', 'string',
                Rule::exists(SwitchCommunity::class, 'name')->where('switch_id', $switchId),
            ],
        ];

        foreach (self::THEN_VALUES as $field) {
            $rules["{$key}.{$field}.operation"] = ['nullable', Rule::enum(PolicyValueOperation::class)];
            $rules["{$key}.{$field}.value"] = ['nullable', 'integer', 'between:0,4294967295'];
        }

        foreach (self::THEN_TEXTS as $field => $itemRules) {
            $rules["{$key}.{$field}"] = ['nullable', ...$itemRules];
        }

        return $rules;
    }

    /**
     * Cihazın kendi kuralları. Commit'e gitmeden söylenir.
     */
    protected function guardConditions(Validator $validator, string $key): void
    {
        $match = (array) $this->input($key, []);

        foreach ($match['route_filters'] ?? [] as $index => $filter) {
            $type = RouteFilterMatchType::tryFrom((string) ($filter['match_type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $value = trim((string) ($filter['value'] ?? ''));

            /*
             * "upto", "through" ve "prefix-length-range" bir DEĞER alır;
             * exact/longer/orlonger almaz. Junos ikisini de reddeder.
             */
            if ($type->needsValue() && $value === '') {
                $validator->errors()->add(
                    "{$key}.route_filters.{$index}.value",
                    "“{$type->value}” bir değer ister; örnek: {$type->valueHint()}",
                );
            }

            if (! $type->needsValue() && $value !== '') {
                $validator->errors()->add(
                    "{$key}.route_filters.{$index}.value",
                    "“{$type->value}” değer almaz; değeri boş bırakın.",
                );
            }

            $this->guardFilterFamily($validator, $key, $index, (string) ($filter['address'] ?? ''), $match);
        }
    }

    /**
     * Adres ailesi "from family" ile çelişmemeli: IPv4 route-filter, family
     * inet6 diyen bir terimde hiçbir zaman eşleşmez.
     *
     * @param  array<string, mixed>  $match
     */
    private function guardFilterFamily(
        Validator $validator,
        string $key,
        int|string $index,
        string $address,
        array $match,
    ): void {
        $families = array_map('strval', (array) ($match['families'] ?? []));

        if ($families === [] || $address === '') {
            return;
        }

        $ipv6 = str_contains($address, ':');
        $beklenen = $ipv6 ? 'inet6' : 'inet';

        if (! in_array($beklenen, $families, true) && array_intersect(['inet', 'inet6'], $families) !== []) {
            $validator->errors()->add(
                "{$key}.route_filters.{$index}.address",
                "Adres {$beklenen} ailesinden; terim yalnızca ".implode(', ', $families).' ailesine bakıyor.',
            );
        }
    }

    /**
     * Aynı community hem eklenip hem silinemez: terim kendi kendini bozar.
     */
    protected function guardActions(Validator $validator, string $key): void
    {
        $sayac = [];

        foreach ((array) $this->input("{$key}.communities", []) as $community) {
            $name = (string) ($community['name'] ?? '');

            if ($name !== '') {
                $sayac[$name][] = (string) ($community['operation'] ?? '');
            }
        }

        foreach ($sayac as $name => $islemler) {
            if (count(array_unique($islemler)) > 1) {
                $validator->errors()->add(
                    "{$key}.communities",
                    "“{$name}” community’sine tek terimde birden çok işlem uygulanamaz.",
                );

                return;
            }
        }
    }

    /**
     * Doğrulanmış girdiden PolicyMatch biçimini kurar.
     *
     * Korunan ham satırlar (residual) formdan geçmez; düzenlenen kaydın
     * KENDİSİNDEN gelir ve olduğu gibi taşınır.
     *
     * @param  list<string>|null  $residual
     * @return array<string, mixed>
     */
    protected function conditionsFrom(string $key, ?array $residual = null): array
    {
        $input = (array) $this->input($key, []);
        $match = PolicyMatch::blank();

        foreach (['prefix_lists', 'as_paths', 'communities'] as $field) {
            $match[$field] = array_values((array) ($input[$field] ?? []));
        }

        foreach (array_keys(self::MATCH_LISTS) as $field) {
            $match[$field] = array_values((array) ($input[$field] ?? []));
        }

        foreach ((array) ($input['route_filters'] ?? []) as $filter) {
            $match['route_filters'][] = [
                'address' => trim((string) ($filter['address'] ?? '')),
                'match_type' => (string) ($filter['match_type'] ?? ''),
                'value' => trim((string) ($filter['value'] ?? '')) ?: null,
            ];
        }

        $match['residual'] = $residual ?? [];

        return $match;
    }

    /**
     * @param  list<string>|null  $residual
     * @return array<string, mixed>
     */
    protected function actionsFrom(string $key, ?array $residual = null): array
    {
        $input = (array) $this->input($key, []);
        $then = PolicyThen::blank();

        foreach (self::THEN_VALUES as $field) {
            $value = $input[$field]['value'] ?? null;

            if (filled($value)) {
                $then[$field] = [
                    'operation' => (string) ($input[$field]['operation'] ?? PolicyValueOperation::Set->value),
                    'value' => (int) $value,
                    'nested' => true,
                ];
            }
        }

        foreach (array_keys(self::THEN_TEXTS) as $field) {
            $then[$field] = filled($input[$field] ?? null) ? trim((string) $input[$field]) : null;
        }

        foreach ((array) ($input['communities'] ?? []) as $community) {
            $then['communities'][] = [
                'operation' => (string) ($community['operation'] ?? CommunityOperation::Add->value),
                'name' => (string) $community['name'],
            ];
        }

        $action = (string) ($input['action'] ?? '');
        $then['action'] = PolicyAction::tryFrom($action)?->value;
        $then['residual'] = $residual ?? [];

        return $then;
    }

    /**
     * Politikanın KENDİ from/then'i, yazıcının beklediği biçimde.
     *
     * Korunan ham satırlar düzenlenen kayıttan gelir: panelin göstermediği
     * satırlar formdan geçmez ama kaybolmaları da gerekmez.
     *
     * @return array<string, mixed>
     */
    protected function defaultsAttributes(?SwitchPolicyStatement $statement): array
    {
        return [
            'match' => $this->conditionsFrom('default_match', $statement?->default_match['residual'] ?? null),
            'then' => $this->actionsFrom('default_then', $statement?->default_then['residual'] ?? null),
            'residual_xml' => $statement?->residual_xml,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function conditionAttributeNames(string $key, string $label): array
    {
        $adlar = [
            "{$key}.prefix_lists" => "{$label} · prefix listeler",
            "{$key}.as_paths" => "{$label} · as-path",
            "{$key}.communities" => "{$label} · community",
            "{$key}.route_filters" => "{$label} · route-filter",
        ];

        foreach (array_keys(self::MATCH_LISTS) as $field) {
            $adlar["{$key}.{$field}"] = "{$label} · ".str_replace('_', ' ', $field);
        }

        return $adlar;
    }

    /**
     * @return list<string>
     */
    private function splitValues(mixed $raw): array
    {
        if (is_array($raw)) {
            $parcalar = $raw;
        } else {
            $parcalar = preg_split('/[\s,]+/', (string) $raw) ?: [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $parcalar),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
