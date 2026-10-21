<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NetworkSwitch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceOperationFilterRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['failed', 'warning', 'ok'])],
            'operation' => ['nullable', 'string', 'max:24'],
            // Cihaz uuid ile süzülür; sıralı birincil anahtar dışarı çıkmaz.
            'switch' => ['nullable', 'uuid', Rule::exists(NetworkSwitch::class, 'uuid')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only(['q', 'status', 'operation', 'switch', 'from', 'to']);
    }
}
