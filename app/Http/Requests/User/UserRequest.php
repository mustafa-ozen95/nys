<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
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
        $user = $this->route('user');
        $isUpdate = $user instanceof User;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($isUpdate ? $user->id : null),
            ],
            // Düzenlemede boş bırakılırsa parola değişmez.
            'password' => [
                $isUpdate ? 'nullable' : 'required',
                'confirmed',
                Password::min(10)->letters()->numbers(),
            ],
            // Rolü yalnızca yönetici belirler. "Kullanıcıları düzenle" yetkisi
            // verilen biri, aksi halde kendini yönetici yapabilirdi.
            /*
             * Rol bir KAYITTIR; formdan uuid gelir. Sıralı birincil anahtar
             * dışarı çıkmaz (bkz. CLAUDE.md, "Nesne erişimi (IDOR)").
             */
            'role' => [
                Rule::requiredIf(fn (): bool => $this->roleAssignable()),
                Rule::prohibitedIf(fn (): bool => ! $this->roleAssignable()),
                Rule::exists('roles', 'uuid'),
            ],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'ad soyad',
            'email' => 'e-posta adresi',
            'password' => 'parola',
            'role' => 'rol',
            'status' => 'durum',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role.prohibited' => 'Kullanıcı rolünü yalnızca yönetici değiştirebilir.'];
    }

    private function roleAssignable(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Parola boşsa alan hiç yazılmaz; mevcut parola korunur.
     * Rol yönetici olmayan tarafından hiç yazılmaz.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        $attributes = $this->safe()->only(['name', 'email', 'status', 'password']);

        if ($this->roleAssignable()) {
            $attributes['role_id'] = Role::query()
                ->where('uuid', $this->string('role')->toString())
                ->value('id');
        } elseif (! $this->isMethod('PUT') && ! $this->isMethod('PATCH')) {
            // Yönetici olmayan biri kullanıcı oluşturuyorsa VARSAYILAN rol verilir.
            $attributes['role_id'] = Role::default()?->id;
        }

        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }

        return $attributes;
    }
}
