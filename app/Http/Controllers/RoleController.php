<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BadgeVariant;
use App\Http\Requests\Settings\RoleRequest;
use App\Models\Role;
use App\Services\ActivityRecorder;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Rollerin ve hazır yetkilerinin yönetimi.
 *
 * Rol tek başına yetki DEĞİLDİR; hazır bir şablondur. Buradan değiştirmek
 * mevcut kullanıcıların yetkisini KENDİLİĞİNDEN değiştirmez — şablon
 * kullanıcıya yetki ekranından açık istekle uygulanır. Aksi hâlde bir role
 * dokunmak, o roldeki herkesin elle ayarlanmış istisnalarını sessizce silerdi.
 *
 * Yetkilendirme: yalnızca yönetici (SettingPolicy). Rol yetkisi verebilen
 * biri kendine her şeyi verebilirdi.
 */
class RoleController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsCatalog $catalog,
        private PermissionRegistry $registry,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:manageRoles')];
    }

    public function index(): View
    {
        return view('settings.roles', [
            'sections' => $this->catalog->tabs('roles'),
            'roles' => Role::query()->ordered()->withCount('users')->get(),
            'badgeOptions' => collect(BadgeVariant::cases())
                ->mapWithKeys(fn (BadgeVariant $variant): array => [$variant->value => $variant->value])
                ->all(),
        ]);
    }

    public function show(Role $role): View
    {
        return view('settings.role', [
            'sections' => $this->catalog->tabs('roles'),
            'role' => $role,
            'resources' => $this->resources(),
            'actionLabels' => $this->registry->actions(),
            'template' => $role->abilities(),
            'badgeOptions' => collect(BadgeVariant::cases())
                ->mapWithKeys(fn (BadgeVariant $variant): array => [$variant->value => $variant->value])
                ->all(),
            'userCount' => $role->users()->count(),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = DB::transaction(function () use ($request): Role {
            $role = Role::query()->create([
                ...$request->safe()->only(['slug', 'label', 'description', 'badge', 'position']),
                'is_admin' => $request->boolean('is_admin'),
                'is_default' => false,
                'template' => $request->template($this->registry),
            ]);

            $this->applyDefault($request->boolean('is_default'), $role);

            return $role;
        });

        $this->activity->created($role, "Rol eklendi: {$role->label} ({$role->slug})");

        return redirect()->route('settings.roles.show', $role)->with('status', "{$role->label} eklendi.");
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $affected = DB::transaction(function () use ($request, $role): int {
            $role->fill([
                ...$request->safe()->only(['label', 'description', 'badge', 'position']),
                'is_admin' => $request->boolean('is_admin'),
                'template' => $request->template($this->registry),
            ]);

            // Yerleşik rolün kod adı değişmez; istek zaten reddediyor.
            if (! $role->is_system && $request->filled('slug')) {
                $role->slug = $request->string('slug')->toString();
            }

            $role->save();

            $this->applyDefault($request->boolean('is_default'), $role);

            return $role->users()->count();
        });

        /*
         * Rolün yetkileri CANLI: kaydedildiği an o roldeki herkes için
         * geçerli. Uygulanacak bir şablon yok — kopya da yok.
         */
        $this->activity->updated(
            $role,
            "Rol güncellendi: {$role->label} ({$role->slug})"
                .($affected > 0 ? " · roldeki {$affected} kullanıcıyı etkiler" : ''),
        );

        return back()->with('status', $affected > 0
            ? "{$role->label} kaydedildi; roldeki {$affected} kullanıcı için hemen geçerli."
            : "{$role->label} kaydedildi.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (! $role->isDeletable()) {
            return back()->with('error', $role->is_system
                ? 'Yerleşik roller silinemez.'
                : 'Bu rolde kullanıcı var; önce başka bir role taşınmalı.');
        }

        $role->delete();

        $this->activity->deleted($role, "Rol silindi: {$role->label} ({$role->slug})");

        return redirect()->route('settings.roles')->with('status', "{$role->label} silindi.");
    }

    /**
     * Varsayılan rol TEKTİR: yenisi işaretlenince eskisi düşer.
     */
    private function applyDefault(bool $isDefault, Role $role): void
    {
        if (! $isDefault) {
            $role->forceFill(['is_default' => false])->save();

            return;
        }

        Role::query()->where('id', '!=', $role->id)->update(['is_default' => false]);
        $role->forceFill(['is_default' => true])->save();
    }

    /**
     * Şablon ekranındaki kaynaklar: anahtar, etiket ve aksiyonları.
     *
     * @return list<array{key: string, label: string, actions: list<string>}>
     */
    private function resources(): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->registry->label($key),
            'actions' => $this->registry->actionsFor($key),
        ], $this->registry->resourceKeys());
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }
}
