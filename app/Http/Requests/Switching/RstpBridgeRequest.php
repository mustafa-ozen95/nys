<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Spanning tree köprü ayarları.
 *
 * Köprü önceliği Junos'ta SERBEST bir sayı değildir: 4096'nın katları,
 * "4k" biçiminde yazılır. Serbest metin kabul edilseydi cihaz commit'i
 * reddederdi.
 *
 * Önceliği düşürmek bu switchi kök köprü yapar ve topoloji yeniden
 * yakınsar — birkaç saniye trafik kesintisi demektir. Onay penceresi bu
 * yüzden switch adı yazdırır.
 */
class RstpBridgeRequest extends FormRequest
{
    /** Junos'un kabul ettiği köprü öncelikleri. */
    public const PRIORITIES = [
        '0', '4k', '8k', '12k', '16k', '20k', '24k', '28k',
        '32k', '36k', '40k', '44k', '48k', '52k', '56k', '60k',
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
        return [
            'enabled' => ['boolean'],
            'bridge_priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'bpdu_block_on_edge' => ['boolean'],
        ];
    }

    public function bridgePriority(): ?string
    {
        return $this->string('bridge_priority')->toString() ?: null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enabled' => 'spanning tree',
            'bridge_priority' => 'köprü önceliği',
            'bpdu_block_on_edge' => 'BPDU koruması',
        ];
    }
}
