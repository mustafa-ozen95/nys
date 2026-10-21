<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\PortMode;
use App\Models\SwitchPort;
use App\Models\SwitchRstpInterface;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bir arayüzün spanning tree ayarları.
 *
 * "edge" portu uç nokta sayar ve dinleme/öğrenme aşamalarını atlatır: sunucu
 * bağlı portta beklemeyi kaldırır, ama SWITCH bağlı bir porta verilirse
 * döngü anında oluşur. Kısıt yazılamaz — kararı kullanıcı verir — ama onay
 * penceresi bunu açıkça söyler.
 *
 * Arayüz adı cihazda kimliktir; düzenlemede değiştirilemez. "all" Junos'un
 * kabul ettiği sanal bir addır ve portlar arasında karşılığı yoktur.
 */
class RstpInterfaceRequest extends FormRequest
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
        $mevcut = $this->route('rstpInterface');

        return [
            'interface_name' => array_values(array_filter([
                'required', 'string', 'max:64',
                $mevcut instanceof SwitchRstpInterface
                    ? Rule::in([$mevcut->interface_name])
                    : Rule::in(['all', ...$this->switchedPorts()]),
                $mevcut instanceof SwitchRstpInterface
                    ? null
                    : Rule::unique(SwitchRstpInterface::class, 'interface_name')
                        ->where('switch_id', $switch->id),
            ])),

            'edge' => ['boolean'],
            'no_root_port' => ['boolean'],

            // Junos aralıkları; dışına çıkılırsa commit reddedilir.
            'cost' => ['nullable', 'integer', 'min:1', 'max:200000000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:240'],
        ];
    }

    /**
     * Spanning tree yalnızca ANAHTARLAMA modundaki portlarda tanımlanır.
     *
     * Cihaz aksini reddediyor: "XSTP : Interface ... is not enabled for
     * Ethernet Switching". Routed bir porta ya da hiç yapılandırılmamış bir
     * porta yazılamaz.
     *
     * @return list<string>
     */
    private function switchedPorts(): array
    {
        return SwitchPort::query()
            ->where('switch_id', $this->route('switch')->id)
            ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
            ->pluck('name')
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $priority = $this->input('priority');

            /*
             * Port önceliği 16'nın katı olmalıdır; Junos aradaki değerleri
             * reddeder.
             */
            if (filled($priority) && (int) $priority % 16 !== 0) {
                $validator->errors()->add('priority', 'Port önceliği 16’nın katı olmalı (0, 16, 32 … 240).');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'interface_name' => 'arayüz',
            'edge' => 'uç nokta',
            'no_root_port' => 'kök port olmasın',
            'cost' => 'maliyet',
            'priority' => 'öncelik',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interface_name.in' => 'Spanning tree yalnızca anahtarlama modundaki (access/trunk) portlarda '
                .'tanımlanabilir; cihaz routed ve yapılandırılmamış portları reddediyor. '
                .'Bütün portlar için “all” seçilebilir.',
            'interface_name.unique' => 'Bu arayüzün spanning tree ayarı zaten var.',
        ];
    }
}
