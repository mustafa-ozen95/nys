<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrafficHistoryRequest extends FormRequest
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
            // Aralıklar config'te tanımlıdır; buraya serbest değer giremez.
            'range' => ['sometimes', 'string', Rule::in(array_keys(config('zabbix.chart_ranges')))],
        ];
    }

    public function range(): string
    {
        return $this->validated('range', array_key_first(config('zabbix.chart_ranges')));
    }
}
