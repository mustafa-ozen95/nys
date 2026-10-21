<?php

declare(strict_types=1);

namespace App\Http\Requests\Provisioning;

use App\Models\NetworkSwitch;
use App\Support\Credentials\PasswordGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza config yükleme formu.
 *
 * Hedef iki türlü olabilir: envanterdeki bir switch ya da yalnızca yönetim
 * erişim bilgisine sahip olduğumuz dış bir cihaz. Kaynak da iki türlüdür:
 * elle yapıştırılan metin ya da şablon girdileri.
 */
class ConfigurationPushRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', Rule::in(['switch', 'external'])],
            'switch' => ['required_if:target,switch', 'nullable', Rule::exists('switches', 'uuid')],

            'host' => ['required_if:target,external', 'nullable', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_if:target,external', 'nullable', 'string', 'max:64'],
            'password' => ['required_if:target,external', 'nullable', 'string', 'max:255'],
            'save_to_inventory' => ['boolean'],

            'source' => ['required', Rule::in(['manual', 'template'])],
            'body' => ['required_if:source,manual', 'nullable', 'string', 'max:200000'],

            'mode' => ['required', Rule::in(['merge', 'override'])],
            'step' => ['required', Rule::in(['preview', 'apply'])],

            // Şablon girdileri.
            'hostname' => ['required_if:source,template', 'nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9._-]+$/'],
            'management_address' => ['required_if:source,template', 'nullable', 'ip'],
            // Üst sınır aileye bağlı: IPv6 yönetim adresi /128'e kadar olabilir.
            'management_prefix' => ['required_if:source,template', 'nullable', 'integer', 'min:8', 'max:128'],
            'gateway' => ['nullable', 'ip'],
            'dns' => ['nullable', 'string', 'max:255'],
            'ntp' => ['nullable', 'string', 'max:255'],
            /*
             * Cihazın kendi kuralı: en az 6 karakter ve en az iki karakter
             * sınıfı (test1'de ölçüldü). Sınıf kuralı hiç yoktu; tek çeşit
             * karakterden oluşan bir parola panelden geçiyor ve KURULUM
             * SIRASINDA cihazda düşüyordu.
             */
            'root_password' => ['nullable', 'string', ...PasswordGenerator::deviceRules()],
            'admin_username' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'admin_password' => ['nullable', 'string', ...PasswordGenerator::deviceRules()],
            'snmp_community' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'host' => 'yönetim adresi',
            'username' => 'kullanıcı',
            'password' => 'parola',
            'body' => 'config metni',
            'hostname' => 'cihaz adı',
            'management_address' => 'yönetim adresi',
            'management_prefix' => 'maske uzunluğu',
            'gateway' => 'ağ geçidi',
            'root_password' => 'root parolası',
            'admin_username' => 'yönetici kullanıcı adı',
            'admin_password' => 'yönetici parolası',
            'snmp_community' => 'SNMP community',
        ];
    }

    public function targetSwitch(): ?NetworkSwitch
    {
        return $this->input('target') === 'switch'
            ? NetworkSwitch::query()->where('uuid', $this->input('switch'))->firstOrFail()
            : null;
    }

    public function appliesImmediately(): bool
    {
        return $this->input('step') === 'apply';
    }

    /**
     * Şablon girdileri, üretici sınıfın beklediği biçimde.
     *
     * @return array<string, mixed>
     */
    public function templateInput(): array
    {
        return [
            'hostname' => (string) $this->input('hostname'),
            'management_address' => (string) $this->input('management_address'),
            'management_prefix' => (int) $this->input('management_prefix'),
            'gateway' => $this->input('gateway'),
            'dns' => $this->addresses('dns'),
            'ntp' => $this->addresses('ntp'),
            'root_password' => $this->input('root_password'),
            'admin_username' => $this->input('admin_username'),
            'admin_password' => $this->input('admin_password'),
            'snmp_community' => $this->input('snmp_community'),
        ];
    }

    /**
     * Virgül ya da boşlukla ayrılmış adres listesi.
     *
     * @return list<string>
     */
    private function addresses(string $field): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', (string) $this->input($field)) ?: [],
            static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false,
        ));
    }
}
