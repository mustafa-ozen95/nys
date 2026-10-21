<x-layout.app :title="$user->name.' yetkileri'">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head :title="$user->name" :subtitle="$user->email">
                <x-slot:actions>
                    <x-ui.badge :variant="$user->role->badge">{{ $user->role->label }}</x-ui.badge>

                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$resourceTabs" />

            <form method="POST" action="{{ route('users.permissions.update', $user) }}"
                  data-confirm="{{ $user->name }} kullanıcısının yetkileri değişecek."
                  data-confirm-title="Yetkileri kaydet"
                  data-confirm-verify="{{ $user->name }}">
                @csrf
                @method('PUT')
                <x-form.version :record="$user" />
                <input type="hidden" name="resource" value="{{ $resource }}">

                <x-ui.panel title="Kaynağın tamamı"
                            :subtitle="'“Rolden gelsin” seçili olan aksiyonda '.$user->role->label.' rolü ne diyorsa o geçerlidir. '
                                .'İzin, rolün vermediğini ekler; yasak, verdiğini bu kişide keser.'">
                    <div class="row row--wrap permission-wildcards">
                        @foreach ($actions as $action)
                            <label class="field field--cell">
                                <span class="field__label">
                                    {{ $actionLabels[$action] }}
                                    @if ($roleWildcard[$action])
                                        <span class="text-sm text-muted">· rolde var</span>
                                    @endif
                                </span>
                                <select class="select select--cell" name="wildcard[{{ $action }}]">
                                    @foreach ($stateOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($wildcard[$action] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </x-ui.panel>

                <div class="permission-records">
                    <x-ui.panel title="Kayıt bazlı istisnalar" flush>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Kayıt</th>
                                        @foreach ($recordActions as $action)
                                            <th>{{ $actionLabels[$action] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($records as $record)
                                        <tr>
                                            <td data-label="Kayıt">
                                                <div class="mono">{{ $record['title'] }}</div>
                                                @if ($record['subtitle'])
                                                    <div class="text-sm text-muted mono">{{ $record['subtitle'] }}</div>
                                                @endif
                                            </td>

                                            {{--
                                                data-label ŞART: dar ekranda tablo bloklara
                                                dönüşür ve sütun başlıkları kaybolur. Etiketsiz
                                                bırakılırsa ekranda adsız "Devral" kutuları
                                                durur; hangisinin "Bağlan" olduğu
                                                anlaşılmaz.
                                            --}}
                                            @foreach ($recordActions as $action)
                                                <td data-label="{{ $actionLabels[$action] }}">
                                                    <select class="select select--cell"
                                                            name="cells[{{ $action }}][{{ $record['id'] }}]"
                                                            aria-label="{{ $record['title'] }} · {{ $actionLabels[$action] }}">
                                                        @foreach ($stateOptions as $value => $label)
                                                            <option value="{{ $value }}"
                                                                @selected($record['states'][$action] === $value)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>

                                                    {{-- Rolün ne dediği: seçim değil, bilgi. --}}
                                                    @if ($roleWildcard[$action])
                                                        <span class="text-sm text-muted">rolde var</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ count($actions) + 1 }}">
                                                <x-ui.empty title="Bu kaynakta kayıt yok" />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-ui.panel>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Yetkileri kaydet</button>
                    <a href="{{ route('users.index') }}" class="btn btn--ghost">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
