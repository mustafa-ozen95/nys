<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Services\Authorization\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yetki matrisinin kaydedilmesi.
 *
 * Form her hücre için üç durumdan birini gönderir: inherit (kayda özel kural
 * yok), allow, deny. Bu istek onları resolver'ın beklediği listelere çevirir;
 * çeviri controller'da tekrarlanmaz.
 */
class PermissionMatrixRequest extends FormRequest
{
    private const STATES = ['inherit', 'allow', 'deny'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $registry = app(PermissionRegistry::class);
        $actions = array_keys($registry->actions());

        return [
            'resource' => ['required', 'string', Rule::in($registry->resourceKeys())],
            'wildcard' => ['array'],
            'wildcard.*' => ['string', Rule::in(self::STATES)],
            'cells' => ['array'],
            'cells.*' => ['array'],
            'cells.*.*' => ['string', Rule::in(self::STATES)],
        ];
    }

    public function resource(): string
    {
        return $this->validated('resource');
    }

    /**
     * Kaynağın tamamında İZİN verilen aksiyonlar.
     *
     * @return array<int, string>
     */
    public function wildcardActions(): array
    {
        return $this->wildcardWithState('allow');
    }

    /**
     * Kaynağın tamamında YASAKLANAN aksiyonlar: rolün verdiğini bu kişide
     * keser.
     *
     * @return array<int, string>
     */
    public function wildcardDenied(): array
    {
        return $this->wildcardWithState('deny');
    }

    /** @return array<int, string> */
    private function wildcardWithState(string $state): array
    {
        $registry = app(PermissionRegistry::class);
        $actions = $registry->actionsFor($this->resource());

        return collect($this->validated('wildcard', []))
            ->filter(fn (string $value, string $action): bool => $value === $state && in_array($action, $actions, true))
            ->keys()
            ->all();
    }

    /**
     * @return array<string, array<int, int>>
     */
    public function allowedRecords(): array
    {
        return $this->recordsWithState('allow');
    }

    /**
     * @return array<string, array<int, int>>
     */
    public function deniedRecords(): array
    {
        return $this->recordsWithState('deny');
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function recordsWithState(string $state): array
    {
        $resolution = $this->recordKeys();

        return collect($this->validated('cells', []))
            ->map(fn (array $cells): array => collect($cells)
                ->filter(fn (string $value): bool => $value === $state)
                ->keys()
                ->map(fn (string $outerKey): mixed => $resolution($outerKey))
                ->filter(fn (mixed $id): bool => $id !== null)
                ->values()
                ->all())
            ->filter(fn (array $ids): bool => $ids !== [])
            ->all();
    }

    /**
     * Formdan gelen DIŞ anahtarı iç anahtara çevirir.
     *
     * Form uuid taşır (bkz. PermissionMatrixService): sıralı birincil anahtar
     * dışarı çıkmaz. Tanınmayan bir uuid sessizce DÜŞER — elle yazılmış bir
     * değer yetki üretmemeli.
     *
     * Config'den gelen kaynaklarda (panel ayarları) kayıt anahtarı zaten bir
     * slug'dır; orada çeviri yoktur.
     *
     * @return callable(string): mixed
     */
    private function recordKeys(): callable
    {
        $definition = app(PermissionRegistry::class)->resource($this->resource());

        if (isset($definition['records'])) {
            return static fn (string $key): string => $key;
        }

        /** @var class-string<Model> $model */
        $model = $definition['model'];

        if ((new $model)->getRouteKeyName() !== 'uuid') {
            return static fn (string $key): int => (int) $key;
        }

        $match = $model::query()->pluck('id', 'uuid')->all();

        return static fn (string $key): ?int => $match[$key] ?? null;
    }
}
