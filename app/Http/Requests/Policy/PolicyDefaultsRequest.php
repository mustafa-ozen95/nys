<?php

declare(strict_types=1);

namespace App\Http\Requests\Policy;

use App\Http\Requests\Policy\Concerns\BuildsPolicyConditions;
use App\Models\SwitchPolicyStatement;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Politikanın VARSAYILAN DAVRANIŞI: terimlerden sonra gelen from/then.
 *
 * Junos'ta bu satır politikanın son sözüdür:
 *
 *   term A { from { prefix-list-filter LISTE orlonger; } then accept; }
 *   then reject;
 *
 * Bütün BGP süzgeçlerinin standart biçimi budur. Panel bu satırı okumazsa
 * politika her güncellendiğinde silinir ve süzgeç "gerisini reddet"ten
 * "gerisini de al"a döner (test1'de commit check ile ölçüldü).
 */
class PolicyDefaultsRequest extends FormRequest
{
    use BuildsPolicyConditions;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
            ...$this->conditionRules('default_match'),
            ...$this->actionRules('default_then'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardConditions($validator, 'default_match');
            $this->guardActions($validator, 'default_then');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $statement = $this->route('policyStatement');

        return $this->defaultsAttributes($statement instanceof SwitchPolicyStatement ? $statement : null);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->conditionAttributeNames('default_match', 'varsayılan koşul');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_match.prefix_lists.*.exists' => 'Bu prefix list cihazda tanımlı değil.',
            'default_match.as_paths.*.exists' => 'Bu as-path cihazda tanımlı değil.',
            'default_match.communities.*.exists' => 'Bu community cihazda tanımlı değil.',
            'default_then.communities.*.name.exists' => 'Bu community cihazda tanımlı değil.',
        ];
    }
}
