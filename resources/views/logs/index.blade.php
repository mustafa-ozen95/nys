<x-layout.app title="Etkinlik kayıtları">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Etkinlik kayıtları</span>
                <span class="column__count">{{ $logs->total() }}</span>

                <form method="GET" class="row row--wrap filter-bar">
                    <x-ui.search :value="$filters['q']" :standalone="false"
                                 placeholder="İşlem veya açıklama" label="Kayıtlarda ara" />

                    <select class="select" name="channel" aria-label="Kanal">
                        @foreach ($channels as $value => $label)
                            <option value="{{ $value }}" @selected($filters['channel'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <select class="select" name="user" aria-label="Kullanıcı">
                        @foreach ($users as $value => $label)
                            <option value="{{ $value }}" @selected((string) $filters['user'] === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <input class="input" type="date" name="from" value="{{ $filters['from'] }}"
                           aria-label="Başlangıç tarihi">
                    <input class="input" type="date" name="to" value="{{ $filters['to'] }}"
                           aria-label="Bitiş tarihi">

                    <button type="submit" class="btn btn--sm">Filtrele</button>

                    @if (array_filter($filters))
                        <a href="{{ route('logs.index') }}" class="btn btn--ghost btn--sm">Temizle</a>
                    @endif
                </form>
            </div>

            <div class="column__body">
                {{--
                    Bütünlük rozeti: kaydın değiştirilmediğinin kanıtı.
                    Her satır kendinden öncekinin özetini taşır; biri
                    değiştirilir ya da silinirse zincir kırılır.
                --}}
                {{--
                    Etkinlik kaydı "kim ne yaptı"yı söyler. Cihazın CEVABI
                    (reddedilen yazmalar, uyarılar, okunamayan eşitlemeler)
                    ayrı bir ekrandadır; ikisi ayrı sorulardır.
                --}}
                <div class="row row--wrap log-integrity">
                    <a href="{{ route('logs.device-operations') }}" class="btn btn--ghost btn--sm">
                        Hata kayıtları
                    </a>
                </div>

                @isset($integrity)
                    <div class="row row--wrap log-integrity">
                        @if ($integrity['ok'])
                            <x-ui.badge variant="green">Kayıt bütünlüğü doğrulandı</x-ui.badge>
                        @else
                            <x-ui.badge variant="red">KAYIT DEĞİŞTİRİLMİŞ</x-ui.badge>
                            <span class="text-sm">İlk bozuk kayıt: #{{ $integrity['broken_id'] }}</span>
                        @endif

                        <span class="text-sm text-muted">
                            {{ $integrity['checked'] }} kayıt · {{ $integrity['checked_at'] }}
                        </span>

                        @if (($integrity['acknowledged'] ?? 0) > 0)
                            <span class="text-sm text-muted">
                                {{ $integrity['acknowledged'] }} kayıt bilinen eski kırık (mühürlü, değişmemiş)
                            </span>
                        @endif

                        @if ($integrity['unprotected'] > 0)
                            <span class="text-sm text-muted">
                                {{ $integrity['unprotected'] }} kayıt zincirden önce yazılmış
                            </span>
                        @endif
                    </div>
                @endisset

                @if ($errors->any())
                    <div class="alert alert--danger">{{ $errors->first() }}</div>
                @endif

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zaman</th>
                                <th>Kullanıcı</th>
                                <th>Kanal</th>
                                <th>İşlem</th>
                                <th>Açıklama</th>
                                <th>Gerekçe</th>
                                <th>Adres</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                <tr>
                                    <td class="table__cell--mono text-muted" data-label="Zaman"><x-ui.time :at="$log->created_at" /></td>
                                    <td data-label="Kullanıcı">{{ $log->user?->name ?? '—' }}</td>
                                    <td data-label="Kanal"><x-ui.badge :variant="$log->channel->badge()">{{ $log->channel->label() }}</x-ui.badge></td>
                                    <td class="table__cell--mono" data-label="İşlem">{{ $log->action }}</td>
                                    <td data-label="Açıklama">{{ $log->description }}</td>

                                    {{-- Kullanıcının kendi yazdığı sebep; zincire girdiği için değişmez. --}}
                                    <td data-label="Gerekçe">{{ $log->reason ?? '—' }}</td>
                                    <td class="table__cell--mono text-muted" data-label="Adres">{{ $log->ip_address ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <x-ui.empty title="Kayıt bulunamadı"
                                                    text="Filtreleri gevşetmeyi deneyin." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $logs->links() }}
            </div>
        </div>
    </div>
</x-layout.app>
