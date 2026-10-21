<x-layout.app title="Hata kayıtları">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Hata kayıtları</span>
                <span class="column__count">{{ $operations->total() }}</span>

                <form method="GET" class="row row--wrap filter-bar">
                    <x-ui.search :value="$filters['q'] ?? null" :standalone="false"
                                 placeholder="Cihazın mesajı, özet veya cihaz adı" label="Hata kayıtlarında ara" />

                    <select class="select" name="status" aria-label="Sonuç">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <select class="select" name="operation" aria-label="İşlem">
                        @foreach ($operations_list as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['operation'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <select class="select" name="switch" aria-label="Cihaz">
                        @foreach ($switches as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['switch'] ?? '') === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <input class="input" type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                           aria-label="Başlangıç tarihi">
                    <input class="input" type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                           aria-label="Bitiş tarihi">

                    <button type="submit" class="btn btn--sm">Filtrele</button>

                    @if (array_filter($filters))
                        <a href="{{ route('logs.device-operations') }}" class="btn btn--ghost btn--sm">Temizle</a>
                    @endif
                </form>
            </div>

            <div class="column__body">
                {{--
                    Etkinlik kaydı "kim ne yaptı"yı söyler; bu ekran cihazın
                    CEVABINI gösterir. İkisi ayrı sorulardır ve ayrı bakılır.
                --}}
                <div class="row row--wrap log-integrity">
                    <a href="{{ route('logs.index') }}" class="btn btn--ghost btn--sm">Etkinlik kayıtlarına git</a>

                    @if ($summary['failed'] > 0)
                        <x-ui.badge variant="red">Son 24 saatte {{ $summary['failed'] }} başarısız işlem</x-ui.badge>
                    @else
                        <x-ui.badge variant="green">Son 24 saatte başarısız işlem yok</x-ui.badge>
                    @endif

                    @if ($summary['warning'] > 0)
                        <x-ui.badge variant="orange">{{ $summary['warning'] }} uyarı</x-ui.badge>
                    @endif

                    <span class="text-sm text-muted">
                        Cihazın reddettiği yazmalar, döndürdüğü uyarılar ve okunamayan eşitlemeler burada durur.
                    </span>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zaman</th>
                                <th>Cihaz</th>
                                <th>İşlem</th>
                                <th>Sonuç</th>
                                <th>Cihazın cevabı</th>
                                <th>Kullanıcı</th>
                                <th>Süre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($operations as $operation)
                                <tr>
                                    <td class="table__cell--mono text-muted" data-label="Zaman">
                                        <x-ui.time :at="$operation->created_at" />
                                    </td>
                                    <td data-label="Cihaz">{{ $operation->target }}</td>
                                    <td data-label="İşlem">
                                        {{ $operation->operation_label }}
                                        @if ($operation->stage)
                                            <span class="text-sm text-muted">· {{ $operation->stage_label }}</span>
                                        @endif
                                    </td>
                                    <td data-label="Sonuç">
                                        <x-ui.badge :variant="$operation->status_badge">{{ $operation->status_label }}</x-ui.badge>
                                    </td>
                                    <td data-label="Cihazın cevabı">
                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="islem-{{ $operation->uuid }}">
                                            {{ \Illuminate\Support\Str::limit($operation->device_message ?? $operation->summary, 90) }}
                                        </button>
                                    </td>
                                    <td data-label="Kullanıcı">{{ $operation->user?->name ?? 'sistem' }}</td>
                                    <td class="table__cell--mono text-muted" data-label="Süre">
                                        {{ $operation->duration_ms !== null ? $operation->duration_ms.' ms' : '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <x-ui.empty title="Cihaz işlem kaydı yok"
                                                    text="Cihaz bir işlemi reddettiğinde ya da uyarı döndürdüğünde burada görünür." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $operations->links() }}
            </div>
        </div>
    </div>

    {{-- Ayrıntı pencereleri: cihazın tam cevabı, gönderilen belge ve fark. --}}
    @foreach ($operations as $operation)
        <x-ui.modal :id="'islem-'.$operation->uuid" :title="$operation->operation_label.' · '.$operation->target" wide>
            <div class="modal__body stack">
                <x-ui.facts :rows="[
                    'Zaman' => $operation->created_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i:s'),
                    'İşlem' => $operation->operation_label,
                    'Adım' => $operation->stage ? $operation->stage_label : null,
                    'Sonuç' => $operation->status_label,
                    'Hata cinsi' => $operation->kind,
                    'Kullanıcı' => $operation->user?->name ?? 'sistem',
                    'Süre' => $operation->duration_ms !== null ? $operation->duration_ms.' ms' : null,
                    'Özet' => $operation->summary,
                ]" />

                @if ($operation->device_message)
                    <div class="stack stack--tight">
                        <span class="eyebrow">Cihazın cevabı</span>
                        <pre class="code-block">{{ $operation->device_message }}</pre>
                    </div>
                @endif

                {{--
                    Her rpc-error'un kendi alanları: hangi satırda (path),
                    hangi kural (tag), hangi süreç (daemon). Tek dizeye
                    indirilirse sorunu bulan bilgi kaybolur.
                --}}
                @if ($operation->errors)
                    <div class="stack stack--tight">
                        <span class="eyebrow">Hata ayrıntısı</span>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr><th>Mesaj</th><th>Eleman</th><th>Yer</th><th>Etiket</th><th>Süreç</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($operation->errors as $error)
                                        <tr>
                                            <td data-label="Mesaj">{{ $error['message'] ?? '—' }}</td>

                                            {{--
                                                Cihaz hangi etiketi anlamadığını söylüyor
                                                (bad-element); sorunu bulan en hızlı alan bu.
                                            --}}
                                            <td class="table__cell--mono" data-label="Eleman">{{ $error['element'] ?? '—' }}</td>
                                            <td class="table__cell--mono" data-label="Yer">{{ $error['path'] ?? '—' }}</td>
                                            <td class="table__cell--mono" data-label="Etiket">{{ $error['tag'] ?? '—' }}</td>
                                            <td class="table__cell--mono" data-label="Süreç">{{ $error['daemon'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($operation->warnings)
                    <div class="stack stack--tight">
                        <span class="eyebrow">Cihazın uyarıları</span>
                        <pre class="code-block">{{ implode("\n", $operation->warnings) }}</pre>
                    </div>
                @endif

                @if ($operation->document)
                    <div class="stack stack--tight">
                        <span class="eyebrow">Gönderilen yapılandırma (maskeli)</span>
                        <pre class="code-block">{{ $operation->document }}</pre>
                    </div>
                @endif

                @if ($operation->diff)
                    <div class="stack stack--tight">
                        <span class="eyebrow">Cihazın ürettiği fark (maskeli)</span>
                        <pre class="code-block">{{ $operation->diff }}</pre>
                    </div>
                @endif
            </div>
        </x-ui.modal>
    @endforeach
</x-layout.app>
