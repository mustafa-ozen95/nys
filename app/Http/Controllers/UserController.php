<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Requests\User\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class UserController extends Controller implements HasMiddleware
{
    public function __construct(
        private ActivityRecorder $activity,
    ) {}

    /**
     * Yetkilendirme Policy'de yapılır; burada yalnızca hangi metodun hangi
     * yeteneği gerektirdiği bildirilir.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.User::class, only: ['index']),

            /*
             * 'show' YOK: rota tanımı onu kapatıyor (routes/web.php,
             * ->except('show')). Buradaki tanım hiçbir zaman çalışmıyordu;
             * çalışmayan bir kural, okuyan kişiye var olmayan bir kapı
             * gösterir.
             */
            new Middleware('can:create,'.User::class, only: ['create', 'store']),
            new Middleware('can:update,user', only: ['edit', 'update']),
            new Middleware('can:delete,user', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        return view('users.index', [
            'users' => User::query()
                ->visibleTo($request->user())
                ->search($request->string('q')->toString() ?: null)
                ->orderBy('name')
                ->get(),
            'query' => $request->string('q')->toString(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', $this->formData(null));
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = User::create($request->userAttributes());

        /*
         * YETKİ KOPYALANMAZ. Rol canlı okunuyor (bkz. RolePermissions):
         * kullanıcı rolünün yetkileriyle doğar ve rol değiştiğinde onunla
         * birlikte değişir. Buradaki satırlar yalnızca istisna tutar.
         */
        $this->activity->created($user, "Kullanıcı eklendi: {$user->email} · {$user->role->label}");

        return redirect()
            ->route('users.index')
            ->with('status', "{$user->name} eklendi.");
    }

    public function edit(User $user): View
    {
        return view('users.edit', $this->formData($user));
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->userAttributes());

        /*
         * ROL DEĞİŞİNCE YETKİLER KENDİLİĞİNDEN DEĞİŞİR: rol canlı okunuyor,
         * kullanıcıya kopyalanmıyor. Kişiye özel istisnalar ise yerinde
         * kalır — onlar bu kişiye verilmişti, role değil.
         */
        $this->activity->updated($user, "Kullanıcı güncellendi: {$user->email}");

        return redirect()
            ->route('users.index')
            ->with('status', "{$user->name} güncellendi.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $name = $user->name;

        $this->activity->deleted($user, "Kullanıcı silindi: {$user->email}");
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('status', "{$name} silindi.");
    }

    /**
     * Form ekranının hazır verisi.
     *
     * @return array<string, mixed>
     */
    private function formData(?User $user): array
    {
        return [
            'user' => $user,
            /*
             * Roller kayıttır; seçenek listesi uuid taşır (sıralı birincil
             * anahtar dışarı çıkmaz).
             */
            'roleOptions' => Role::query()->ordered()->pluck('label', 'uuid')->all(),
            'statusOptions' => $this->labels(UserStatus::cases()),
            'action' => $user === null ? route('users.store') : route('users.update', $user),
            'method' => $user === null ? 'POST' : 'PUT',
            'submitLabel' => $user === null ? 'Kullanıcıyı ekle' : 'Değişiklikleri kaydet',
        ];
    }

    /**
     * @param  array<int, UserStatus>  $cases
     * @return array<string, string>
     */
    private function labels(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (UserStatus $case): array => [$case->value => $case->label()])
            ->all();
    }
}
