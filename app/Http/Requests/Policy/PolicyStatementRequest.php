<?php

declare(strict_types=1);

namespace App\Http\Requests\Policy;

use App\Models\SwitchPolicyStatement;
use Illuminate\Validation\Rule;

/**
 * Yeni politika: ad, İLK TERİM ve politikanın VARSAYILAN DAVRANIŞI.
 *
 * Terim zorunludur çünkü terimsiz politika cihazda sessizce yok sayılıyor:
 * "mgd: statement has no contents; ignored" (gerçek cihazda doğrulandı).
 * Panel, cihazda karşılığı oluşmayacak bir kayıt yaratmamalı.
 *
 * Varsayılan davranış (politikanın kendi from/then'i) BAŞTAN sorulur:
 * "term A ... then accept; then reject;" bütün BGP süzgeçlerinin standart
 * biçimidir ve iki ayrı commit gerektirmemeli.
 */
class PolicyStatementRequest extends PolicyTermRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'default_match' => $this->normalizeConditions('default_match'),
            'default_then' => $this->normalizeActions('default_then'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),

            // Terim adı "name" olduğu için politika adı ayrı alandır.
            'statement_name' => [
                'required', 'string', 'max:63',
                'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::unique(SwitchPolicyStatement::class, 'name')
                    ->where('switch_id', $this->route('switch')->id),
            ],

            ...$this->conditionRules('default_match'),
            ...$this->actionRules('default_then'),
        ];
    }

    public function statementName(): string
    {
        return $this->string('statement_name')->toString();
    }

    /**
     * Politikanın kendi from/then'i; yeni kayıtta korunacak ham satır yoktur.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaultsAttributes(null);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'statement_name' => 'politika adı',
            ...$this->conditionAttributeNames('default_match', 'varsayılan koşul'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'statement_name.unique' => 'Bu switchte aynı adlı bir politika zaten var.',
            'default_match.prefix_lists.*.exists' => 'Bu prefix list cihazda tanımlı değil.',
            'default_match.as_paths.*.exists' => 'Bu as-path cihazda tanımlı değil.',
            'default_then.communities.*.name.exists' => 'Bu community cihazda tanımlı değil.',
        ];
    }
}
