<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\MeasurementSource;
use App\Models\NetworkSwitch;
use App\Services\Measurement\MeasurementCircuit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Ayar ekranındaki durum tablosunun verisi.
 *
 * Ayarları değiştirmeden önce kullanıcı ne olup bittiğini görmeli: hangi
 * kaynak konuşuyor, hangisi kesik, kuyrukta ne birikmiş. Bu bilgiler olmadan
 * "Zabbix'i kapatayım mı" sorusu körlemesine cevaplanır.
 */
class SystemHealthService
{
    public function __construct(private MeasurementCircuit $circuit) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'sources' => $this->sources(),
            'queue' => $this->queue(),
            'switches' => $this->switches(),
        ];
    }

    /**
     * Her ölçüm kaynağının durumu.
     *
     * @return list<array{source: MeasurementSource, primary: bool, configured: bool, open: bool, reason: ?string, last_success: ?string, switches: int}>
     */
    private function sources(): array
    {
        $primary = MeasurementSource::tryFrom((string) config('measurement.source'));
        /*
         * Sayım koleksiyonda yapılır: switch sayısı onlarcadır, ham SQL'e
         * inmeyi haklı çıkaracak bir hacim yok ve proje ham SQL'i son
         * seçenek sayar.
         */
        $usage = NetworkSwitch::query()
            ->whereNotNull('measurement_source')
            ->pluck('measurement_source')
            ->countBy(fn (MeasurementSource $source): string => $source->value);

        $rows = [];

        foreach (MeasurementSource::cases() as $source) {
            $summary = $this->circuit->summary($source);

            $rows[] = [
                'source' => $source,
                'primary' => $source === $primary,
                'configured' => $this->isConfigured($source),
                'open' => $summary['open'],
                'reason' => $summary['reason'],
                'last_success' => $this->humanised($summary['last_success']),
                'switches' => (int) ($usage[$source->value] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Zaman damgası okunur biçimde; Blade hesap yapmaz.
     */
    private function humanised(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->diffForHumans();
    }

    /**
     * Kaynağın kullanılabilir olması için gereken asgari yapılandırma var mı.
     */
    private function isConfigured(MeasurementSource $source): bool
    {
        return match ($source) {
            MeasurementSource::Zabbix => (bool) config('zabbix.enabled')
                && filled(config('zabbix.url'))
                && filled(config('zabbix.api_token')),
            MeasurementSource::Snmp => (bool) config('snmp.enabled'),
            MeasurementSource::Netconf => true,
        };
    }

    /**
     * @return array{pending: ?int, failed: ?int, name: string}
     */
    private function queue(): array
    {
        $name = (string) config('zabbix.queue');

        return [
            'name' => $name,
            'pending' => $this->guard(fn (): int => Queue::size($name)),
            'failed' => $this->guard(fn (): int => DB::table((string) config('queue.failed.table', 'failed_jobs'))->count()),
        ];
    }

    /**
     * @return array{total: int, stale: int}
     */
    private function switches(): array
    {
        return [
            'total' => NetworkSwitch::query()->count(),

            // Bir saattir hiç ölçüm alınamamış cihazlar: ya kaynak kesik ya da
            // zamanlayıcı çalışmıyor.
            'stale' => NetworkSwitch::query()
                ->where(fn ($query) => $query->whereNull('last_sync_at')->orWhere('last_sync_at', '<', now()->subHour()))
                ->count(),
        ];
    }

    /**
     * Durum tablosu, tek bir sayacı okuyamadı diye ayar ekranını
     * düşürmemeli: Redis erişilemezse kuyruk sayısı boş gösterilir.
     *
     * @param  \Closure(): int  $callback
     */
    private function guard(\Closure $callback): ?int
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
