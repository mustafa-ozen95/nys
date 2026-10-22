<?php

declare(strict_types=1);

namespace App\Http\Requests\Aggregation;

use App\Models\SwitchPort;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "ae limiti": chassis aggregated-devices ethernet device-count.
 *
 * Bu bir üst sınır değil, KAÇ TANE ae ARAYÜZÜNÜN YARATILACAĞIDIR. 4 verilirse
 * cihaz ae0..ae3'ü gerçekten oluşturur.
 *
 * Sayıyı düşürmek üstteki arayüzleri YOK EDER ve cihaz bunu commit'te
 * söylemez — gerçek cihazda ölçüldü: device-count 4'ten 2'ye indirildiğinde
 * yapılandırması duran ae3 sessizce ortadan kalktı. Koruma bu yüzden burada.
 */
class DeviceCountRequest extends FormRequest
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
        // Aralığı cihaz söylüyor: "Value 112 is not within range (1..111)".
        return [
            'device_count' => ['required', 'integer', 'min:1', 'max:111'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = (int) $this->input('device_count');
            $used = $this->highestConfiguredIndex();

            if ($used !== null && $count <= $used) {
                $validator->errors()->add('device_count', sprintf(
                    'ae%d yapılandırılmış durumda; limit en az %d olmalı. Daha küçük bir sayı o arayüzü cihazdan sessizce siler.',
                    $used,
                    $used + 1,
                ));
            }
        });
    }

    /**
     * Yapılandırması olan en yüksek ae sırası. Boş ae'ler (chassis'in
     * yarattığı ama hiç kullanılmayanlar) sayılmaz; onları kaybetmek zararsız.
     */
    private function highestConfiguredIndex(): ?int
    {
        $indices = $this->route('switch')->ports()
            ->aggregated()
            ->get()
            ->filter(fn (SwitchPort $port): bool => $port->description !== null
                || $port->lacp_mode !== null
                || $port->minimum_links !== null
                || $port->link_speed !== null
                || $port->members()->exists())
            ->map(fn (SwitchPort $port): ?int => $port->aggregate_index)
            ->filter(fn (?int $index): bool => $index !== null);

        return $indices->isEmpty() ? null : (int) $indices->max();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['device_count' => 'ae limiti'];
    }
}
