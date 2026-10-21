<?php

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\ConnectionProtocol;
use App\Enums\ServerOs;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServerRequest extends FormRequest
{
    /**
     * Protokolün varsayılan portu; kullanıcı boş bırakırsa bu kullanılır.
     */
    private const DEFAULT_PORTS = [
        'ssh' => 22,
        'rdp' => 3389,
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
        $server = $this->route('server');
        $ignore = $server instanceof Server ? $server->id : null;

        return [
            'short_name' => [
                'required', 'string', 'max:60',
                Rule::unique('servers', 'short_name')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'long_name' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'string', 'max:255'],
            'os_type' => ['required', Rule::enum(ServerOs::class)],
            'os_version' => ['nullable', 'string', 'max:255'],
            'connection_protocol' => ['required', Rule::enum(ConnectionProtocol::class)],
            'connection_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'connection_username' => ['required', 'string', 'max:255'],
            // Boş bırakılırsa mevcut parola korunur.
            'connection_password' => [$server instanceof Server ? 'nullable' : 'required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ServerStatus::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Kayıtlı parola BAŞKA bir hedefe taşınamaz.
     *
     * Adres, port ya da protokol değişirse panel kayıtlı parolayı yeni hedefe
     * gönderir (bağlantı, uzak oturum). "Düzenle" yetkisi olup parolayı
     * dışarı çıkarma ("export") yetkisi olmayan biri hedefi kendi
     * dinleyicisine çevirip parolayı ele geçirebilirdi. Yetki yoksa hedefle
     * birlikte parola da AYNI istekte yeniden girilmelidir.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $server = $this->route('server');

            if (! $server instanceof Server
                || $server->connection_password === null
                || $this->filled('connection_password')
                || $validator->errors()->isNotEmpty()
                || $this->user()->can('export', $server)) {
                return;
            }

            $degisti = $this->string('ip_address')->toString() !== (string) $server->ip_address
                || (int) $this->input('connection_port') !== (int) $server->connection_port
                || $this->string('connection_protocol')->toString() !== (string) $server->connection_protocol?->value;

            if ($degisti) {
                $validator->errors()->add(
                    'connection_password',
                    'Bağlantı hedefi (adres, port, protokol) değişirken kayıtlı parola yeni hedefe gönderilir; '
                    .'parola yeniden girilmeli (ya da parolayı dışarı çıkarma yetkisi gerekir).',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'short_name' => 'kısa ad',
            'long_name' => 'uzun ad',
            'ip_address' => 'adres',
            'os_type' => 'işletim sistemi',
            'os_version' => 'sürüm',
            'connection_protocol' => 'bağlantı protokolü',
            'connection_port' => 'bağlantı portu',
            'connection_username' => 'kullanıcı adı',
            'connection_password' => 'parola',
            'status' => 'durum',
            'location' => 'konum',
            'notes' => 'notlar',
        ];
    }

    protected function prepareForValidation(): void
    {
        $protocol = $this->string('connection_protocol')->toString();

        $this->merge([
            'connection_port' => $this->input('connection_port') ?: (self::DEFAULT_PORTS[$protocol] ?? 22),
        ]);
    }

    /**
     * Parola boşsa alan hiç yazılmaz; kayıtlı sır silinmez.
     *
     * @return array<string, mixed>
     */
    public function serverAttributes(): array
    {
        $attributes = $this->safe()->except([]);

        if (blank($attributes['connection_password'] ?? null)) {
            unset($attributes['connection_password']);
        }

        return $attributes;
    }
}
