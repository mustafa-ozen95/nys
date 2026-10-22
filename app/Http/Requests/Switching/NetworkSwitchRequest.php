<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\CredentialProtocol;
use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;
use App\Models\NetworkSwitch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Switch oluşturma ve düzenleme kuralları ortaktır; tekil kural yalnızca
 * düzenlenen kaydı hariç tutar.
 *
 * Yetki kontrolü Policy'de yapılır (route seviyesinde authorizeResource),
 * bu yüzden burada tekrarlanmaz.
 */
class NetworkSwitchRequest extends FormRequest
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
        $switch = $this->route('switch');
        $ignore = $switch instanceof NetworkSwitch ? $switch->id : null;

        return [
            'hostname' => [
                'required', 'string', 'max:255',
                Rule::unique('switches', 'hostname')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'management_ip' => [
                'required', 'ip',
                Rule::unique('switches', 'management_ip')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'uplink_ip' => ['nullable', 'ip'],

            /*
             * model, junos_version ve seri numarası BURADA YOK: onları cihaz
             * bildirir (NETCONF get-chassis-inventory / get-software-information)
             * ve eşitleme yazar. Formda sorulsalardı kullanıcının yazdığı değer
             * bir sonraki eşitlemede sessizce ezilirdi.
             */
            'role' => ['required', Rule::enum(SwitchRole::class)],
            'location' => ['required', Rule::enum(SwitchLocation::class)],

            // Kimlik bilgileri: boş bırakılırsa mevcut değer korunur.
            'ssh_username' => ['nullable', 'string', 'max:255'],
            'ssh_password' => ['nullable', 'string', 'min:4', 'max:255'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'snmp_community' => ['nullable', 'string', 'max:255'],
            'snmp_port' => ['required', 'integer', 'min:1', 'max:65535'],

            /*
             * NETCONF kimliği olmadan switch panelden yapılandırılamaz.
             * Kullanıcı adı ve parola boş bırakılırsa SSH kimliği kopyalanır;
             * cihazlarda genelde aynı hesap kullanılıyor.
             */
            'netconf_enabled' => ['boolean'],
            'netconf_username' => ['nullable', 'string', 'max:255'],
            'netconf_password' => ['nullable', 'string', 'min:4', 'max:255'],
            'netconf_port' => ['required_if:netconf_enabled,1', 'nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * Kayıtlı kimlik bilgileri BAŞKA bir adrese taşınamaz.
     *
     * Yönetim IP'si değişirse panel (15 dakikalık eşitleme, bağlantı, yazma)
     * kayıtlı parolayı yeni adrese gönderir. "Düzenle" yetkisi olup "Parolayı
     * Gör" yetkisi olmayan biri adresi kendi dinleyicisine çevirip parolayı
     * ele geçirebilirdi. Parolayı görme yetkisi yoksa hedefle birlikte
     * parolaların da AYNI istekte yeniden girilmesi gerekir. Adres aynı
     * kalıyorsa hiçbir şey sorulmaz.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $switch = $this->route('switch');

            if (! $switch instanceof NetworkSwitch
                || $this->string('management_ip')->toString() === (string) $switch->management_ip
                || $validator->errors()->has('management_ip')
                || $this->user()->can('secret', $switch)) {
                return;
            }

            $registered = $switch->credentials()->pluck('protocol')
                ->map(fn ($protocol): string => $protocol instanceof CredentialProtocol ? $protocol->value : (string) $protocol);

            $missing = [];

            if ($registered->contains(CredentialProtocol::Ssh->value) && ! $this->filled('ssh_password')) {
                $missing['ssh_password'] = 'SSH parolası';
            }

            // Boş NETCONF parolası SSH'ınkinden kopyalanır; ikisi de boşsa eski parola kalır.
            if ($registered->contains(CredentialProtocol::Netconf->value)
                && ! ($this->boolean('netconf_enabled') && ($this->filled('netconf_password') || $this->filled('ssh_password')))) {
                $missing['netconf_password'] = 'NETCONF parolası (ve NETCONF açık)';
            }

            if ($registered->contains(CredentialProtocol::SnmpV2c->value) && ! $this->filled('snmp_community')) {
                $missing['snmp_community'] = 'SNMP community';
            }

            foreach ($missing as $alan => $name) {
                $validator->errors()->add(
                    $alan,
                    "Yönetim IP adresi değişirken kayıtlı kimlik bilgisi yeni adrese gönderilir; $name yeniden girilmeli "
                    .'(ya da “Parolayı Gör” yetkisi gerekir).',
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
            'hostname' => 'switch adı',
            'management_ip' => 'yönetim IP adresi',
            'uplink_ip' => 'uplink IP adresi',
            'model' => 'model',
            'role' => 'rol',
            'location' => 'konum',
            'ssh_username' => 'SSH kullanıcı adı',
            'ssh_password' => 'SSH parolası',
            'ssh_port' => 'SSH portu',
            'snmp_community' => 'SNMP community',
            'snmp_port' => 'SNMP portu',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ssh_port' => $this->input('ssh_port') ?: 22,
            'snmp_port' => $this->input('snmp_port') ?: 161,
        ]);
    }

    /**
     * Switch tablosuna yazılacak alanlar.
     *
     * @return array<string, mixed>
     */
    public function switchAttributes(): array
    {
        return $this->safe()->only([
            'hostname', 'management_ip', 'uplink_ip', 'role', 'location',
        ]);
    }

    /**
     * Kimlik bilgisi alanları.
     *
     * @return array<string, mixed>
     */
    public function credentialAttributes(): array
    {
        return $this->safe()->only([
            'ssh_username', 'ssh_password', 'ssh_port',
            'snmp_community', 'snmp_port',
            'netconf_username', 'netconf_password', 'netconf_port',
        ]) + ['netconf_enabled' => $this->boolean('netconf_enabled')];
    }
}
