@if ($show)
    {{--
        YALNIZCA YÖNETİCİYE: güncellemeyi o başlatır. Ayrıntı ve yeni
        commit'lerin listesi Ayarlar → Güncelleme'de.
    --}}
    <div class="alert alert--info alert--spaced" role="status">
        <strong>Panelin yeni sürümü var.</strong>
        Çalışan sürüm {{ $behind }} commit geride.
        <a href="{{ route('settings.updates') }}">Ayarlar → Güncelleme</a>
    </div>
@endif
