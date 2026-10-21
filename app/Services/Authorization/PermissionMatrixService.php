<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Yetki matrisi ekranının verisini hazırlar.
 *
 * Ekran İKİ KATMANI birden gösterir: rolün canlı yetkisi (bilgi olarak,
 * "rolde var") ve kişinin istisnası (seçilebilir). "Rolden gelsin" seçilince
 * kişinin satırı silinir ve rol ne diyorsa o geçerli olur.
 *
 * Blade yalnızca hazır dizileri basar.
 */
class PermissionMatrixService
{
    public function __construct(
        private RolePermissions $roles,
        private PermissionRegistry $registry,
        private UserPermissionResolver $resolver,
    ) {}

    /**
     * @return array{
     *     user: User,
     *     resource: string,
     *     resources: array<string, string>,
     *     actions: array<int, string>,
     *     recordActions: array<int, string>,
     *     actionLabels: array<string, string>,
     *     wildcard: array<string, string>,
     *     records: Collection<int, array<string, mixed>>
     * }
     */
    public function forUser(User $user, ?string $resource = null): array
    {
        $resource = $this->resolveResource($resource);
        $matrix = $this->resolver->matrixFor($user);
        $actions = $this->registry->actionsFor($resource);

        return [
            'user' => $user,
            'resource' => $resource,
            'resources' => $this->resourceLabels(),
            'actions' => $actions,
            'recordActions' => $recordActions = $this->recordActions($actions),
            'actionLabels' => $this->registry->actions(),
            'wildcard' => $this->wildcardState($matrix, $resource, $actions),

            /* Rolün bu kaynakta verdikleri: seçilebilir değil, BİLGİ. */
            'roleWildcard' => $this->roleWildcard($user, $resource, $actions),
            'records' => $this->records($resource, $matrix, $recordActions, $user),
            'resourceTabs' => $this->tabs($user, $resource),
            'stateOptions' => [
                'inherit' => 'Rolden gelsin',
                'allow' => 'İzin ver',
                'deny' => 'Reddet',
            ],
        ];
    }

    /**
     * Kayıt bazında anlamı olan aksiyonlar.
     *
     * "Oluştur" listede YOKTUR: yaratma bir kayda bağlı değildir, yetki
     * sorusu kayıt daha ortada yokken sorulur ve policy'ler onu kayıtsız
     * çağırır. Sütun olarak gösterilseydi hiçbir zaman işe yaramayan bir
     * seçim sunulurdu.
     *
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    private function recordActions(array $actions): array
    {
        return array_values(array_filter(
            $actions,
            static fn (string $action): bool => $action !== 'create',
        ));
    }

    /**
     * @return array<int, array{label: string, url: string, active: bool}>
     */
    private function tabs(User $user, string $current): array
    {
        return collect($this->registry->resourceKeys())
            ->map(fn (string $key): array => [
                'label' => $this->registry->label($key),
                'url' => route('users.permissions', ['user' => $user, 'resource' => $key]),
                'active' => $key === $current,
            ])
            ->all();
    }

    private function resolveResource(?string $resource): string
    {
        $keys = $this->registry->resourceKeys();

        return in_array($resource, $keys, true) ? $resource : $keys[0];
    }

    /**
     * @return array<string, string>
     */
    private function resourceLabels(): array
    {
        return collect($this->registry->resourceKeys())
            ->mapWithKeys(fn (string $key): array => [$key => $this->registry->label($key)])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @param  array<int, string>  $actions
     * @return array<string, bool>
     */
    private function wildcardState(array $matrix, string $resource, array $actions): array
    {
        return collect($actions)
            ->mapWithKeys(fn (string $action): array => [
                $action => $matrix[$resource][$action]['wildcard'] ?? 'inherit',
            ])
            ->all();
    }

    /**
     * Rolün bu kaynakta verdiği aksiyonlar — ekranda "rolde var" yazısı.
     *
     * @param  array<int, string>  $actions
     * @return array<string, bool>
     */
    private function roleWildcard(User $user, string $resource, array $actions): array
    {
        return collect($actions)
            ->mapWithKeys(fn (string $action): array => [
                $action => $this->roles->grants($user->role, $resource, $action),
            ])
            ->all();
    }

    /**
     * Kayıt bazlı istisnaların listesi.
     *
     * Kullanıcı kaynağında kişinin KENDİ kaydı listelenmez: kendi üzerinde
     * "düzenle" yetkisi vermek, kullanıcıya kendi hesabını yönetme kapısı
     * açar ve yetki ekranının amacı bunun tersidir. Kendi hesabını silmesi
     * zaten policy tarafından engelli; liste de bunu ima etmemeli.
     *
     * @param  array<string, mixed>  $matrix
     * @param  array<int, string>  $actions
     * @return Collection<int, array<string, mixed>>
     */
    private function records(string $resource, array $matrix, array $actions, User $user): Collection
    {
        $definition = $this->registry->resource($resource);

        /*
         * Kayıtları veritabanından GELMEYEN kaynaklar var: panel ayarlarının
         * bölümleri config dosyasında tanımlı. Onlar listelerini kendileri
         * verir (bkz. PermissionRecords).
         */
        if (isset($definition['records'])) {
            return collect(app($definition['records'])->records())
                ->map(fn (array $record): array => [
                    'id' => $record['id'],
                    'title' => $record['title'],
                    'subtitle' => $record['subtitle'] ?? null,
                    'states' => collect($actions)
                        ->mapWithKeys(fn (string $action): array => [
                            $action => $this->stateFor($matrix, $resource, $action, $record['id']),
                        ])
                        ->all(),
                ]);
        }

        /** @var class-string<Model> $model */
        $model = $definition['model'];

        return $model::query()
            ->when(
                $model === User::class,
                fn ($query) => $query->whereKeyNot($user->getKey()),
            )
            ->orderBy($definition['order'] ?? 'id')
            ->get()
            ->map(fn (Model $record): array => [
                /*
                 * DIŞARI UUID ÇIKAR.
                 *
                 * Sıralı birincil anahtar form değerinde ve DOM kimliğinde
                 * duruyordu: kaç kaydınız olduğunu söyler ve komşu kaydı
                 * denemeye davet eder. Karar hâlâ iç anahtarla veriliyor
                 * (aşağıdaki stateFor); dışarı çıkan yalnızca rota anahtarı.
                 */
                'id' => $record->getRouteKey(),
                'title' => $record->{$definition['title']},
                'subtitle' => $definition['subtitle'] ?? null ? $record->{$definition['subtitle']} : null,
                'states' => collect($actions)
                    ->mapWithKeys(fn (string $action): array => [
                        $action => $this->stateFor($matrix, $resource, $action, $record->getKey()),
                    ])
                    ->all(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $matrix
     */
    private function stateFor(array $matrix, string $resource, string $action, int|string $id): string
    {
        $bucket = $matrix[$resource][$action] ?? null;

        return match (true) {
            in_array($id, $bucket['denied'] ?? [], true) => 'deny',
            in_array($id, $bucket['allowed'] ?? [], true) => 'allow',
            default => 'inherit',
        };
    }
}
