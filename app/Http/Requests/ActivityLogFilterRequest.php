<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ActivityChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Etkinlik kaydı filtreleri.
 *
 * Filtre değerleri de doğrulanır: serbest metin sorguya geçmez.
 */
class ActivityLogFilterRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', Rule::enum(ActivityChannel::class)],
            /* Sıralı birincil anahtar dışarı çıkmaz: süzgeç uuid taşır. */
            'user' => ['nullable', 'uuid', 'exists:users,uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'arama',
            'channel' => 'kanal',
            'user' => 'kullanıcı',
            'from' => 'başlangıç tarihi',
            'to' => 'bitiş tarihi',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'q' => $this->validated('q'),
            'channel' => $this->validated('channel'),
            'user' => $this->validated('user'),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
        ];
    }
}
