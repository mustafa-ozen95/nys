<x-layout.app title="Roller · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Rol hazır bir yetki şablonudur. Buradan değiştirmek mevcut kullanıcıların yetkisini kendiliğinden değiştirmez; şablon yetki ekranından açık istekle uygulanır." />

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            <x-ui.panel title="Roller" subtitle="Varsayılan rol, yönetici olmayan biri kullanıcı açtığında verilir.">
                <x-slot:actions>
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="role-add">Rol ekle</button>
                </x-slot:actions>

                @foreach ($roles as $role)
                    <div class="grid-row grid-row--static setting-row">
                        <span>
                            <a href="{{ route('settings.roles.show', $role) }}" class="mono">{{ $role->slug }}</a>
                        </span>
                        <span>
                            <x-ui.badge :variant="$role->badge">{{ $role->label }}</x-ui.badge>
                            @if ($role->is_admin)
                                <x-ui.badge variant="red">yönetici</x-ui.badge>
                            @endif
                            @if ($role->is_default)
                                <x-ui.badge variant="green">varsayılan</x-ui.badge>
                            @endif
                        </span>
                        <span class="text-muted truncate">
                            {{ $role->users_count }} kullanıcı ·
                            {{ $role->is_admin ? 'bütün kontrolleri geçer' : count($role->abilities()).' kaynakta hazır yetki' }}
                        </span>
                        <span class="text-right">
                            <a href="{{ route('settings.roles.show', $role) }}" class="btn btn--sm">Düzenle</a>
                        </span>
                    </div>
                @endforeach
            </x-ui.panel>
        </div>
    </div>

    <x-ui.modal id="role-add" title="Rol ekle">
        <form method="POST" action="{{ route('settings.roles.store') }}"
              data-confirm="Yeni bir rol eklenecek."
              data-confirm-title="Rol ekle">
            @csrf
            <div class="modal__body stack">
                <x-form.field name="label" label="Rol adı" id="role-name">
                    <x-form.input name="label" id="role-name" required />
                </x-form.field>

                <x-form.field name="slug" label="Kod adı" id="role-code"
                              hint="Küçük harfle başlar; harf, rakam ve tire. Sonradan değiştirilebilir ama kod tarafındaki kimliktir.">
                    <x-form.input name="slug" id="role-code" mono required placeholder="saha-ekibi" />
                </x-form.field>

                <x-form.field name="description" label="Açıklama" id="role-description">
                    <x-form.input name="description" id="role-description" />
                </x-form.field>

                <x-form.field name="badge" label="Renk" id="role-color">
                    <x-form.select name="badge" id="role-color" :options="$badgeOptions" selected="gray" />
                </x-form.field>

                {{-- Yönetici rolü bütün kontrolleri geçer; şablonu olmaz. --}}
                <x-form.field name="is_admin" label="Yönetici" id="role-admin"
                              hint="Bütün yetki kontrollerini geçer. Hazır yetki şablonu anlamsızdır.">
                    <label class="checkbox">
                        <input type="checkbox" name="is_admin" value="1" id="role-admin">
                        <span>Bütün kontrolleri geçsin</span>
                    </label>
                </x-form.field>
            </div>
            <x-ui.modal-actions submit="Ekle" />
        </form>
    </x-ui.modal>
</x-layout.app>
