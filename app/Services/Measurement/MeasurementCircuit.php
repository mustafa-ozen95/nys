<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use App\Enums\MeasurementSource;
use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\Cache;

/**
 * Devre kesici: hata veren ölçüm kaynağı kısa süre denenmez.
 *
 * Bu olmadan izleme sistemi kesintisinde her istek zaman aşımı kadar donar —
 * 15 saniyelik zaman aşımı ve iki yeniden deneme, istek başına 45 saniye
 * demektir. Bayrak süresi dolunca kaynak kendiliğinden yeniden denenir;
 * elle müdahale gerekmez.
 *
 * Zabbix arızası sistemiktir (tek sunucu), cihaz arızası ise o cihaza
 * özeldir. Bu yüzden anahtar Zabbix'te switchten bağımsızdır.
 */
class MeasurementCircuit
{
    public function isOpen(MeasurementSource $source, NetworkSwitch $switch): bool
    {
        return Cache::get($this->key($source, $switch)) !== null;
    }

    public function recordFailure(MeasurementSource $source, NetworkSwitch $switch, string $reason): void
    {
        $ttl = (int) config('measurement.failure_ttl');

        if ($ttl <= 0) {
            return;
        }

        Cache::put(
            $this->key($source, $switch),
            ['at' => now()->toIso8601String(), 'reason' => $reason],
            $ttl,
        );
    }

    public function recordSuccess(MeasurementSource $source, NetworkSwitch $switch): void
    {
        Cache::forget($this->key($source, $switch));

        /*
         * GÜNLÜK İŞARETİ BAŞARIYLA DÜŞMEZ.
         *
         * Bir turda aynı kaynaktan iki okuma yapılır (device ve ports).
         * İzleme sisteminde cihazın bir ölçümü varken öbürü yoksa tur başına
         * biri başarılı biri başarısız olur; başarı işareti düşürseydi "saatte
         * bir yaz" kuralı her turda sıfırlanır ve uyarı her turda yeniden
         * yazılırdı. Bu yüzden işaret yalnızca ZAMANLA düşer.
         */
        Cache::put($this->successKey($source), now()->toIso8601String(), now()->addDay());
    }

    /**
     * Bu arıza günlüğe YAZILSIN MI?
     *
     * Kesici süresi kısadır (60 sn) ve ölçüm turu 30 saniyede bir koşar:
     * ulaşılamayan tek bir cihaz saatte yüzlerce satır üretebilir ve günlük
     * dosyası hızla şişer. Gerçek bir üretim hatasını o yığının içinde bulmak
     * samanlıkta iğne aramaktır.
     *
     * Arıza SÜRDÜĞÜ sürece saatte bir kez yazılır. Sınır yalnızca zamana
     * bağlıdır: aynı kaynağın başka bir okuması başarılı olduğu için sıfırlanmaz
     * (bkz. recordSuccess). "Hâlâ bozuk mu" sorusunu saatlik satır, "ne zaman
     * toparladı" sorusunu summary() yanıtlar.
     */
    public function shouldReport(MeasurementSource $source, NetworkSwitch $switch): bool
    {
        $key = $this->reportKey($source, $switch);

        if (Cache::get($key) !== null) {
            return false;
        }

        Cache::put($key, now()->toIso8601String(), (int) config('measurement.report_interval'));

        return true;
    }

    /**
     * Ayar ekranında gösterilen özet: kaynak şu an kesik mi, neden, en son ne
     * zaman veri alınmıştı.
     *
     * @return array{open: bool, reason: ?string, last_success: ?string}
     */
    public function summary(MeasurementSource $source): array
    {
        $failure = Cache::get('measurement:circuit:'.$source->value);

        return [
            'open' => is_array($failure),
            'reason' => is_array($failure) ? $failure['reason'] : null,
            'last_success' => Cache::get($this->successKey($source)),
        ];
    }

    private function key(MeasurementSource $source, NetworkSwitch $switch): string
    {
        return $source === MeasurementSource::Zabbix
            ? 'measurement:circuit:'.$source->value
            : "measurement:circuit:{$source->value}:{$switch->id}";
    }

    private function reportKey(MeasurementSource $source, NetworkSwitch $switch): string
    {
        return "measurement:reported:{$source->value}:{$switch->id}";
    }

    private function successKey(MeasurementSource $source): string
    {
        return 'measurement:last-success:'.$source->value;
    }
}
