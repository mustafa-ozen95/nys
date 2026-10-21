<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\AddressBlock;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\Vlan;
use App\Services\Switching\SwitchAddressCandidateService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchIpAddressRequest extends FormRequest
{
    /*
     * Adresin nereye bağlı olduğu. Arayüz adı bu seçimden türetilir; VLAN
     * ile port aynı anda seçilemez.
     *
     * Loopback'in ayrıca seçilecek bir nesnesi yok: arayüz daima lo0.0'dır.
     * BGP'nin local-address'i ve router-id tipik olarak oradadır; panelden
     * verilemediği sürece o iş CLI'da kalıyordu.
     */
    public const ATTACHMENTS = ['vlan', 'port', 'loopback'];

    /** Loopback adresinin daima yazıldığı arayüz. */
    public const LOOPBACK_INTERFACE = 'lo0.0';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switchId = $this->route('switch')->id;

        return [
            'attachment' => ['required', Rule::in(self::ATTACHMENTS)],

            'vlan_id' => [
                Rule::requiredIf(fn (): bool => $this->attachment() === 'vlan'),
                'nullable', 'uuid',
                Rule::exists(Vlan::class, 'uuid')->where('switch_id', $switchId),
            ],

            'switch_port_id' => [
                Rule::requiredIf(fn (): bool => $this->attachment() === 'port'),
                'nullable', 'uuid',
                Rule::exists(SwitchPort::class, 'uuid')->where('switch_id', $switchId),
            ],

            'address' => [
                'required', 'ip',
                Rule::unique(SwitchIpAddress::class, 'address')
                    ->where('switch_id', $switchId)
                    ->where('interface_name', $this->resolvedInterfaceName())
                    ->ignore($this->route('ipAddress')),
            ],

        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /*
             * ae ÜYESİ porta adres verilemez. Adres portun 0 numaralı
             * birimine yazılır ve cihaz "logical unit is not allowed on
             * aggregated links" diyerek commit'i düşürür. Port ekranı ve
             * VLAN ekranı bunu zaten engelliyordu; adres ekranı
             * engellemiyordu ve kullanıcıya ham cihaz hatası çıkıyordu.
             * Adres ae ARAYÜZÜNÜN KENDİSİNE verilir.
             */
            $port = $this->port();

            if ($this->attachment() === 'port' && $port?->aggregate_port_id !== null) {
                $validator->errors()->add(
                    'switch_port_id',
                    "{$port->name} bir ae arayüzüne üye; üye porta adres verilemez. "
                    .'Adres ae arayüzünün kendisine verilir.',
                );
            }

            // Arayüz adı VLAN'ın L3 arayüzünden gelir; tanımlı değilse
            // türetilecek bir ad yoktur.
            if ($this->attachment() === 'vlan' && $this->vlan() !== null && blank($this->vlan()->l3_interface)) {
                $validator->errors()->add(
                    'vlan_id',
                    'Seçilen VLAN’ın L3 arayüzü tanımlı değil. Önce VLAN kaydına L3 arayüzünü girin.'
                );
            }

            /*
             * Adres serbest girilmez: Adresleme'de bu switche atanmış bir
             * bloğun içinde olmak zorunda. Maske de o bloktan alınır.
             *
             * Tek istisna, düzenlenen kaydın DEĞİŞMEYEN adresidir: o adres
             * cihazdan okunmuştur, bloğu silinmiş olsa bile cihazda duruyor.
             * Reddedilirse yalnızca portunu değiştirmek isteyen kullanıcı
             * kaydı hiç kaydedemezdi.
             */
            if ($validator->errors()->hasAny(['address']) === false && $this->prefixLength() === null) {
                $validator->errors()->add(
                    'address',
                    'Bu adres, Adresleme’de bu switche atanmış hiçbir bloğun içinde değil.'
                );
            }
        });
    }

    /**
     * Cihaza yazılan biçim: adres/maske.
     */
    public function cidr(): string
    {
        return $this->string('address').'/'.$this->prefixLength();
    }

    /**
     * Maskenin tek kaynağı: adresin ait olduğu blok, o yoksa düzenlenen
     * kaydın kendi maskesi. İkisi de yoksa adres bu switche tanımlanamaz.
     */
    private function prefixLength(): ?int
    {
        if ($this->block() !== null) {
            return (int) $this->block()->prefix_length;
        }

        $current = $this->route('ipAddress');

        return $current instanceof SwitchIpAddress
            && $current->address === $this->string('address')->toString()
                ? (int) $current->prefix_length
                : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'attachment' => 'bağlantı',
            'address' => 'adres',
            'switch_port_id' => 'port',
            'vlan_id' => 'VLAN',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.unique' => 'Bu arayüzde bu adres zaten tanımlı.',
            'attachment.required' => 'Adresin VLAN’a mı porta mı bağlı olduğunu seçin.',
        ];
    }

    /** Adresin ait olduğu blok; maskenin tek kaynağı. */
    private function block(): ?AddressBlock
    {
        return app(SwitchAddressCandidateService::class)
            ->blockFor($this->route('switch'), $this->string('address')->toString());
    }

    private function attachment(): string
    {
        return $this->string('attachment')->toString();
    }

    private function vlan(): ?Vlan
    {
        return Vlan::query()->firstWhere('uuid', $this->string('vlan_id')->toString());
    }

    private function port(): ?SwitchPort
    {
        return SwitchPort::query()->firstWhere('uuid', $this->string('switch_port_id')->toString());
    }

    /**
     * Arayüz adı sorulmaz, seçimden türetilir. Portlarda mantıksal birim 0
     * varsayılır (ge-0/0/1 → ge-0/0/1.0); JunOS'ta L3 adres birime tanımlanır.
     */
    public function resolvedInterfaceName(): string
    {
        return match ($this->attachment()) {
            'vlan' => (string) $this->vlan()?->l3_interface,
            'port' => $this->port() !== null ? "{$this->port()->name}.0" : '',
            'loopback' => self::LOOPBACK_INTERFACE,
            default => '',
        };
    }
}
