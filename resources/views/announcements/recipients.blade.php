<x-layout.app title="Anons alıcıları">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Anons alıcıları"
                            subtitle="Talebin kime, hangi dilde gideceği burada belirlenir.">
                <x-slot:actions>
                    @can('create', App\Models\AnnouncementRecipient::class)
                        <button type="button" class="btn btn--primary btn--sm" data-modal-open="alici-ekle">
                            Alıcı ekle
                        </button>
                    @endcan
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            <x-ui.panel title="Alıcılar" flush>
                @forelse ($recipients as $recipient)
                    <details class="mail-log">
                        <summary class="mail-log__head recipient-row">
                            <x-ui.badge :variant="$recipient->locale->badge()">{{ $recipient->locale->short() }}</x-ui.badge>
                            <span class="recipient-row__ad">{{ $recipient->label }}</span>
                            <span class="mono text-sm text-muted recipient-row__mail">{{ $recipient->email }}</span>
                            <x-ui.badge :variant="$recipient->is_active ? 'green' : 'gray'">
                                {{ $recipient->is_active ? 'Aktif' : 'Pasif' }}
                            </x-ui.badge>
                        </summary>

                        <div class="mail-log__govde">
                            <form method="POST" action="{{ route('announcements.recipients.update', $recipient) }}"
                                  data-confirm="{{ $recipient->email }} kaydı güncellenecek."
                                  data-confirm-title="Alıcıyı güncelle"
                                  data-confirm-verify="{{ $recipient->email }}">
                                @csrf
                                @method('PUT')
                                <x-form.version :record="$recipient" />

                                <div class="form-grid">
                                    <x-form.field name="label" label="Ad" :id="'ad-'.$recipient->uuid">
                                        <x-form.input name="label" :id="'ad-'.$recipient->uuid" :value="$recipient->label" required />
                                    </x-form.field>

                                    <x-form.field name="email" label="E-posta" :id="'mail-'.$recipient->uuid">
                                        <x-form.input name="email" :id="'mail-'.$recipient->uuid" type="email"
                                                      :value="$recipient->email" mono required />
                                    </x-form.field>

                                    <x-form.field name="locale" label="Dil" :id="'dil-'.$recipient->uuid"
                                                  hint="Metin bu dilde üretilir.">
                                        <x-form.select name="locale" :id="'dil-'.$recipient->uuid"
                                                       :options="$localeOptions" :selected="$recipient->locale->value" />
                                    </x-form.field>

                                    <x-form.field name="is_active" label="Durum" :id="'durum-'.$recipient->uuid">
                                        <label class="checkbox">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" name="is_active" id="durum-{{ $recipient->uuid }}"
                                                   value="1" @checked($recipient->is_active)>
                                            <span>Anons listesinde görünsün</span>
                                        </label>
                                    </x-form.field>
                                </div>

                                <div class="form-actions">
                                    @can('update', $recipient)
                                        <button type="submit" class="btn btn--primary btn--sm">Kaydet</button>
                                    @endcan
                                </div>
                            </form>

                            @can('delete', $recipient)
                                <x-ui.delete-form :action="route('announcements.recipients.destroy', $recipient)"
                                                  confirm="{{ $recipient->email }} alıcı listesinden silinecek."
                                                  title="Alıcıyı sil"
                                                  verify="{{ $recipient->email }}" />
                            @endcan
                        </div>
                    </details>
                @empty
                    <x-ui.empty title="Alıcı yok"
                                text="Anons talebinin gideceği adresleri ekleyin." />
                @endforelse
            </x-ui.panel>
        </div>
    </div>

    @can('create', App\Models\AnnouncementRecipient::class)
        <x-ui.modal id="alici-ekle" title="Yeni alıcı">
            <form method="POST" action="{{ route('announcements.recipients.store') }}"
                  data-confirm="Yeni alıcı eklenecek."
                  data-confirm-title="Alıcı ekle">
                @csrf

                <div class="modal__body stack">
                    <x-form.field name="label" label="Ad">
                        <x-form.input name="label" placeholder="NOC" required />
                    </x-form.field>

                    <x-form.field name="email" label="E-posta">
                        <x-form.input name="email" type="email" placeholder="noc@ornek.test" mono required />
                    </x-form.field>

                    <x-form.field name="locale" label="Dil" hint="Metin bu dilde üretilir.">
                        <x-form.select name="locale" :options="$localeOptions" />
                    </x-form.field>
                </div>

                <x-ui.modal-actions submit="Ekle" />
            </form>
        </x-ui.modal>
    @endcan
</x-layout.app>
