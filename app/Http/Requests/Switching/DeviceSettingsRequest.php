<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\NetworkSwitch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihazın kendi ayarları: sistem adı, saat dilimi, isim sunucuları, NTP ve
 * günlük hedefleri.
 *
 * Listeler çok satırlı kutulardan gelir; doğrulama ve cihaza yazma liste
 * bekler. Boş satırlar atılır, sıra korunur — Junos'ta sıra anlamlıdır
 * (ilk yanıt veren kullanılır).
 */
class DeviceSettingsRequest extends FormRequest
{
    /** Cihazın kabul ettiği alarm seviyeleri (jn32-test ile doğrulandı). */
    public const ALARM_LEVELS = ['ignore', 'yellow', 'red'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lldp_interfaces' => $this->scope('lldp'),
            'lldp_med_interfaces' => $this->scope('lldp_med'),
            'igmp_snooping_vlans' => $this->scope('igmp_snooping'),
            'name_servers' => $this->lines('name_servers'),
            'ntp_servers' => $this->lines('ntp_servers'),
            'syslog_hosts' => $this->lines('syslog_hosts'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switch = $this->route('switch');

        return [
            /*
             * Junos ana makine adı: harf/rakam, nokta, tire ve alt çizgi.
             * Cihazda kimliktir ve SNMP adı ondan türetilir.
             */
            'hostname' => [
                'required', 'string', 'max:63',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('switches', 'hostname')
                    ->ignore($switch instanceof NetworkSwitch ? $switch->id : null)
                    ->whereNull('deleted_at'),
            ],

            // "Europe/Istanbul" gibi; cihaz listesi geniş, biçim doğrulanır.
            'time_zone' => ['nullable', 'string', 'max:64', 'regex:#^[A-Za-z]+(/[A-Za-z_+-]+)*$#'],

            'name_servers' => ['array', 'max:3'],
            'name_servers.*' => ['ip'],

            'ntp_servers' => ['array', 'max:4'],
            'ntp_servers.*' => ['ip'],

            /*
             * AÇILIŞ sunucusu. "server" ntpd'yi çalıştırır ama fark 1000
             * saniyeyi aşınca ntpd saati adımlamayı reddeder; bu alan
             * açılışta saati zorla yerine oturtur (bkz. DeviceSettingsWriter).
             */
            'ntp_boot_server' => ['nullable', 'ip'],

            /*
             * Protokol kapsamları. Null = protokol kapalı (cihazdan silinir);
             * liste = açık ve bu kapsamda. "all" cihazın kabul ettiği sanal
             * addır ve üç hiyerarşide de geçerli (test2/test4'te ölçüldü).
             */
            'lldp_interfaces' => ['nullable', 'array', 'max:64'],
            'lldp_interfaces.*' => ['string', 'max:64'],
            'lldp_med_interfaces' => ['nullable', 'array', 'max:64'],
            'lldp_med_interfaces.*' => ['string', 'max:64'],
            'igmp_snooping_vlans' => ['nullable', 'array', 'max:64'],
            'igmp_snooping_vlans.*' => ['string', 'max:64'],

            'syslog_hosts' => ['array', 'max:4'],
            'syslog_hosts.*' => ['ip'],

            /*
             * Şasi alarmları. Cihaz yalnızca bu üç değeri tanıyor; başkası
             * "invalid value" ile reddediliyor. Boş bırakmak satırı siler ve
             * cihaz varsayılanına döner.
             */
            'alarm_ethernet_link_down' => ['nullable', Rule::in(self::ALARM_LEVELS)],
            'alarm_management_link_down' => ['nullable', Rule::in(self::ALARM_LEVELS)],
        ];
    }

    /**
     * Cihaza yazılacak biçim.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'hostname' => $this->string('hostname')->toString(),
            'time_zone' => $this->string('time_zone')->trim()->toString() ?: null,
            'name_servers' => array_values((array) $this->input('name_servers', [])),
            'ntp_servers' => array_values((array) $this->input('ntp_servers', [])),
            'ntp_boot_server' => $this->string('ntp_boot_server')->trim()->toString() ?: null,
            'lldp_interfaces' => $this->input('lldp_interfaces'),
            'lldp_med_interfaces' => $this->input('lldp_med_interfaces'),
            'igmp_snooping_vlans' => $this->input('igmp_snooping_vlans'),
            'syslog_hosts' => array_values((array) $this->input('syslog_hosts', [])),
            'alarm_ethernet_link_down' => $this->string('alarm_ethernet_link_down')->toString() ?: null,
            'alarm_management_link_down' => $this->string('alarm_management_link_down')->toString() ?: null,
        ];
    }

    /**
     * @return list<string>
     */
    /**
     * Bir protokolün KAPSAMI.
     *
     * Ekran iki alan gönderiyor: açık mı (onay kutusu) ve kapsam (metin).
     * Kapalıysa null döner ve yazıcı protokolü cihazdan SİLER — boş bir
     * liste "tanımlı ama kapsamsız" demek olurdu, o farklı bir durum.
     *
     * @return list<string>|null
     */
    private function scope(string $key): ?array
    {
        if (! $this->boolean("{$key}_enabled")) {
            return null;
        }

        $kapsam = $this->lines("{$key}_scope");

        // Açık ama kapsam verilmemiş: cihazdaki yaygın kullanım "all".
        return $kapsam === [] ? ['all'] : $kapsam;
    }

    private function lines(string $key): array
    {
        $ham = $this->input($key);

        if (is_array($ham)) {
            return array_values(array_filter(array_map('trim', $ham), 'filled'));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string) $ham) ?: []),
            'filled',
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hostname' => 'cihaz adı',
            'time_zone' => 'saat dilimi',
            'name_servers.*' => 'DNS sunucusu',
            'ntp_servers.*' => 'NTP sunucusu',
            'ntp_boot_server' => 'NTP açılış sunucusu',
            'lldp_interfaces' => 'LLDP kapsamı',
            'lldp_med_interfaces' => 'LLDP-MED kapsamı',
            'igmp_snooping_vlans' => 'IGMP snooping kapsamı',
            'syslog_hosts.*' => 'günlük sunucusu',
            'alarm_ethernet_link_down' => 'port alarmı',
            'alarm_management_link_down' => 'yönetim portu alarmı',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hostname.unique' => 'Bu ad başka bir switchte kullanılıyor.',
            'hostname.regex' => 'Cihaz adında harf, rakam, nokta, tire ve alt çizgi kullanılabilir.',
        ];
    }
}
