<x-layout.app title="Kullanıcılar">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Kullanıcılar</span>
                <span class="column__count">{{ $users->count() }}</span>

                <x-ui.search name="q" :value="$query"
                            placeholder="Ad veya e-posta" label="Kullanıcı filtrele" />

                <span class="spacer"></span>

                @can('create', App\Models\User::class)
                    <a href="{{ route('users.create') }}" class="btn btn--primary btn--sm">Yeni kullanıcı</a>
                @endcan
            </div>

            <div class="column__body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ad soyad</th>
                                <th>E-posta</th>
                                <th>Rol</th>
                                <th>Durum</th>
                                <th>Son giriş</th>
                                <th class="table__cell--tight"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $user)
                                <tr>
                                    <td data-label="Ad soyad">{{ $user->name }}</td>
                                    <td class="table__cell--mono text-muted" data-label="E-posta">{{ $user->email }}</td>
                                    <td data-label="Rol"><x-ui.badge :variant="$user->role->badge">{{ $user->role->label }}</x-ui.badge></td>
                                    <td data-label="Durum"><x-ui.badge :variant="$user->status->badge()">{{ $user->status->label() }}</x-ui.badge></td>
                                    <td class="table__cell--mono text-muted" data-label="Son giriş">{{ $user->last_login_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="table__cell--tight">
                                        <div class="table__row-actions">
                                            @can('managePermissions', $user)
                                                <a href="{{ route('users.permissions', $user) }}" class="btn btn--sm">Yetkiler</a>
                                            @endcan

                                            @can('update', $user)
                                                <a href="{{ route('users.edit', $user) }}" class="btn btn--sm">Düzenle</a>
                                            @endcan

                                            @can('delete', $user)
                                                <x-ui.delete-form :action="route('users.destroy', $user)"
                                                                  confirm="{{ $user->name }} silinecek."
                                                                  title="Kullanıcıyı sil"
                                                                  verify="{{ $user->name }}" />
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <x-ui.empty title="Kullanıcı bulunamadı" />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-layout.app>
