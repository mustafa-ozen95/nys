<x-layout.app title="Config yükle">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Config yükle"
                            subtitle="Elle yapıştırılan metni ya da şablon çıktısını cihaza uygular. Geri alınabilir commit kullanılır: onay verilemezse cihaz kendini eski hâline döndürür." />

            <x-ui.flash />

            @if (session('preview'))
                <x-ui.panel title="Önizleme"
                            subtitle="Cihaza yüklendi ama COMMIT EDİLMEDİ; aday yapılandırma atıldı.">
                    <dl class="facts">
                        <div class="facts__row">
                            <dt class="facts__key">Uygulama biçimi</dt>
                            <dd class="facts__value">{{ session('preview')['mode'] }}</dd>
                        </div>
                    </dl>

                    <pre class="config-preview">{{ session('preview')['diff'] ?: 'Fark yok: cihaz zaten istenen durumda.' }}</pre>

                    @foreach (session('preview')['warnings'] as $uyari)
                        <div class="alert alert--warning">{{ $uyari }}</div>
                    @endforeach
                </x-ui.panel>
            @endif

            <form method="POST" action="{{ route('provisioning.store') }}"
                  data-config-form
                  data-confirm="Cihazın yapılandırması değiştirilecek. Geri alınabilir commit kullanılır."
                  data-confirm-title="Config yükle"
                  data-confirm-verify="YUKLE">
                @csrf

                <x-ui.panel title="Hedef" subtitle="Envanterdeki bir cihaz ya da yalnızca erişim bilgisine sahip olduğumuz bir cihaz.">
                    <div class="form-grid">
                        <x-form.field name="target" label="Hedef türü" id="target-type">
                            <x-form.select name="target" id="target-type" :selected="old('target', 'switch')"
                                           :options="$canUseExternal
                                               ? ['switch' => 'Envanterdeki cihaz', 'external' => 'Dış cihaz']
                                               : ['switch' => 'Envanterdeki cihaz']" />
                        </x-form.field>

                        <x-form.field name="switch" label="Cihaz" id="target-switch" data-target="switch"
                                      hint="Yalnızca config yükleme yetkiniz olan cihazlar listelenir.">
                            <x-form.select name="switch" id="target-switch" :options="$switches" :selected="old('switch')" />
                        </x-form.field>

                        @if ($canUseExternal)
                            <x-form.field name="host" label="Dış cihaz adresi" data-target="external" id="target-ip"
                                          hint="Envanterde olmayan cihaz için. Yalnızca yönetici kullanabilir.">
                                <x-form.input name="host" id="target-ip" :value="old('host')" mono placeholder="10.9.9.1" />
                            </x-form.field>

                            <x-form.field name="port" label="NETCONF portu" data-target="external" id="target-port">
                                <x-form.input name="port" id="target-port" type="number"
                                              :value="old('port', config('netconf.port'))" mono />
                            </x-form.field>

                            <x-form.field name="username" label="Kullanıcı" data-target="external" id="target-user">
                                <x-form.input name="username" id="target-user" :value="old('username')" mono />
                            </x-form.field>

                            <x-form.field name="password" label="Parola" data-target="external" id="target-password"
                                          hint="Hiçbir yere yazılmaz; yalnızca bu istek boyunca kullanılır.">
                                <x-form.input name="password" id="target-password" type="password"
                                              autocomplete="new-password" mono />
                            </x-form.field>

                            <x-form.field name="save_to_inventory" label="Envantere al" data-target="external" id="target-save">
                                <label class="checkbox">
                                    <input type="hidden" name="save_to_inventory" value="0">
                                    <input type="checkbox" name="save_to_inventory" id="target-save" value="1"
                                           @checked(old('save_to_inventory'))>
                                    <span>Bu cihazı envantere ekle ve kimliğini şifreli sakla</span>
                                </label>
                            </x-form.field>
                        @endif
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Kaynak">
                    <div class="form-grid">
                        <x-form.field name="source" label="Config nereden gelsin" id="source">
                            <x-form.select name="source" id="source" :selected="old('source', 'manual')"
                                           :options="['manual' => 'Elle yapıştır', 'template' => 'Şablondan üret']" />
                        </x-form.field>

                        {{--
                            Seçenek metinleri kısadır: dar ekranda uzun etiket
                            kutuya sığmayıp kırpılır. Ne anlama geldikleri
                            ipuçta yazar.
                        --}}
                        <x-form.field name="mode" label="Uygulama biçimi" id="format"
                                      hint="Ekle: mevcut yapılandırma korunur. Tamamen değiştir: cihazdaki yapılandırma silinir. Set satırları yapıştırılırsa bu seçim yok sayılır.">
                            <x-form.select name="mode" id="format" :selected="old('mode', 'merge')"
                                           :options="[
                                               'merge' => 'Ekle / güncelle',
                                               'override' => 'TAMAMEN DEĞİŞTİR',
                                           ]" />
                        </x-form.field>

                        <x-form.field name="body" label="Config metni" id="body" class="field--wide" data-source="manual"
                                      hint="Terminale yapıştırdığınızın aynısı: set satırları ya da hiyerarşik metin.">
                            <x-form.textarea name="body" id="body" rows="12" class="input--mono" />
                        </x-form.field>
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Şablon girdileri" data-source="template"
                            subtitle="Yalnızca “Şablondan üret” seçildiğinde kullanılır. Yönetim arayüzünün adı cihazdan okunur; modele göre me0 ya da vme olur.">
                    <div class="form-grid">
                        <x-form.field name="hostname" label="Cihaz adı" id="s-name">
                            <x-form.input name="hostname" id="s-name" :value="old('hostname')" mono />
                        </x-form.field>

                        {{--
                            Adresler Adresleme'den seçilir. Elle giriş açıktır:
                            kurulan cihaz henüz envanterde olmayabilir.
                        --}}
                        <x-form.field name="management_address" label="Yönetim adresi" id="s-ip"
                                      hint="Adresleme’de “Yönetim” amaçlı bloklar listelenir.">
                            <x-form.ip-picker name="management_address" id="s-ip" :options="$managementOptions"
                                              manual placeholder="198.18.20.195" />
                        </x-form.field>

                        <x-form.field name="management_prefix" label="Maske uzunluğu" id="s-mask">
                            <x-form.input name="management_prefix" id="s-mask" type="number"
                                          :value="old('management_prefix', 24)" mono />
                        </x-form.field>

                        <x-form.field name="gateway" label="Ağ geçidi" id="s-gw"
                                      hint="Yönetim ağının çıkış adresi.">
                            <x-form.ip-picker name="gateway" id="s-gw" :options="$managementOptions"
                                              manual placeholder="198.18.20.1" />
                        </x-form.field>

                        <x-form.field name="dns" label="DNS sunucuları" id="s-dns" hint="Virgülle ayırın.">
                            <x-form.input name="dns" id="s-dns" :value="old('dns')" mono />
                        </x-form.field>

                        <x-form.field name="ntp" label="NTP sunucuları" id="s-ntp" hint="Virgülle ayırın.">
                            <x-form.input name="ntp" id="s-ntp" :value="old('ntp')" mono />
                        </x-form.field>

                        <x-form.field name="root_password" label="root parolası" id="s-root">
                            <x-form.input name="root_password" id="s-root" type="password" autocomplete="new-password" mono />
                        </x-form.field>

                        <x-form.field name="admin_username" label="Yönetici kullanıcı" id="s-admin">
                            <x-form.input name="admin_username" id="s-admin" :value="old('admin_username')" mono />
                        </x-form.field>

                        <x-form.field name="admin_password" label="Yönetici parolası" id="s-admin-password">
                            <x-form.input name="admin_password" id="s-admin-password" type="password"
                                          autocomplete="new-password" mono />
                        </x-form.field>

                        <x-form.field name="snmp_community" label="SNMP community" id="s-snmp">
                            <x-form.input name="snmp_community" id="s-snmp" :value="old('snmp_community')" mono />
                        </x-form.field>
                    </div>
                </x-ui.panel>

                <div class="form-actions">
                    <button type="submit" name="step" value="preview" class="btn" formnovalidate>Önizle</button>
                    <button type="submit" name="step" value="apply" class="btn btn--primary">Cihaza yaz</button>
                </div>
            </form>

            <x-ui.panel title="Geçmiş" subtitle="Kim, hangi cihaza, ne zaman, hangi biçimde yazdı." flush>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Hedef</th>
                            <th>Biçim</th>
                            <th>Kaynak</th>
                            <th>Kullanıcı</th>
                            <th>Sonuç</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($history as $push)
                            <tr>
                                <td class="mono text-muted" data-label="Zaman"><x-ui.time :at="$push->created_at" format="d.m.Y H:i" /></td>
                                <td class="mono" data-label="Hedef">{{ $push->target_label }}</td>
                                <td data-label="Biçim">{{ $push->mode }}</td>
                                <td data-label="Kaynak">{{ $push->source === 'template' ? 'Şablon' : 'Elle' }}</td>
                                <td data-label="Kullanıcı">{{ $push->user?->name ?? '—' }}</td>
                                <td data-label="Sonuç">
                                    <x-ui.badge :variant="$push->committed ? 'green' : 'gray'">
                                        {{ $push->committed ? 'Yazıldı' : 'Fark yok' }}
                                    </x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-ui.empty title="Henüz config yüklenmedi" /></td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
