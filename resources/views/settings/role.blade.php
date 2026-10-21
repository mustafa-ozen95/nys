<x-layout.app :title="$role->label.' · Roller'">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head :title="$role->label" :subtitle="$role->description">
                <x-slot:actions>
                    <x-ui.badge :variant="$role->badge">{{ $role->slug }}</x-ui.badge>
                    <span class="text-sm text-muted">{{ $userCount }} kullanıcı</span>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            <form method="POST" action="{{ route('settings.roles.update', $role) }}"
                  data-confirm="{{ $role->label }} rolü kaydedilecek. Mevcut kullanıcıların yetkisi kendiliğinden değişmez; şablon yetki ekranından uygulanır."
                  data-confirm-title="Rolü kaydet"
                  data-confirm-verify="{{ $role->label }}">
                @csrf
                @method('PUT')

                <x-ui.panel title="Rol">
                    <div class="form-grid">
                        <x-form.field name="label" label="Rol adı" id="rol-adi">
                            <x-form.input name="label" id="rol-adi" :value="old('label', $role->label)" required />
                        </x-form.field>

                        @if ($role->is_system)
                            {{-- Yerleşik rolün kod adı değişmez: kod ve seeder ona adıyla atıfta bulunuyor. --}}
                            <x-form.field name="slug_readonly" label="Kod adı" id="rol-kod"
                                          hint="Yerleşik rol; kod adı değiştirilemez.">
                                <x-form.input name="slug_readonly" id="rol-kod" :value="$role->slug" mono disabled />
                            </x-form.field>
                        @else
                            <x-form.field name="slug" label="Kod adı" id="rol-kod">
                                <x-form.input name="slug" id="rol-kod" :value="old('slug', $role->slug)" mono required />
                            </x-form.field>
                        @endif

                        <x-form.field name="description" label="Açıklama" id="rol-aciklama" class="field--wide">
                            <x-form.input name="description" id="rol-aciklama" :value="old('description', $role->description)" />
                        </x-form.field>

                        <x-form.field name="badge" label="Renk" id="rol-renk">
                            <x-form.select name="badge" id="rol-renk" :options="$badgeOptions"
                                           :selected="old('badge', $role->badge->value)" />
                        </x-form.field>

                        <x-form.field name="position" label="Sıra" id="rol-sira"
                                      hint="Listede küçükten büyüğe.">
                            <x-form.input type="number" name="position" id="rol-sira" min="0" max="999"
                                          :value="old('position', $role->position)" />
                        </x-form.field>

                        <x-form.field name="is_admin" label="Yönetici" id="rol-yonetici"
                                      hint="Bütün yetki kontrollerini geçer. En az bir yönetici rolü kalmak zorunda.">
                            <label class="checkbox">
                                <input type="checkbox" name="is_admin" value="1" id="rol-yonetici"
                                       @checked(old('is_admin', $role->is_admin))>
                                <span>Bütün kontrolleri geçsin</span>
                            </label>
                        </x-form.field>

                        <x-form.field name="is_default" label="Varsayılan rol" id="rol-varsayilan"
                                      hint="Yönetici olmayan biri kullanıcı açtığında bu rol verilir. Tek bir rol varsayılan olabilir.">
                            <label class="checkbox">
                                <input type="checkbox" name="is_default" value="1" id="rol-varsayilan"
                                       @checked(old('is_default', $role->is_default))>
                                <span>Yeni kullanıcıların varsayılanı</span>
                            </label>
                        </x-form.field>
                    </div>
                </x-ui.panel>

                {{--
                    Hazır yetki şablonu. Yönetici rolünde anlamsızdır: o rol
                    zaten bütün kontrolleri geçer.
                --}}
                @unless ($role->is_admin)
                    <x-ui.panel title="Hazır yetkiler"
                                subtitle="Şablon kullanıcıya yetki ekranından uygulanır; buradaki değişiklik mevcut kullanıcılara kendiliğinden yansımaz.">
                        @foreach ($resources as $resource)
                            <div class="grid-row grid-row--static rol-kaynak">
                                <span>{{ $resource['label'] }}</span>
                                <span class="row row--wrap">
                                    @foreach ($resource['actions'] as $action)
                                        <label class="checkbox">
                                            <input type="checkbox"
                                                   name="template[{{ $resource['key'] }}][]"
                                                   value="{{ $action }}"
                                                   @checked(in_array($action, $template[$resource['key']] ?? [], true))>
                                            <span>{{ $actionLabels[$action] ?? $action }}</span>
                                        </label>
                                    @endforeach
                                </span>
                            </div>
                        @endforeach
                    </x-ui.panel>
                @endunless

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Kaydet</button>

                    @if ($role->isDeletable())
                        <button type="submit" form="rol-sil" class="btn btn--danger">Sil</button>
                    @endif
                </div>
            </form>

            @if ($role->isDeletable())
                <form method="POST" id="rol-sil" hidden action="{{ route('settings.roles.destroy', $role) }}"
                      data-confirm="{{ $role->label }} rolü silinecek."
                      data-confirm-title="Rolü sil" data-confirm-variant="danger"
                      data-confirm-verify="{{ $role->label }}">
                    @csrf
                    @method('DELETE')
                </form>
            @endif
        </div>
    </div>
</x-layout.app>
