<x-layout.app :title="$switch->hostname.' · Prefix listeler'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Prefix listeler</span>
                <span class="column__count">{{ $lists->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchPrefixLists', $switch)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="list-add">Liste ekle</button>
                @endcan
            </div>

            <div class="column__body column__body--padded">
                <div class="stack stack--loose">
                    @forelse ($lists as $list)
                        <x-ui.panel :title="$list->name" :subtitle="$list->entries->count().' prefix'">
                            <x-slot:actions>
                                @can('updateSwitchPrefixLists', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="ayar-{{ $list->uuid }}">Dinamik doldurma</button>
                                @endcan

                                @can('createSwitchPrefixLists', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="prefix-{{ $list->uuid }}">Prefix ekle</button>
                                @endcan

                                @can('deleteSwitchPrefixLists', $switch)
                                    <x-ui.delete-form :action="route('switches.prefix-lists.destroy', [$switch, $list])"
                                                      confirm="{{ $list->name }} listesi ve içindeki prefixler silinecek."
                                                      title="Listeyi sil"
                                                      verify="{{ $list->name }}" />
                                @endcan
                            </x-slot:actions>

                            {{--
                                Cihazda dinamik doldurulan liste (apply-path).
                                Girdileri yapılandırmada YAZILI DEĞİLDİR, o
                                yüzden panel onları tek tek gösteremez; ama
                                ifadenin kendisi buradan yönetilir. Bunu
                                söylemezsek liste "boş" görünür ve aşağıdaki
                                uyarı olmayan bir tehlikeyi haber verir.
                            --}}
                            @if ($list->is_dynamic)
                                <x-ui.alert variant="info">
                                    Bu liste cihazda DİNAMİK doldurulmuş:
                                    <span class="mono">{{ implode(' · ', $list->apply_paths) }}</span>.
                                    Böyle üretilen girdiler yapılandırmada tek tek yazılı olmadığı için aşağıdaki
                                    listede görünmez. İfadeyi “Dinamik doldurma” ile düzenleyebilirsiniz;
                                    buradan eklenen prefixler ayrıca yazılır ve dinamik girdileri etkilemez.
                                </x-ui.alert>
                            @endif

                            {{--
                                Panelin göstermediği ama KORUNAN satırlar.
                                Kullanıcı neyin taşındığını bilmeli: liste
                                silinip yeniden yazılmadığı için bu satırlar
                                cihazda olduğu gibi kalır.
                            --}}
                            @if ($list->preserved_lines)
                                <x-ui.alert variant="info">
                                    Bu listede panelin göstermediği {{ count($list->preserved_lines) }} satır var
                                    ({{ implode(', ', $list->preserved_lines) }}). Panel listeyi silip yeniden
                                    yazmadığı için cihazda olduğu gibi korunurlar.
                                </x-ui.alert>
                            @endif

                            {{--
                                Çıkış bekçisi: uplink çıkışındaki filtre bu
                                listeye dayanıyor. Listede olmayan blok anons
                                edilse bile DIŞARI ÇIKAMAZ.
                            --}}
                            @if ($list->is_egress_guard)
                                <div class="row row--between prefix-entry">
                                    <x-ui.badge :variant="$list->egress_badge">Çıkış bekçisi</x-ui.badge>
                                    <span class="text-sm text-muted">
                                        {{ implode(', ', $list->egress_interfaces) }} çıkışını korur
                                    </span>
                                </div>

                                {{-- Dinamik listede "boş" iddiası yanlıştır. --}}
                                @if ($list->entries->isEmpty() && ! $list->is_dynamic)
                                    <x-ui.alert variant="warning">
                                        Bu liste BOŞ. Uplink çıkışındaki filtre buna dayandığı için
                                        üzerinden geçen hiçbir trafik dışarı çıkamaz. Cihazın kendi
                                        ürettiği trafik (BGP, ping) etkilenmez.
                                    </x-ui.alert>
                                @endif
                            @endif

                            @forelse ($list->entries->sortBy('address') as $entry)
                                <div class="row row--between prefix-entry">
                                    <span class="mono">{{ $entry->cidr }}</span>

                                    @can('createSwitchPrefixLists', $switch)
                                        {{--
                                            Yıkıcı eylem kırmızıdır; "ghost"
                                            bir silme düğmesi diğer "Çıkar"
                                            düğmelerinden ayrışırdı.
                                        --}}
                                        <x-ui.delete-form :action="route('switches.prefix-lists.entries.destroy', [$switch, $list, $entry])"
                                                          confirm="{{ $entry->cidr }} listeden çıkarılacak."
                                                          title="Prefixi çıkar"
                                                          verify="{{ $entry->cidr }}"
                                                          label="Çıkar" />
                                    @endcan
                                </div>
                            @empty
                                <p class="text-sm text-muted">Bu listede statik prefix yok.</p>
                            @endforelse
                        </x-ui.panel>
                    @empty
                        <x-ui.empty title="Prefix liste yok"
                                    text="Listeler cihazdan eşitlenir. Firewall filtreleri ve BGP politikaları bunlara dayanır." />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @can('createSwitchPrefixLists', $switch)
        <x-ui.modal id="list-add" title="Yeni prefix liste">
            <form method="POST" action="{{ route('switches.prefix-lists.store', $switch) }}"
                  data-confirm="Yeni prefix listesi cihaza yazılacak."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-form.field name="name" label="Liste adı" hint="Örnek: MUSTERI-ANONS">
                        <x-form.input name="name" mono required />
                    </x-form.field>
                </div>
                <x-ui.modal-actions submit="Ekle" />
            </form>
        </x-ui.modal>

        @foreach ($lists as $list)
            <x-ui.modal :id="'prefix-'.$list->uuid" :title="$list->name.' · prefix ekle'">
                <form method="POST" action="{{ route('switches.prefix-lists.entries.store', [$switch, $list]) }}"
                      data-confirm="{{ $list->name }} listesine prefix eklenecek ve cihaza yazılacak."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    <div class="modal__body stack">
                        {{--
                            Ağ Adresleme'den seçilir. Elle giriş açıktır: prefix
                            list bizim envanterimizde olmayan bir ağı da taşıyabilir
                            (sağlayıcının özet prefixi, komşunun bloğu). IPv6 de
                            yazılabilir; cihaz kabul ediyor.
                        --}}
                        <x-form.field rule="switch_prefix_lists.prefix-liste:cidr" name="cidr" label="Ağ" :id="'pa-'.$list->uuid"
                                      hint="Adresleme’de tanımlı bloklar listelenir. Elle IPv4 ve IPv6 yazılabilir: 192.0.2.0/24, 2001:db8::/32.">
                            <x-form.ip-picker name="cidr" :id="'pa-'.$list->uuid"
                                              :options="$networkOptions" manual
                                              placeholder="192.0.2.0/24"
                                              empty-text="Adresleme’de blok yok; ağı elle yazın." />
                        </x-form.field>
                    </div>
                    <x-ui.modal-actions submit="Ekle" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan

    {{--
        Dinamik doldurma (apply-path). Cihaz listeyi kendi yapılandırmasından
        türetir; ifade panelden yönetilir. Böyle bir liste salt okunur
        sayılmaz: sayılsaydı kullanıcı hiçbir şey yapamazdı.
    --}}
    @can('updateSwitchPrefixLists', $switch)
        @foreach ($lists as $list)
            <x-ui.modal :id="'ayar-'.$list->uuid" :title="$list->name.' · dinamik doldurma'">
                <form method="POST" action="{{ route('switches.prefix-lists.update', [$switch, $list]) }}"
                      data-confirm="{{ $list->name }} listesinin apply-path ifadeleri cihaza yazılacak."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    @method('PUT')
                    <div class="modal__body stack">
                        <x-ui.alert variant="info">
                            apply-path, listeyi cihazın kendi yapılandırmasından doldurur: ifadeye uyan her
                            adres listeye kendiliğinden girer ve yapılandırmada tek tek yazılmaz. Statik
                            prefixlerle birlikte çalışır, onların yerini almaz.
                        </x-ui.alert>

                        <x-form.field rule="switch_prefix_lists.prefix-liste:apply_paths" name="apply_paths" label="apply-path ifadeleri"
                                      :id="'ap-'.$list->uuid.'-0'"
                                      hint="Örnek: system radius-server <*> — joker <*> ile bir hiyerarşinin tüm adresleri alınır. Hepsini boşaltırsanız liste yalnızca statik prefixlerden oluşur.">
                            <div class="stack stack--tight" data-repeat="apply_paths">
                                @foreach ($list->apply_paths ?: [null] as $i => $path)
                                    <div class="row row--tight" data-repeat-row>
                                        <x-form.input name="apply_paths[]" :id="'ap-'.$list->uuid.'-'.$i"
                                                      :value="$path" mono
                                                      placeholder="system radius-server <*>" />
                                        <button type="button" class="btn btn--ghost btn--sm" data-repeat-remove>Çıkar</button>
                                    </div>
                                @endforeach

                                <button type="button" class="btn btn--sm" data-repeat-add>İfade ekle</button>
                            </div>
                        </x-form.field>

                        {{--
                            Bekçi listesini boşaltmak o çıkıştan geçen her şeyi
                            düşürür. Yasaklamıyoruz — cihaz kabul ediyor ve panelin
                            işi yönetmeyi mümkün kılmak — ama sonucu görülmeden
                            yapılmasın diye açık onay isteniyor.
                        --}}
                        @if ($list->is_egress_guard && $list->entries->isEmpty())
                            <x-ui.alert variant="warning">
                                Bu liste {{ implode(', ', $list->egress_interfaces) }} çıkışını koruyor ve içinde
                                statik prefix yok. Bütün ifadeleri kaldırırsanız liste boşalır ve o çıkıştan
                                hiçbir trafik geçemez.
                            </x-ui.alert>

                            <label class="checkbox">
                                <input type="checkbox" name="acknowledge_empty" value="1">
                                <span>Listeyi boşaltmayı ve bu çıkışın kapanmasını kabul ediyorum.</span>
                            </label>
                        @endif
                    </div>
                    <x-ui.modal-actions submit="Kaydet" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
