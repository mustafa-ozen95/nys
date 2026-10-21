<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\SwitchPrefixList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchPrefixListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique(SwitchPrefixList::class, 'name')
                    ->where('switch_id', $this->route('switch')->id)
                    ->ignore($this->route('prefixList')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'liste adı'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Bu switchte aynı adlı bir prefix liste zaten var.'];
    }
}
