<?php

declare(strict_types=1);

namespace App\Http\Requests\Remote;

use App\Models\NetworkSwitch;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uzak oturum isteği.
 *
 * Hedef türü serbest metin değildir: yalnızca burada tanımlı iki tür kabul
 * edilir, böylece istemci istediği modeli çözdüremez.
 */
class RemoteSessionRequest extends FormRequest
{
    private const SUBJECTS = [
        'switch' => NetworkSwitch::class,
        'server' => Server::class,
    ];

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
            'type' => ['required', 'string', Rule::in(array_keys(self::SUBJECTS))],
            // Hedef uuid ile gelir; sıralı birincil anahtar dışarı çıkmaz.
            'id' => ['required', 'uuid'],
        ];
    }

    public function subject(): Model
    {
        /** @var class-string<Model> $model */
        $model = self::SUBJECTS[$this->validated('type')];

        return $model::query()->where('uuid', $this->validated('id'))->firstOrFail();
    }
}
