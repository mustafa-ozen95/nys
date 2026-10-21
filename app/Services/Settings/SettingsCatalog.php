<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * config/settings.php içindeki tanımların okunması.
 *
 * Ayar ekranı, doğrulama ve config bindirmesi aynı listeden beslenir; yeni
 * bir ayar eklemek için config dosyasına bir satır yazmak yeterlidir.
 */
class SettingsCatalog
{
    /** @var array<string, SettingDefinition>|null */
    private ?array $items = null;

    public function __construct(private SettingsAccess $access) {}

    /**
     * @return array<string, SettingDefinition>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];

        foreach ((array) config('settings.items', []) as $key => $definition) {
            $items[$key] = SettingDefinition::fromArray($key, $definition);
        }

        return $this->items = $items;
    }

    public function find(string $key): ?SettingDefinition
    {
        return $this->items()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * Bölümler arası gezinme şeridi.
     *
     * Her bölüm kendi sayfasıdır: bütün ayarların tek sayfada durması
     * karışıklık üretir ve telefonda aranan bölüme ulaşmak için sayfayı
     * baştan sona kaydırmak gerekir.
     *
     * @return list<array{label: string, url: string, active: bool}>
     */
    public function tabs(?string $current = null, ?User $user = null): array
    {
        $user ??= request()->user();

        $tabs = [[
            'label' => 'Durum',
            'url' => route('settings.index'),
            'active' => $current === null,
        ]];

        foreach ($this->groups() as $group) {
            // Kendi ekranının içine gömülmüş bölüm şeritte durmaz.
            if ($group['tab'] === false) {
                continue;
            }

            // Göremeyeceği bölüm şeritte de görünmez.
            if ($user !== null && ! $this->access->canView($user, $group)) {
                continue;
            }

            $tabs[] = [
                'label' => $group['label'],
                'url' => route('settings.show', $group['slug']),
                'active' => $group['slug'] === $current,
            ];
        }

        /*
         * Ayar olmayan ekranlar (cihaz parolaları) da aynı şeritte durur:
         * kullanıcı onları Ayarlar'ın altında arıyor ve ayrı bir menü
         * öğesi hem yer kaplar hem de ilişkiyi gizler.
         */
        /*
         * Ayar olmayan ekranlar kendi yeteneklerini taşır: rol yönetimi
         * yalnızca yöneticide, cihaz parolaları ise en az bir switchte
         * parola yetkisi olanda.
         */
        foreach ((array) config('settings.actions', []) as $action) {
            if ($user !== null && isset($action['gate']) && Gate::forUser($user)->denies($action['gate'])) {
                continue;
            }

            $tabs[] = [
                'label' => $action['label'],
                'url' => route($action['route']),
                'active' => $action['slug'] === $current,
            ];
        }

        return $tabs;
    }

    /**
     * Adres parçasına karşılık gelen bölüm.
     *
     * @return array{key: string, slug: string, label: string, description: string, permission_id: ?int, tab: bool, test: ?array<string, string>, items: list<SettingDefinition>}|null
     */
    public function group(string $slug): ?array
    {
        foreach ($this->groups() as $group) {
            if ($group['slug'] === $slug) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Ekrandaki bölümler, her biri kendi tanımlarıyla.
     *
     * @return list<array{key: string, slug: string, label: string, description: string, permission_id: ?int, tab: bool, test: ?array<string, string>, items: list<SettingDefinition>}>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ((array) config('settings.groups', []) as $key => $group) {
            $items = [];

            foreach ($group['keys'] as $itemKey) {
                $definition = $this->find($itemKey);

                if ($definition !== null) {
                    $items[] = $definition;
                }
            }

            $groups[] = [
                'key' => $key,
                'slug' => $group['slug'],
                'label' => $group['label'],
                'description' => $group['description'] ?? '',

                // Bölümün yetki kimliği; kullanıcı izinleri buna bağlı.
                'permission_id' => $group['permission_id'] ?? null,

                // Şeritte ayrı sekme olarak görünsün mü.
                'tab' => $group['tab'] ?? true,

                /*
                 * Bölümün "bağlantıyı sına" düğmesi. Dış bir servise bağlanan
                 * bölümde adresi ve jetonu kaydettikten sonra "çalışıyor mu"
                 * sorusunun ekranda cevabı olmalı.
                 */
                'test' => $group['test'] ?? null,
                'items' => $items,
            ];
        }

        return $groups;
    }
}
