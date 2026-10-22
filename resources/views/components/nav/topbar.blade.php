<header class="topbar">
    {{--
        Ad TEK KAYNAKTAN gelir (config/app.php → APP_NAME).

        Elle yazılan bir ad, giriş ekranının config'ten okuduğu adla
        ayrışabilir. Adı değiştirmek isteyen .env'deki APP_NAME'i değiştirir,
        iki ekran birden döner.
    --}}
    <a href="{{ route('overview') }}" class="topbar__mark"
       title="Network Yönetim Sistemi">{{ config('app.name') }}</a>

    <nav class="topbar__nav" aria-label="Bölümler">
        @foreach ($sections as $section)
            <a href="{{ $section['url'] }}"
               class="topbar__link"
               @if ($section['active']) aria-current="page" @endif>{{ $section['label'] }}</a>
        @endforeach
    </nav>

    <span class="topbar__spacer"></span>

    @isset($counts)
        <div class="topbar__counts">{{ $counts }}</div>
    @endisset

    {{--
        Bildirimler: olan biten. Cihazda olan bir şey, onu başlatan kişinin
        değil AĞIN olayıdır — tamamlanan yazmalar ve cihazın reddettiği
        işlemler herkese görünür (bkz. NotificationFeed).
    --}}
    @auth
        <div class="notification" data-notification>
            <button type="button" class="btn btn--ghost btn--icon btn--sm"
                    data-notification-open aria-expanded="false" aria-label="Bildirimler">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8" stroke-linejoin="round" />
                    <path d="M13.7 21a2 2 0 0 1-3.4 0" stroke-linecap="round" />
                </svg>
                <span class="notification__counter" data-notification-counter hidden>0</span>
            </button>

            <div class="notification__panel" data-notification-panel hidden role="dialog" aria-label="Bildirimler">
                <header class="notification__header">
                    <span>Bildirimler</span>

                    {{--
                        OKUNDU AÇIK BİR EYLEMDİR: paneli açmak tek başına
                        işaretlemez. Kullanıcı bakmadan açıp kapatabilir ve
                        sayacı kaybetmek, görülmemiş bir hatayı kaybetmektir.

                        "Okundu" kişiye özeldir; biri işaretledi diye
                        ötekinin sayacı sıfırlanmaz.
                    --}}
                    <span class="notification__actions">
                        <button type="button" class="btn btn--ghost btn--sm"
                                data-notification-read>Tümünü okundu işaretle</button>

                        <a href="{{ route('logs.device-operations') }}"
                           class="text-sm" data-notification-all>Tümü</a>
                    </span>
                </header>

                <div class="notification__list" data-notification-list>
                    <p class="notification__empty">Yükleniyor…</p>
                </div>
            </div>
        </div>
    @endauth

    <button type="button" class="btn btn--ghost btn--icon btn--sm" data-theme-toggle aria-label="Temayı değiştir">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"
                  stroke-linecap="round" />
        </svg>
    </button>

    {{--
        Hesap menüsü.

        Çıkış kimlik göstergesinin altında, hesap işleriyle birlikte durur:
        üst menü, sık kullanılmayan ama yanlışlıkla basılması can sıkıcı bir
        düğme için fazla göze batan bir yerdir.
    --}}
    <div class="user-menu" data-menu>
        <button type="button" class="user-menu__button" data-menu-open
                aria-haspopup="true" aria-expanded="false" aria-label="Hesap menüsü">
            <span class="topbar__avatar" aria-hidden="true">{{ $initials }}</span>
            <span class="topbar__name">{{ auth()->user()?->name }}</span>
            <svg class="user-menu__arrow" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6"
                      stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <div class="user-menu__list" data-menu-list hidden>
            <a href="{{ route('account.profile') }}" class="user-menu__item">Hesabım</a>

            {{--
                Merkezin hızlı erişim sayfası, YENİ SEKMEDE: panelde yapılan
                iş yarıda kalmasın. Adres merkez
                girişinin kullandığı ayardan gelir; merkez kapalı kurulumda
                ölü bir bağlantı göstermemek için yalnızca açıkken çizilir.
            --}}
            @if (config('identity.acik'))
                <a href="{{ config('identity.url') }}" class="user-menu__item" target="_blank" rel="noopener">Hızlı Erişim</a>
            @endif

            {{--
                Ayrıntı düzeyi. Basit modda seyrek kullanılan alanlar çizilmez
                ve panel onlara DOKUNMAZ; gizlemek silmek değildir.

                Yetki değil, kişisel görünüm tercihi olduğu için hesap
                menüsünde duruyor; üst çubukta dar ekranı sıkıştırırdı.
            --}}
            {{-- Gelişmiş moda geçemeyen kullanıcıya düğme gösterilmez (rol yetkisi "Gelişmiş mod"). --}}
            @if ($gelismisModaGecebilir ?? false)
            <form method="POST" action="{{ route('panel-mode.toggle') }}">
                @csrf
                {{-- Etiket EYLEMİ söyler; mevcut hâli söyleyen bir düğme
                     "şu an bu mu, yoksa buna mı geçecek?" diye okunur. --}}
                <button type="submit" class="user-menu__item"
                        title="{{ $panelMode->other()->description() }}">
                    {{ $panelMode->other()->label() }} moda geç
                </button>
            </form>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="user-menu__item user-menu__item--logout">Çıkış</button>
            </form>
        </div>
    </div>
</header>
