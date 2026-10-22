<?php

declare(strict_types=1);

namespace App\View\Components\Nav;

use App\Models\ActivityLog;
use App\Models\AddressBlock;
use App\Models\AnnouncementRecipient;
use App\Models\BgpNeighbor;
use App\Models\ConfigurationPush;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Illuminate\View\View;

class Topbar extends Component
{
    public string $initials;

    /** @var array<int, array{label: string, url: string, active: bool}> */
    public array $sections;

    /** @var array<int, array{label: string, url?: string}> */
    public array $crumbs;

    /**
     * @param  array<int, array{label: string, url?: string}>  $crumbs
     */
    public function __construct(array $crumbs = [])
    {
        $this->initials = self::initialsFor(auth()->user()?->name);
        $this->sections = $this->visibleSections();
        $this->crumbs = $this->trail($crumbs);
    }

    /**
     * Kırıntı yolundan gereksiz tekrarı ayıklar.
     *
     * Menüde vurgulu bölüm zaten nerede olunduğunu söyler; aynı adı bir de
     * kırıntı yolunda göstermek bilgi katmaz. Geriye gerçek bir yol kalmazsa
     * kırıntı yolu hiç basılmaz.
     *
     * @param  array<int, array{label: string, url?: string}>  $crumbs
     * @return array<int, array{label: string, url?: string}>
     */
    private function trail(array $crumbs): array
    {
        $active = collect($this->sections)->firstWhere('active', true)['label'] ?? null;

        if ($active !== null && ($crumbs[0]['label'] ?? null) === $active) {
            array_shift($crumbs);
        }

        return count($crumbs) < 2 ? [] : array_values($crumbs);
    }

    /**
     * Menüde yalnızca kullanıcının erişebildiği bölümler görünür.
     *
     * @return array<int, array{label: string, url: string, active: bool}>
     */
    private function visibleSections(): array
    {
        $candidates = [
            ['label' => 'Switchler', 'route' => 'switches.index', 'ability' => 'viewAny', 'model' => NetworkSwitch::class],
            ['label' => 'Adresleme', 'route' => 'addressing.index', 'ability' => 'viewAny', 'model' => AddressBlock::class],
            ['label' => 'BGP', 'route' => 'bgp.index', 'ability' => 'viewAny', 'model' => BgpNeighbor::class],
            ['label' => 'Prefix anonsu', 'route' => 'announcements.create', 'ability' => 'viewAny', 'model' => AnnouncementRecipient::class],
            ['label' => 'Sunucular', 'route' => 'servers.index', 'ability' => 'viewAny', 'model' => Server::class],
            ['label' => 'Kullanıcılar', 'route' => 'users.index', 'ability' => 'viewAny', 'model' => User::class],
            /*
             * "Kayıtlar" iki ekranın ORTAK adıdır ve ikisi de /logs
             * altında durur; menü sahada aranana, hata kayıtlarına açılır.
             * Etkinlik kaydı oradan bir tıkla ulaşılabilir ve iki ekran
             * birbirine bağlı.
             *
             * Vurgu ikisinde de yanar: aktiflik "logs.*" desenine bakıyor.
             */
            ['label' => 'Kayıtlar', 'route' => 'logs.device-operations', 'ability' => 'viewAny', 'model' => ActivityLog::class],
            ['label' => 'Config', 'route' => 'provisioning.create', 'ability' => 'viewAny', 'model' => ConfigurationPush::class],
            ['label' => 'Ayarlar', 'route' => 'settings.index', 'ability' => 'viewAny', 'model' => Setting::class],
        ];

        $sections = [];

        foreach ($candidates as $candidate) {
            if (! Gate::allows($candidate['ability'], $candidate['model'])) {
                continue;
            }

            $sections[] = [
                'label' => $candidate['label'],
                'url' => route($candidate['route']),
                'active' => Route::is(Str::before($candidate['route'], '.').'.*'),
            ];
        }

        return $sections;
    }

    public static function initialsFor(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '?';
        }

        return Str::of($name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    public function render(): View
    {
        return view('components.nav.topbar');
    }
}
