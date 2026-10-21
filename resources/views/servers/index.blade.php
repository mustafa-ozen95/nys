<x-layout.app title="Sunucular">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Sunucular</span>
                <span class="column__count">{{ $servers->count() }}</span>

                <x-ui.search name="q" :value="$query"
                            placeholder="Ad veya adres" label="Sunucu filtrele" />

                <span class="spacer"></span>

                {{--
                    BAĞLANTI BİLGİLERİNİN ÇIKTISI.

                    Kapsam ekrandaki süzgeçtir: kullanıcının zaten kurduğu,
                    gözüyle gördüğü küme. Form YERİNDE GÖNDERİLMEZ — yanıt bir
                    dosya ve tarayıcı onu kendi indirmeli.
                --}}
                @can('exportAny', App\Models\Server::class)
                    <form method="POST" action="{{ route('export.connections') }}"
                          data-confirm="Listedeki sunucuların BAĞLANTI BİLGİLERİ (adres, kullanıcı, parola) bir Excel dosyasına yazılıp bilgisayarınıza inecek. Dosya panelin dışına çıkar; oradan sonrası panelin denetiminde değildir."
                          data-confirm-title="Bağlantı bilgilerini dosyaya çıkar">
                        @csrf
                        <input type="hidden" name="kapsam" value="sunucular">
                        <input type="hidden" name="q" value="{{ $query }}">
                        <button type="submit" class="btn btn--sm"
                                @disabled($servers->isEmpty())>Excel çıktısı ({{ $servers->count() }})</button>
                    </form>
                @endcan

                {{--
                    Toplu parola değişimi kendi penceresinde: seçim kutuları
                    liste satırlarına konsaydı satır hem bağlantı hem form
                    olurdu ve kutuya basmak sunucuyu açardı.
                --}}
                @if ($parolaSunuculari->isNotEmpty())
                    <button type="button" class="btn btn--sm"
                            data-modal-open="parola-toplu">Parolaları değiştir…</button>
                @endif

                @can('create', App\Models\Server::class)
                    <button type="button" class="btn btn--primary btn--sm"
                            data-modal-open="sunucu-ekle">Yeni sunucu</button>
                @endcan
            </div>

            <div class="column__body"
                 @if ($suren !== []) data-ilerleme-adres="{{ route('servers.password.progress') }}" @endif>
                <div class="grid-row__head server-row server-row__head">
                    <span class="server-row__ad">Sunucu</span>
                    <span class="server-row__adres">Adres</span>
                    <span class="server-row__os">İşletim sistemi</span>
                    <span class="server-row__erisim">Erişim</span>
                    <span class="server-row__durum">Durum</span>
                </div>

                @forelse ($servers as $server)
                    {{--
                        SÜREN TUR SATIRIN ÜSTÜNDE görünür.

                        Çubuğu çizen betik switch turlarıyla AYNI
                        (dondurme-ilerleme.js) ve uç da aynı biçimi veriyor;
                        ikinci bir çizim kodu, biri değiştiğinde diğerini
                        sessizce eskitirdi.
                    --}}
                    <a href="{{ route('servers.index', ['q' => $query ?: null, 'server' => $server->uuid]) }}"
                       class="grid-row server-row"
                       data-ilerleme-cihaz="{{ $server->uuid }}"
                       @if ($selected?->is($server)) aria-selected="true" @endif>
                        <span class="server-row__ad">
                            <span class="mono">{{ $server->short_name }}</span>
                            @if ($server->long_name)
                                <span class="text-sm text-muted truncate">{{ $server->long_name }}</span>
                            @endif
                        </span>
                        <span class="mono text-muted server-row__adres truncate">{{ $server->ip_address }}</span>
                        <span class="server-row__os">
                            <x-ui.badge :variant="$server->os_type->badge()">{{ $server->os_type->label() }}</x-ui.badge>
                            <span class="text-sm text-muted truncate">{{ $server->os_version }}</span>
                        </span>
                        <span class="mono text-muted server-row__erisim">
                            {{ $server->connection_protocol->label() }}:{{ $server->connection_port }}
                        </span>
                        <span class="server-row__durum">
                            @if (($suren[$server->uuid] ?? null) !== null)
                                <span class="ilerleme">
                                    <span class="ilerleme__metin" data-ilerleme-metin>{{ $suren[$server->uuid]['metin'] }}</span>

                                    <span class="ilerleme__yol"
                                          role="progressbar"
                                          aria-label="{{ $server->short_name }} parola güncelleme ilerlemesi"
                                          aria-valuemin="0"
                                          aria-valuemax="100"
                                          aria-valuenow="{{ $suren[$server->uuid]['yuzde'] }}">
                                        <span class="ilerleme__cubuk" data-ilerleme-cubuk data-baslangic="{{ $suren[$server->uuid]['yuzde'] }}"></span>
                                    </span>
                                </span>
                            @else
                                <x-ui.status-dot :status="$server->status" />
                            @endif
                        </span>
                    </a>
                @empty
                    <x-ui.empty title="Sunucu yok" text="Erişmek istediğiniz sunucuları ekleyin." />
                @endforelse
            </div>
        </div>

        @if ($selected)
            <aside class="detail">
                <div class="detail__head">
                    <div class="detail__title">{{ $selected->short_name }}</div>
                    <p class="detail__subtitle">{{ $selected->long_name ?: '—' }}</p>
                </div>

                <div class="detail__body">
                    <div class="detail__section">
                        <span class="eyebrow">Erişim</span>
                        <x-ui.facts class="detail__block" :rows="[
                            'Adres' => $selected->ip_address,
                            'Protokol' => $selected->connection_protocol->label(),
                            'Port' => $selected->connection_port,
                            'Kullanıcı' => $selected->connection_username,
                            'Parola' => $selected->connection_password ? 'Kayıtlı' : 'Tanımlı değil',
                        ]" />
                    </div>

                    <div class="detail__section">
                        <span class="eyebrow">Sistem</span>
                        <x-ui.facts class="detail__block" :rows="[
                            'İşletim sistemi' => $selected->os_type->label(),
                            'Sürüm' => $selected->os_version,
                            'Konum' => $selected->location,
                        ]" />
                    </div>

                    <div class="detail__section">
                        <span class="eyebrow">Not</span>

                        @if (filled($selected->notes))
                            <p class="server-note">{{ $selected->notes }}</p>
                        @else
                            <p class="detail__block text-muted text-sm">Not girilmemiş.</p>
                        @endif
                    </div>
                </div>

                {{--
                    YARIDA KALMIŞ TUR UYARISI.

                    Kurtarma hesabı makinede duruyorsa tam yetkili ikinci bir
                    hesap açık demektir; bu, temizlenene kadar bir açıktır ve
                    ekranın en görünür yerinde durmalı.
                --}}
                @if ($selected->latestPasswordRotation?->recovery_active)
                    <div class="detail__section">
                        <x-ui.alert variant="danger">
                            Yarıda kalmış bir parola turu var: makinede
                            <span class="mono">{{ $selected->latestPasswordRotation->recovery_username }}</span>
                            adlı tam yetkili bir kurtarma hesabı duruyor.

                            @can('rotate', $selected)
                                <form method="POST"
                                      action="{{ route('servers.password.repair', $selected->latestPasswordRotation) }}"
                                      data-yerinde
                                      data-confirm="Makineye bağlanılıp kurtarma hesabı silinecek."
                                      data-confirm-title="Yarıda kalan turu kapat">
                                    @csrf
                                    <button type="submit" class="btn btn--sm">Kurtarma hesabını temizle</button>
                                </form>
                            @endcan
                        </x-ui.alert>
                    </div>
                @endif

                <div class="detail__foot">
                    @can('connect', $selected)
                        <button type="button" class="btn btn--primary btn--sm"
                                data-uzak-baglan="{{ route('ajax.remote.connect') }}"
                                data-uzak-tur="server"
                                data-uzak-kimlik="{{ $selected->uuid }}">Bağlan</button>
                    @endcan

                    @can('update', $selected)
                        <button type="button" class="btn btn--sm"
                                data-modal-open="sunucu-duzenle">Düzenle</button>
                    @endcan

                    {{--
                        PAROLA DEĞİŞTİRME.

                        Tur KUYRUKTA koşar: makineye birkaç kez bağlanılıyor ve
                        isteği bekletmek tarayıcıyı zaman aşımına düşürürdü.
                        İlerleme aşağıdaki satırdan izlenir.
                    --}}
                    @can('rotate', $selected)
                        <form method="POST" action="{{ route('servers.password', $selected) }}"
                              data-yerinde
                              data-confirm="{{ $selected->short_name }} makinesindeki {{ $selected->connection_username }} hesabına YENİ ve rastgele bir parola verilecek. Eski parola artık çalışmayacak."
                              data-confirm-title="Sunucu parolasını değiştir"
                              data-confirm-variant="danger">
                            @csrf
                            <button type="submit" class="btn btn--sm">Parolayı değiştir</button>
                        </form>
                    @endcan

                    @can('delete', $selected)
                        <x-ui.delete-form :action="route('servers.destroy', $selected)"
                                          confirm="{{ $selected->short_name }} silinecek."
                                          title="Sunucuyu sil"
                                          verify="{{ $selected->short_name }}" />
                    @endcan
                </div>
            </aside>

            @can('update', $selected)
                {{--
                    Düzenleme AYRI BİR SAYFA değil: kullanıcı seçtiği
                    sunucudan ayrılmadan düzenler. Alanlar "yeni sunucu"
                    sayfasıyla PAYLAŞILIR (servers/fields.blade.php); iki
                    kopya olsaydı biri değiştiğinde öteki sessizce ayrışırdı.
                --}}
                <x-ui.modal id="sunucu-duzenle" :title="$selected->short_name.' ayarları'"
                            :open="request()->boolean('duzenle')">
                    <form method="POST" action="{{ route('servers.update', $selected) }}"
                          data-confirm="Sunucu kaydı güncellenecek."
                          data-confirm-title="Sunucuyu kaydet">
                        @csrf
                        @method('PUT')

                        <div class="modal__body stack">
                            @include('servers.fields', ['server' => $selected])
                        </div>

                        <x-ui.modal-actions submit="Değişiklikleri kaydet" />
                    </form>
                </x-ui.modal>
            @endcan
        @endif
    </div>

    @if ($parolaSunuculari->isNotEmpty())
        {{--
            Seçilenlerin parolasını değiştir.

            Listede o an GÖRÜNEN ve parola yetkisi olan sunucular basılır.
            Yetki sunucu tarafında yeniden ve tek tek sorulur, çünkü form
            değeri kullanıcının elindedir ve ekranda çizmemek koruma değildir.

            Turlar SIRAYLA koşar: aynı anda koşan turlar işçilerin tamamını
            meşgul edip hepsi birden zaman aşımına girebilir.
        --}}
        <x-ui.modal id="parola-toplu" title="Seçilenlerin parolasını değiştir">
            <form method="POST" action="{{ route('servers.password.bulk') }}"
                  data-yerinde
                  data-confirm="Seçilen sunuculardaki hesaplara YENİ ve rastgele parolalar verilecek. Eski parolalar artık çalışmayacak."
                  data-confirm-title="Toplu parola değişimi"
                  data-confirm-variant="danger">
                @csrf

                <div class="modal__body stack">
                    <p class="text-sm text-muted">
                        Her sunucuya AYRI ve rastgele bir parola verilir. Turlar sırayla koşar;
                        biri başarısız olsa da diğerleri devam eder. İlerleme listede görünür.
                    </p>

                    <div class="stack" data-secim-grubu>
                        <label class="checkbox">
                            <input type="checkbox" data-tumunu-sec>
                            <span>Tümü</span>
                        </label>

                        @foreach ($parolaSunuculari as $server)
                            <label class="checkbox">
                                <input type="checkbox" name="sunucular[]" value="{{ $server->uuid }}">
                                <span class="mono">{{ $server->short_name }}</span>
                                <span class="text-sm text-muted mono">{{ $server->connection_username.'@'.$server->ip_address }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <x-ui.modal-actions submit="Seçilenlerin parolasını değiştir" />
            </form>
        </x-ui.modal>
    @endif

    @can('create', App\Models\Server::class)
        {{--
            Ekleme de AYRI BİR SAYFA değil. Alanlar düzenleme penceresiyle
            PAYLAŞILIR (servers/fields.blade.php); iki kopya olsaydı biri
            değiştiğinde öteki sessizce ayrışırdı.

            Seçili sunucudan BAĞIMSIZ: liste boşken de açılabilmeli.
        --}}
        <x-ui.modal id="sunucu-ekle" title="Yeni sunucu" :open="request()->boolean('ekle')">
            <form method="POST" action="{{ route('servers.store') }}"
                  data-confirm="Sunucu kaydı eklenecek."
                  data-confirm-title="Sunucuyu kaydet">
                @csrf

                <div class="modal__body stack">
                    @include('servers.fields', ['server' => null])
                </div>

                <x-ui.modal-actions submit="Sunucuyu ekle" />
            </form>
        </x-ui.modal>
    @endcan
</x-layout.app>
