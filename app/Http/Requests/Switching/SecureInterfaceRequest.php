<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\PortMode;
use App\Enums\SecureAccessAction;
use App\Models\SwitchPort;
use App\Models\SwitchSecureInterface;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Bir arayüzün port güvenliği kuralı (secure-access-port interface <ad>).
 *
 * Cihazın kendi kuralları formda ÖNCEDEN söylenir ve sunucuda da durur:
 *
 * - Arayüz bu switchte var olmalı ve ANAHTARLAMA modunda olmalı; "all"
 *   cihazın kabul ettiği sanal addır.
 * - Eylem tek başına yazılmaz.
 * - allowed-mac girdileri MAC adresi olmalı (00:11:22:33:44:55).
 * - ELS'te secure-access-port düğümü yoktur.
 *
 * "dhcp-trusted" BLOKLANMAZ, uyarılır: erişim portuna verilmesi neredeyse
 * her zaman hatadır ama "erişim moduna ayarlı ama karşısında switch olan"
 * port sahada vardır. Karar kullanıcınındır; panelin işi kararı bilerek
 * verdirmektir (form uyarısı + onay penceresi + listede kırmızı rozet).
 */
class SecureInterfaceRequest extends SecureAccessRequest
{
    /** Junos'un kabul ettiği MAC yazımı. */
    private const MAC = '/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $current = $this->route('secureInterface');

        return [
            'interface_name' => [
                Rule::requiredIf($current === null),
                'string', 'max:64',
                Rule::unique(SwitchSecureInterface::class, 'interface_name')
                    ->where('switch_id', $this->switch()->id)
                    ->ignore($current),
            ],

            /*
             * ELS'te DHCP güveni ve izinli MAC listesi VLAN kapsamındadır;
             * o cihazlarda hangi VLAN olduğu sorulur. ELS öncesinde alan boş
             * kalır (ayarlar switch genelindedir).
             */
            'vlan_name' => ['nullable', 'string', 'max:64'],

            /*
             * Üç durumlu: boş (cihazın varsayılanı), 1 (dhcp-trusted),
             * 0 (no-dhcp-trusted). Boolean olarak doğrulanamaz; "yok" ile
             * "hayır" aynı şey değildir.
             *
             * ELS'te iki durum vardır: güven ya gruptadır ya değildir
             * ("no-dhcp-trusted" orada sözdizimi hatası). 0 ile boş aynı
             * sonucu verir.
             */
            'dhcp_trusted' => ['nullable', 'in:0,1'],

            // Üst sınır cihazda ÖLÇÜLMEDİ; cihaz kendi sınırını söyler.
            'mac_limit' => ['nullable', 'integer', 'between:1,65535'],
            /*
             * Geçerli eylemler CİHAZ AİLESİNE bağlıdır: ELS "log" ile
             * "none"ı reddediyor, karşılığında "drop-and-log" sunuyor.
             * Küme lehçeden gelir, burada sabitlenmez.
             */
            'mac_limit_action' => [
                'nullable',
                Rule::in(array_map(
                    static fn (SecureAccessAction $action): string => $action->value,
                    $this->dialect()->macLimitActions(),
                )),
            ],

            'allowed_macs' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardSchema($validator, 'interface_name');
            $this->guardVlanExists($validator);
            $this->guardMacLimitAction($validator);
            $this->guardSwitchingPort($validator);
            $this->guardLimitAction($validator, 'mac_limit', 'mac_limit_action');
            $this->guardMacAddresses($validator);
        });
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta bu kuralı vermiyor.
     *
     * Arayüz adı cihazda BİRİMLİ tutulur (ge-0/0/5.0) ve geri okumada öyle
     * kaydedilir; port tablosunda ise birimsizdir. Birim soyulmadan
     * arandığında cihazdan okunmuş bir satır bir daha düzenlenemezdi.
     */
    private function guardSwitchingPort(Validator $validator): void
    {
        $name = $this->name();

        if ($name === '' || $name === 'all') {
            return;
        }

        $port = SwitchPort::query()
            ->where('switch_id', $this->switch()->id)
            ->firstWhere('name', SwitchSecureInterface::basePortName($name));

        if ($port === null) {
            $validator->errors()->add('interface_name', 'Bu switchte böyle bir port yok.');

            return;
        }

        if (! in_array($port->port_mode, [PortMode::Access, PortMode::Trunk], true)) {
            $validator->errors()->add(
                'interface_name',
                "{$name} anahtarlama modunda değil; cihaz bu porta erişim güvenliği kuralı tanımlamayı reddediyor.",
            );
        }
    }

    private function guardMacAddresses(Validator $validator): void
    {
        foreach ($this->macLines() as $mac) {
            if (preg_match(self::MAC, $mac) !== 1) {
                $validator->errors()->add(
                    'allowed_macs',
                    "“{$mac}” bir MAC adresi değil; 00:11:22:33:44:55 biçiminde yazın.",
                );

                return;
            }
        }
    }

    /**
     * Cihaza yazılacak ayarlar. Korunan ham satırlar düzenlenen kayıttan
     * gelir; panelde gösterilmeyen satırlar kaybolmasın.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $current = $this->route('secureInterface');

        return [
            // ELS'te VLAN kapsamlı alanların hangi VLAN'a yazılacağı.
            'vlan_name' => $this->filled('vlan_name') ? $this->string('vlan_name')->toString() : null,
            'dhcp_trusted' => $this->filled('dhcp_trusted') ? $this->boolean('dhcp_trusted') : null,
            'mac_limit' => $this->filled('mac_limit') ? $this->integer('mac_limit') : null,
            'mac_limit_action' => $this->filled('mac_limit_action')
                ? $this->string('mac_limit_action')->toString()
                : null,
            'allowed_macs' => $this->allowedMacs(),
            'residual_xml' => $current instanceof SwitchSecureInterface ? $current->residual_xml : null,
        ];
    }

    /**
     * Düzenlemede ad KAYITTAN gelir: arayüz adı bu kuralın kimliğidir.
     */
    public function interfaceName(): string
    {
        $current = $this->route('secureInterface');

        return $current instanceof SwitchSecureInterface
            ? (string) $current->interface_name
            : $this->name();
    }

    /**
     * Onay metni ve etkinlik kaydı için: bu istek portu hizmet dışı
     * bırakabilecek bir eylem taşıyor mu?
     */
    public function shutsPortDown(): bool
    {
        return SecureAccessAction::tryFrom($this->string('mac_limit_action')->toString())?->isDisruptive() === true;
    }

    /**
     * @return list<string>|null
     */
    private function allowedMacs(): ?array
    {
        $macs = array_values(array_unique(array_map(
            static fn (string $mac): string => mb_strtolower($mac),
            $this->macLines(),
        )));

        return $macs === [] ? null : $macs;
    }

    /**
     * Metin alanı satır satır girilir; boş satırlar atılır.
     *
     * @return list<string>
     */
    private function macLines(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $this->string('allowed_macs')->toString()) ?: [],
        ), static fn (string $line): bool => $line !== ''));
    }

    private function name(): string
    {
        return trim($this->string('interface_name')->toString());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'interface_name' => 'arayüz',
            'dhcp_trusted' => 'DHCP güven durumu',
            'mac_limit' => 'MAC sınırı',
            'mac_limit_action' => 'sınır eylemi',
            'allowed_macs' => 'izin verilen MAC listesi',
        ];
    }

    /**
     * Seçilen VLAN bu switchte gerçekten olmalı; cihazda olmayan bir VLAN'a
     * yazmak commit check'te düşerdi.
     */
    private function guardVlanExists(Validator $validator): void
    {
        if (! $this->filled('vlan_name')) {
            return;
        }

        $var = $this->switch()->vlans()
            ->where('name', $this->string('vlan_name')->toString())
            ->exists();

        if (! $var) {
            $validator->errors()->add('vlan_name', 'Bu switchte böyle bir VLAN yok.');
        }
    }

    /**
     * ELS MAC sınırını EYLEMSİZ kabul etmiyor ("should be configured with
     * drop/drop-and-log/shutdown action"). Panel eylemi kendisi seçmez:
     * trafiği düşürmek ya da portu kapatmak operatörün kararıdır.
     */
    private function guardMacLimitAction(Validator $validator): void
    {
        if (! $this->dialect()->requiresMacLimitAction()) {
            return;
        }

        if ($this->filled('mac_limit') && ! $this->filled('mac_limit_action')) {
            $validator->errors()->add(
                'mac_limit_action',
                'Bu cihazda MAC sınırı eylemsiz yazılamaz; ne yapılacağını seçin.',
            );
        }
    }
}
