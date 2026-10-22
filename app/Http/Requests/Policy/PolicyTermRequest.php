<?php

declare(strict_types=1);

namespace App\Http\Requests\Policy;

use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Http\Requests\Policy\Concerns\BuildsPolicyConditions;
use App\Models\SwitchCommunity;
use App\Models\SwitchPolicyTerm;
use App\Models\SwitchPrefixList;
use App\Support\Netconf\PolicyThen;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bir politika terimi.
 *
 * Terim artık Junos'un pratik yüzeyinin tamamını taşır: prefix-list,
 * prefix-list-filter, route-filter, protocol, as-path, community, neighbor,
 * next-hop, interface, rib, route-type, tag, family, level, area koşulları;
 * local-preference, metric, metric2, preference, community add/set/delete,
 * as-path-prepend, as-path-expand, next-hop, origin, tag, load-balance,
 * install-nexthop, forwarding-class, damping eylemleri.
 *
 * Cihazda doğrulanmış kısıtlar:
 *
 * - prefix-list-filter eşleşme türü ZORUNLUDUR (exact | longer | orlonger);
 *   türsüz filtre "syntax error, expecting <flag-value>" ile reddedilir.
 * - route-filter'da upto/through/prefix-length-range bir DEĞER ister,
 *   exact/longer/orlonger istemez.
 * - Atıf yapılan prefix list, community ve as-path cihazda ÖNCEDEN tanımlı
 *   olmalıdır; tanımsız ada atıf commit'i düşürür.
 * - Terimin bir sonucu olmalı: hiçbir eylem taşımayan terim cihazda hiçbir
 *   şey yapmaz.
 */
class PolicyTermRequest extends FormRequest
{
    use BuildsPolicyConditions;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Boş seçenek diziye boş dize olarak gelir ve exists doğrulamasına
     * takılırdı. Virgülle yazılan listeler de burada diziye çevrilir.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'match_prefix_lists' => array_values(array_filter(
                (array) $this->input('match_prefix_lists', []),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            )),
            'match' => $this->normalizeConditions('match'),
            'then' => $this->normalizeActions('then'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switchId = $this->route('switch')->id;

        return [
            // Junos tanımlayıcısı; terim adı politika içinde kimliktir.
            'name' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/'],

            /*
             * Terimin kaçıncı sırada değerlendirileceği. Junos'ta İLK EŞLEŞEN
             * terim kazanır; sıra bir tercih değil, politikanın kendisidir.
             * Boş bırakılırsa sona eklenir.
             */
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],

            'match_prefix_lists' => ['array'],
            'match_prefix_lists.*' => [
                'string',
                Rule::exists(SwitchPrefixList::class, 'name')->where('switch_id', $switchId),
            ],

            // Prefix list seçildiyse eşleşme türü zorunlu, seçilmediyse yasak.
            'match_type' => [
                $this->hasPrefixLists() ? 'required' : 'prohibited',
                Rule::enum(PrefixMatchType::class),
            ],

            'action' => ['nullable', Rule::enum(PolicyAction::class)],

            'community_delete' => [
                'nullable', 'string',
                Rule::exists(SwitchCommunity::class, 'name')->where('switch_id', $switchId),
            ],

            ...$this->conditionRules('match'),
            ...$this->actionRules('then'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardConditions($validator, 'match');
            $this->guardActions($validator, 'then');

            /*
             * Sonucu olmayan terim cihazda hiçbir şey yapmaz. Sonlandırıcı
             * eylem ayrı alandadır; thenActions() onu taşımaz, o yüzden ikisi
             * birlikte sorulur.
             */
            if (! filled($this->input('action')) && PolicyThen::isEmpty($this->thenActions())) {
                $validator->errors()->add('action', 'Terimin bir sonucu olmalı: eylem seçin ya da bir değer değiştirin.');
            }
        });
    }

    /**
     * Terimin ekleneceği sıra; verilmezse listenin sonu.
     */
    public function positionOr(int $default): int
    {
        return filled($this->input('position')) ? $this->integer('position') : $default;
    }

    private function hasPrefixLists(): bool
    {
        return (array) $this->input('match_prefix_lists', []) !== [];
    }

    /**
     * PolicyStatementConfigurator'ın beklediği biçim.
     *
     * Korunan ham satırlar düzenlenen terimin KENDİSİNDEN gelir: panelin
     * göstermediği satırlar formdan geçmez ama kaybolmaları da gerekmez.
     *
     * @return array<string, mixed>
     */
    public function termAttributes(): array
    {
        $term = $this->route('term');
        $term = $term instanceof SwitchPolicyTerm ? $term : null;
        $action = $this->string('action')->toString();

        return [
            'name' => $this->string('name')->toString(),
            'match_prefix_lists' => array_values((array) $this->input('match_prefix_lists', [])),
            'match_type' => $this->hasPrefixLists()
                ? PrefixMatchType::from($this->string('match_type')->toString())
                : null,
            'match_conditions' => $this->conditionsFrom('match', $term?->match_conditions['residual'] ?? null),
            'action' => $action === '' ? null : PolicyAction::from($action),
            'community_delete' => $this->string('community_delete')->toString() ?: null,
            'then_actions' => $this->thenActions($term),
            'residual_xml' => $term?->residual_xml,
        ];
    }

    /**
     * Terimde sonlandırıcı eylem KENDİ alanındadır; then_actions yalnızca
     * değiştirici eylemleri taşır.
     *
     * @return array<string, mixed>
     */
    private function thenActions(?SwitchPolicyTerm $term = null): array
    {
        $actions = $this->actionsFrom('then', $term?->then_actions['residual'] ?? null);

        /*
         * "Silinecek community" ayrı bir kısayol alandır; aynı community
         * aşağıdaki işlem listesinden de seçilmiş olabilir. Bir kez yazılır,
         * yoksa cihaza aynı satır iki kere giderdi.
         */
        $community = $this->string('community_delete')->toString();
        $already = in_array($community, array_column($actions['communities'], 'name'), true);

        if ($community !== '' && ! $already) {
            $actions['communities'][] = ['operation' => 'delete', 'name' => $community];
        }

        $actions['action'] = null;

        return $actions;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'terim adı',
            'match_prefix_lists' => 'prefix listeler',
            'match_type' => 'eşleşme türü',
            'position' => 'sıra',
            'action' => 'sonuç',
            'community_delete' => 'silinecek community',
            ...$this->conditionAttributeNames('match', 'koşul'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'match_prefix_lists.*.exists' => 'Bu prefix list cihazda tanımlı değil.',
            'community_delete.exists' => 'Bu community cihazda tanımlı değil.',
            'match_type.required' => 'Prefix list seçildiğinde eşleşme türü zorunludur.',
            'match_type.prohibited' => 'Eşleşme türü yalnızca prefix list seçiliyken verilebilir.',
            'match.prefix_lists.*.exists' => 'Bu prefix list cihazda tanımlı değil.',
            'match.as_paths.*.exists' => 'Bu as-path cihazda tanımlı değil.',
            'match.communities.*.exists' => 'Bu community cihazda tanımlı değil.',
            'then.communities.*.name.exists' => 'Bu community cihazda tanımlı değil.',
        ];
    }
}
