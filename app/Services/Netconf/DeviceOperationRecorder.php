<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\DeviceOperation;
use App\Models\NetworkSwitch;
use App\Support\Netconf\ConfigurationRedactor;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Cihaz işlemlerini kaydeder — özellikle DÜŞENLERİ.
 *
 * Kayıt olmasaydı cihaz bir yazmayı reddettiğinde kullanıcı yalnızca bir
 * toast görür, sayfayı kapatınca hiçbir iz kalmazdı; okuma hataları yalnızca
 * dosya günlüğüne düşer, uyarılar ise tamamen atılırdı.
 *
 * Ne kaydedilir:
 *   - Her BAŞARISIZ cihaz işlemi (yazma, okuma, eşitleme, ölçüm, sonda).
 *   - Cihazın UYARI döndürdüğü her işlem. Uyarı "işi durdurmadı" demektir,
 *     "önemsiz" demek değildir.
 *   - Başarılılar yalnızca config/activity.php açıkça isterse; olayın kendisi
 *     zaten etkinlik kaydında duruyor ve burada tekrarlanmaz.
 *
 * Gönderilen belge ve fark MASKELENEREK yazılır: kayıt dışa aktarılabilir,
 * parola ve community oraya girmemeli.
 *
 * Kaydetmek işi BOZMAZ. Kayıt yazılamıyorsa (tablo yok, disk dolu) asıl
 * işlemin hatası kaybolmamalı; bu yüzden her şey yutulur ve dosya günlüğüne
 * düşer.
 */
class DeviceOperationRecorder
{
    /**
     * Başarısız bir işlemi kaydeder ve istisnayı OLDUĞU GİBİ geri verir;
     * çağıran taraf `throw $recorder->failure(...)` diye kullanır.
     *
     * @param  array<string, mixed>  $context
     */
    public function failure(
        Throwable $exception,
        string $operation,
        string $target,
        ?NetworkSwitch $switch = null,
        array $context = [],
    ): Throwable {
        $netconf = $exception instanceof NetconfException ? $exception : null;

        $this->write([
            'switch_id' => $switch?->id,
            'target' => $target,
            'operation' => $operation,
            'stage' => $netconf?->stage() ?? ($context['stage'] ?? null) ?? $netconf?->rpc(),
            'status' => 'failed',
            'kind' => $netconf?->kind() ?? 'unknown',
            'summary' => $this->summary($context['summary'] ?? $operation),
            'device_message' => $exception->getMessage(),
            'errors' => $netconf === null ? null : array_map(
                static fn (RpcError $error): array => $error->toArray(),
                $netconf->errors(),
            ),
            'warnings' => $context['warnings'] ?? null,
            'document' => $this->redact($context['document'] ?? null),
            'diff' => $this->redact($context['diff'] ?? null),
            'duration_ms' => $context['duration_ms'] ?? null,
        ]);

        return $exception;
    }

    /**
     * Cihazın uyarı döndürdüğü (ama işin sürdüğü) bir işlem.
     *
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $context
     */
    public function warnings(
        array $warnings,
        string $operation,
        string $target,
        ?NetworkSwitch $switch = null,
        array $context = [],
    ): void {
        if ($warnings === []) {
            return;
        }

        $this->write([
            'switch_id' => $switch?->id,
            'target' => $target,
            'operation' => $operation,
            'stage' => $context['stage'] ?? null,
            'status' => 'warning',
            'kind' => 'rpc',
            'summary' => $this->summary($context['summary'] ?? $operation),
            'device_message' => implode(' | ', $warnings),
            'errors' => null,
            'warnings' => $warnings,
            'document' => $this->redact($context['document'] ?? null),
            'diff' => $this->redact($context['diff'] ?? null),
            'duration_ms' => $context['duration_ms'] ?? null,
        ]);
    }

    /**
     * Başarılı işlem. Varsayılan olarak KAYDEDİLMEZ; olayın kendisi etkinlik
     * kaydındadır ve burada ikinci kez yazmak kaydı şişirir.
     *
     * @param  array<string, mixed>  $context
     */
    public function success(
        string $operation,
        string $target,
        ?NetworkSwitch $switch = null,
        array $context = [],
    ): void {
        if (! config('activity.device_operations.record_success', false)) {
            return;
        }

        $this->write([
            'switch_id' => $switch?->id,
            'target' => $target,
            'operation' => $operation,
            'stage' => $context['stage'] ?? null,
            'status' => 'ok',
            'kind' => null,
            'summary' => $this->summary($context['summary'] ?? $operation),
            'device_message' => null,
            'errors' => null,
            'warnings' => $context['warnings'] ?? null,
            'document' => $this->redact($context['document'] ?? null),
            'diff' => $this->redact($context['diff'] ?? null),
            'duration_ms' => $context['duration_ms'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        try {
            DeviceOperation::query()->create($attributes + ['user_id' => Auth::id()]);
        } catch (Throwable $exception) {
            // Kayıt yazılamıyorsa asıl hata kaybolmamalı.
            report($exception);
        }
    }

    private function summary(string $summary): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $summary) ?? $summary), 0, 255);
    }

    private function redact(?string $value): ?string
    {
        return $value === null || $value === '' ? null : ConfigurationRedactor::apply($value);
    }
}
