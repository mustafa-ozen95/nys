<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\BadgeVariant;
use App\Models\Role;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Panel rolü.
 *
 * Rol hazır bir yetki şablonudur; şablon kaynak => aksiyon listesidir ve
 * yalnızca TANIMLI kaynak/aksiyon çiftlerini taşıyabilir. Tanımsız bir satır
 * hiçbir işe yaramayan yetki üretir ve yetki ekranını yanıltır.
 *
 * Yerleşik rollerin slug'ı değişmez: kod, seeder ve testler onlara adıyla
 * atıfta bulunuyor.
 *
 * En az bir yönetici rolü KALMAK ZORUNDA; yoksa paneli kimse yönetemez.
 */
class RoleRequest extends FormRequest
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
        $role = $this->role();

        return [
            /*
             * Slug kod tarafındaki kimliktir. Yerleşik rollerde hiç sorulmaz;
             * yenisinde harf, rakam ve tire kabul edilir.
             */
            'slug' => [
                Rule::requiredIf($role === null),
                Rule::prohibitedIf($role?->is_system === true),
                'string', 'max:40',
                'regex:/^[a-z][a-z0-9-]*$/',
                Rule::unique('roles', 'slug')->ignore($role?->id),
            ],

            'label' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'badge' => ['required', Rule::enum(BadgeVariant::class)],

            'is_admin' => ['boolean'],
            'is_default' => ['boolean'],

            'position' => ['nullable', 'integer', 'between:0,999'],

            // Şablon: kaynak => aksiyonlar.
            'template' => ['array'],
            'template.*' => ['array'],
            'template.*.*' => ['string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->role();

            /*
             * Son yönetici rolünün yetkisi alınamaz: alınırsa paneli kimse
             * yönetemez ve geri almak için de yönetici gerekir.
             */
            if ($role?->is_admin === true && ! $this->boolean('is_admin')
                && Role::query()->where('is_admin', true)->where('id', '!=', $role->id)->doesntExist()) {
                $validator->errors()->add('is_admin', 'Panelde en az bir yönetici rolü kalmak zorunda.');
            }

            // Yönetici rolü varsayılan olamaz: "kullanıcı ekle" yetkisi,
            // yönetici üretme yetkisi değildir.
            if ($this->boolean('is_admin') && $this->boolean('is_default')) {
                $validator->errors()->add('is_default', 'Yönetici rolü yeni kullanıcıların varsayılanı olamaz.');
            }
        });
    }

    /**
     * Yalnızca TANIMLI kaynak/aksiyon çiftleri.
     *
     * @return array<string, list<string>>
     */
    public function template(PermissionRegistry $registry): array
    {
        $template = [];

        foreach ((array) $this->input('template', []) as $resource => $actions) {
            if (! is_string($resource)) {
                continue;
            }

            $kabul = array_values(array_filter(
                (array) $actions,
                fn (mixed $action): bool => is_string($action) && $registry->supports($resource, $action),
            ));

            if ($kabul !== []) {
                $template[$resource] = $kabul;
            }
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'kod adı',
            'label' => 'rol adı',
            'badge' => 'renk',
            'is_admin' => 'yönetici',
            'is_default' => 'varsayılan rol',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Kod adı küçük harfle başlamalı; harf, rakam ve tire kullanılabilir.',
            'slug.prohibited' => 'Yerleşik rollerin kod adı değiştirilemez.',
        ];
    }

    private function role(): ?Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : null;
    }
}
