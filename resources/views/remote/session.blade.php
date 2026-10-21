<x-layout.gate :title="$session['label'].' oturumu'" wide>
    <div class="remote-session" data-oturum-jetonu="{{ $token }}">
        <header class="remote-session__head">
            <span class="dot dot--green"></span>
            <span class="mono">{{ $session['label'] }}</span>
            <x-ui.badge variant="blue">{{ Str::upper($session['protocol']) }}</x-ui.badge>

            <span class="spacer"></span>

            <button type="button" class="btn btn--danger btn--sm" data-oturum-kapat>Oturumu kapat</button>
        </header>

        <iframe class="remote-session__frame"
                src="{{ $session['path'] }}"
                title="{{ $session['label'] }} uzak oturumu"
                allow="clipboard-read; clipboard-write"></iframe>
    </div>
</x-layout.gate>
