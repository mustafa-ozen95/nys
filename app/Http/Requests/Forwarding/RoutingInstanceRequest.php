<?php

declare(strict_types=1);

namespace App\Http\Requests\Forwarding;

use App\Enums\RoutingInstanceType;
use App\Models\SwitchRoutingInstance;
use App\Services\Capabilities\DeviceCapabilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yönlendirme tablosu (routing-instance).
 *
 * Cihazın kuralları formda ÖNCEDEN söylenir:
 *
 * - Ad cihazda KİMLİKTİR; düzenlemede değiştirilemez, aynı switchte tekrar
 *   edemez.
 * - TÜR CİHAZA GÖRE DEĞİŞİR. EX2300 "forwarding" ve "virtual-switch" için
 *   "invalid value" diyor, EX3200 hepsini kabul ediyor (ölçüldü). Panel
 *   yalnızca o cihazda ölçülmüş türleri sunar; ölçüm yoksa daraltma yapmaz.
 */
class RoutingInstanceRequest extends FormRequest
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
        $switchId = $this->route('switch')->id;
        $mevcut = $this->route('routingInstance');

        return [
            /*
             * Junos adı: harf, rakam, tire, alt çizgi ve nokta. Cihaz
             * boşluklu ya da özel karakterli adı reddediyor.
             */
            'name' => [
                Rule::requiredIf($mevcut === null),
                'string', 'max:63', 'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique(SwitchRoutingInstance::class, 'name')
                    ->where('switch_id', $switchId)
                    ->ignore($mevcut),
            ],

            'instance_type' => ['required', Rule::in($this->allowedTypes())],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Bu CİHAZIN kabul ettiği tablo türleri.
     *
     * @return list<string>
     */
    public function allowedTypes(): array
    {
        $turler = array_keys(RoutingInstanceType::options());
        $olculen = app(DeviceCapabilities::class)
            ->values($this->route('switch'), 'routing.instance_types');

        return $olculen === null ? $turler : array_values(array_intersect($turler, $olculen));
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $mevcut = $this->route('routingInstance');

        return [
            'instance_type' => $this->string('instance_type')->toString(),
            'description' => $this->string('description')->trim()->toString() ?: null,
            'residual_xml' => $mevcut?->residual_xml,
        ];
    }

    public function instanceName(): string
    {
        return $this->route('routingInstance')?->name
            ?? $this->string('name')->trim()->toString();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'tablo adı',
            'instance_type' => 'tür',
            'description' => 'açıklama',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Tablo adı yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.',
            'instance_type.in' => 'Bu cihaz seçilen türü kabul etmiyor.',
        ];
    }
}
