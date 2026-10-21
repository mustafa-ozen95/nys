<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\MailLocale;
use App\Models\AnnouncementRecipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRecipientRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique(AnnouncementRecipient::class, 'email')->ignore($this->route('recipient')),
            ],
            'locale' => ['required', Rule::enum(MailLocale::class)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'ad',
            'email' => 'e-posta',
            'locale' => 'dil',
            'is_active' => 'durum',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientAttributes(): array
    {
        return $this->safe()->only(['label', 'email', 'locale']) + [
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
