<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * config/permissions.php dosyasının tek okuma noktası.
 *
 * Kaynak anahtarları ve aksiyonlar uygulamanın her yerinde bu sınıf üzerinden
 * sorulur; config() çağrısı controller, view veya request içine dağılmaz.
 */
class PermissionRegistry
{
    /**
     * @return array<string, string> anahtar => Türkçe etiket
     */
    public function actions(): array
    {
        return config('permissions.actions', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resources(): array
    {
        return config('permissions.resources', []);
    }

    /**
     * @return array<int, string>
     */
    public function resourceKeys(): array
    {
        return array_keys($this->resources());
    }

    /**
     * @return array<string, mixed>
     */
    public function resource(string $resource): array
    {
        $definition = $this->resources()[$resource] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Tanımsız yetki kaynağı: {$resource}");
        }

        return $definition;
    }

    /**
     * Bir kaynağın desteklediği aksiyonlar.
     *
     * @return array<int, string>
     */
    public function actionsFor(string $resource): array
    {
        return $this->resource($resource)['actions'] ?? ['view'];
    }

    public function supports(string $resource, string $action): bool
    {
        return isset($this->resources()[$resource])
            && in_array($action, $this->actionsFor($resource), true);
    }

    public function label(string $resource): string
    {
        return $this->resource($resource)['label'] ?? $resource;
    }

    /**
     * @return class-string<Model>
     */
    public function modelFor(string $resource): string
    {
        return $this->resource($resource)['model'];
    }

    /**
     * Model örneğinden kaynak anahtarını bulur. Policy'ler bu eşlemeyi kullanır.
     */
    public function resourceFor(Model $model): string
    {
        foreach ($this->resources() as $key => $definition) {
            if ($model instanceof $definition['model']) {
                return $key;
            }
        }

        throw new InvalidArgumentException($model::class.' için tanımlı bir yetki kaynağı yok.');
    }
}
