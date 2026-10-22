<x-layout.app title="Cihaz parolaları · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Panelin cihazlara bağlandığı hesabın parolası. Her switche ayrı ve rastgele bir parola verilir." />

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            @if ($active !== [])
                {{--
                    SÜREN TURLAR EN ÜSTTE.

                    Satır içindeki çubuklar ayrıntıyı veriyor ama cihaz listesi
                    sayfanın altında: dar bir telefon ekranında biçim paneli
                    ekranı doldurur ve çubukların hiçbiri görüntü alanına
                    girmez. Görünmeyen bir ilerleme göstergesinin varlık
                    sebebi yok.

                    Toplam doluluk ADIM SAYISINDAN hesaplanır (biten adımlar /
                    toplam adımlar), yüzdelerin ortalamasından değil: iki
                    cihazdan biri bitmiş diğeri başlamışken "ortalama" ikisinin
                    de yarısında olduğunu söylerdi.
                --}}
                <div class="progress progress--strip"
                     data-progress-summary
                     data-progress-address="{{ route('settings.passwords.progress') }}">
                    <div class="progress__top">
                        <span class="progress__text" data-progress-summary-text>{{ count($active) }} cihazda parola turu sürüyor</span>
                        <span class="progress__step" data-progress-summary-step>{{ $ozet['biten'] }}/{{ $ozet['toplam'] }} adım</span>
                    </div>

                    <div class="progress__path"
                         role="progressbar"
                         aria-label="Parola turlarının toplam ilerlemesi"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-valuenow="{{ $ozet['yuzde'] }}">
                        <div class="progress__bar" data-progress-summary-bar data-start="{{ $ozet['yuzde'] }}"></div>
                    </div>
                </div>
            @endif

            {{--
                Cihazda kalmış kurtarma hesapları. Tam yetkili açık bir hesap
                demektir; kapatılana kadar en üstte durur.
            --}}
            @if ($stranded->isNotEmpty())
                <x-ui.panel title="Kapatılmamış kurtarma hesapları"
                            subtitle="Bu cihazlarda tam yetkili geçici bir hesap duruyor. Parola turu yarıda kaldı.">
                    @foreach ($stranded as $rotation)
                        <div class="grid-row grid-row--static setting-row">
                            <span class="mono">{{ $rotation->switch?->hostname ?? '—' }}</span>
                            <span>
                                <x-ui.badge :variant="$rotation->stage?->badge() ?? 'red'">{{ $rotation->stage?->label() ?? 'Bilinmiyor' }}</x-ui.badge>
                            </span>
                            <span class="text-muted truncate">{{ $rotation->stage?->hint() }}</span>
                            <span class="text-right">
                                <button type="submit" form="recovery-{{ $rotation->uuid }}"
                                        class="btn btn--primary btn--sm">Kapat</button>
                            </span>
                        </div>

                        <form method="POST" id="recovery-{{ $rotation->uuid }}" hidden
                              action="{{ route('settings.passwords.repair', $rotation) }}"
                              data-in-place
                              data-confirm="Kurtarma hesabı cihazdan silinecek. Panel hesabına girilemiyorsa önce parolası yeniden kurulur."
                              data-confirm-title="Kurtarma hesabını kapat"
                              data-confirm-verify="{{ $rotation->switch?->hostname }}">
                            @csrf
                            @method('PUT')
                        </form>
                    @endforeach
                </x-ui.panel>
            @endif

            {{--
                Üretilecek parolanın biçimi. Ayrı bir ayar sekmesi DEĞİL:
                biçimi seçtiğin yer ile sonucunu gördüğün yer aynı olmalı.
                Kaydetme yine ayar ucundan geçer; yetkisi olmayana form hiç
                gösterilmez.
            --}}
            @if ($canEditFormat && $formatGroup !== null)
                <x-ui.panel title="Üretilecek parolanın biçimi"
                            :subtitle="$formatGroup['description']">
                    <form method="POST" action="{{ route('settings.update', $formatGroup['slug']) }}"
                          data-confirm="Üretilecek parolanın biçimi değişecek. Bundan sonraki her tur bu ayarla üretir; mevcut parolalar etkilenmez."
                          data-confirm-title="Parola biçimi"
                          data-confirm-verify="{{ $formatGroup['label'] }}">
                        @csrf
                        @method('PUT')

                        <div class="form-grid">
                            @foreach ($formatGroup['items'] as $item)
                                <x-settings.field :item="$item" :value="$formatValues[$item->key]" />
                            @endforeach
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary btn--sm">Kaydet</button>
                        </div>
                    </form>
                </x-ui.panel>
            @endif

            <x-ui.panel title="Toplu parola değişimi"
                        subtitle="Her cihaza {{ $length }} karakterlik, {{ $format }} içeren AYRI bir parola yazılır.">
                @if ($canRotateAny)
                    <x-slot:actions>
                        <button type="submit" form="bulk-password" class="btn btn--danger btn--sm">
                            Hepsini değiştir
                        </button>
                    </x-slot:actions>
                @endif

                {{--
                    Sıra güvenliğin kendisidir; kullanıcı ne olacağını
                    düğmeye basmadan önce görmeli.
                --}}
                <ol class="text-sm stack">
                    <li>Cihazda <span class="mono">{{ $recoveryUsername }}</span> adında tam yetkili bir kurtarma hesabı açılır.</li>
                    <li>O hesapla gerçekten bağlanılabildiği denenir; denenemezse hesap silinir ve tur durur.</li>
                    <li>Panel hesabının parolası kurtarma hesabıyla değiştirilir.</li>
                    <li>Yeni parola yeni bir oturumla denenir.</li>
                    <li>Panelin kimlik kaydı ancak bundan sonra güncellenir.</li>
                    <li>Kurtarma hesabı yeni parolayla silinir.</li>
                </ol>

                <p class="text-sm text-muted">
                    Bir adımda takılırsa kurtarma hesabı cihazda bırakılır ve bu ekranda uyarı olarak görünür;
                    panelin cihaza dönebileceği kapı hep açık kalır.
                </p>

                {{--
                    Parola yetkili kişiye gösterilir; "parolalar hiçbir
                    ekranda gösterilmez" gibi bir cümle burada yanlış olur ve
                    kullanıcıya parolanın kasten gizlendiğini düşündürür.
                --}}
                <p class="text-sm text-muted">
                    Üretilen parola <strong>düz metindir</strong>, özet değil: aşağıdaki listede göz simgesine
                    basınca olduğu gibi görünür ve cihaza konsoldan da o hâliyle girilir. Rastgele harf ve
                    rakamdan oluştuğu için özet gibi görünebilir. Parola <strong>kayıtlara</strong> hiçbir
                    zaman yazılmaz; her gösterim ise kayda geçer.
                </p>

                <form method="POST" id="bulk-password" hidden action="{{ route('settings.passwords.store') }}"
                      data-in-place
                      data-confirm="Bütün switchlerin panel parolası değiştirilecek. Cihaza SSH ile bu hesapla bağlanan başka sistemler varsa erişimlerini kaybeder."
                      data-confirm-title="Bütün cihazların parolasını değiştir"
                      data-confirm-variant="danger"
                      data-confirm-verify="TÜM CİHAZLAR">
                    @csrf
                </form>
            </x-ui.panel>

            {{--
                Süren turlar varsa adres verilir ve yoklama başlar. Yoksa
                öznitelik hiç basılmaz: boş bir sayfanın sunucuya soru
                sormasının anlamı yok.
            --}}
            <x-ui.panel title="Cihazlar" subtitle="Son turun sonucu. Tek bir cihazı yeniden denemek için satırdaki düğme."
                        >
                @forelse ($switches as $switch)
                    <div class="grid-row grid-row--static setting-row" data-progress-device="{{ $switch->uuid }}">
                        <span class="mono">{{ $switch->hostname }}</span>
                        <span>
                            @if (isset($active[$switch->uuid]))
                                <x-ui.badge variant="cyan">Sürüyor</x-ui.badge>
                            @elseif ($switch->latestPasswordRotation === null)
                                <x-ui.badge variant="gray">Hiç değişmedi</x-ui.badge>
                            @else
                                <x-ui.badge :variant="$switch->latestPasswordRotation->status->badge()">
                                    {{ $switch->latestPasswordRotation->status->label() }}
                                </x-ui.badge>
                            @endif
                        </span>
                        <span class="truncate">
                            {{-- Parola ekranda basılmaz; istek üzerine gelir. --}}
                            @can('secret', $switch)
                                <x-ui.secret :source="route('switches.password', $switch)"
                                             :username="$switch->netconfCredential?->username ?? $switch->sshCredential?->username" />
                            @else
                                <span class="text-muted">
                                    @if ($switch->credentials->isEmpty())
                                        Cihaz kimliği tanımlı değil.
                                    @else
                                        {{ $switch->latestPasswordRotation?->finished_at?->diffForHumans() ?? $switch->management_ip }}
                                    @endif
                                </span>
                            @endcan
                        </span>
                        <span @class(['text-right', 'setting-row__progress' => isset($active[$switch->uuid])])>
                            @if (isset($active[$switch->uuid]))
                                {{--
                                    Tur SÜRÜYOR. Düğmeler gösterilmez: ikinci
                                    iş, işin tekilliğine takılıp sessizce
                                    düşerdi ve kullanıcı bunu hata sanardı.
                                --}}
                                <span class="progress">
                                    <span class="progress__text" data-progress-text>{{ $active[$switch->uuid]['adim'] > 0 ? $active[$switch->uuid]['adim'].'/'.$active[$switch->uuid]['toplam'].' · '.$active[$switch->uuid]['metin'] : $active[$switch->uuid]['metin'] }}</span>

                                    <span class="progress__path"
                                          role="progressbar"
                                          aria-label="{{ $switch->hostname }} parola güncelleme ilerlemesi"
                                          aria-valuemin="0"
                                          aria-valuemax="100"
                                          aria-valuenow="{{ $active[$switch->uuid]['yuzde'] }}">
                                        <span class="progress__bar" data-progress-bar data-start="{{ $active[$switch->uuid]['yuzde'] }}"></span>
                                    </span>
                                </span>
                            @else
                                @can('rotate', $switch)
                                    @unless ($switch->credentials->isEmpty())
                                        <button type="button" class="btn btn--sm"
                                                data-modal-open="elle-{{ $switch->uuid }}">Elle</button>
                                        <button type="submit" form="password-{{ $switch->uuid }}"
                                                class="btn btn--sm">Değiştir</button>
                                    @endunless
                                @endcan
                            @endif
                        </span>
                    </div>

                    @if (! $switch->credentials->isEmpty() && auth()->user()->can('rotate', $switch))
                        <form method="POST" id="password-{{ $switch->uuid }}" hidden
                              action="{{ route('settings.passwords.update', $switch) }}"
                              data-in-place
                              data-confirm="{{ $switch->hostname }} cihazının panel parolası değiştirilecek."
                              data-confirm-title="Parolayı değiştir"
                              data-confirm-verify="{{ $switch->hostname }}">
                            @csrf
                            @method('PUT')
                        </form>
                    @endif
                @empty
                    <p class="text-sm text-muted">Parola yetkiniz olan switch yok.</p>
                @endforelse
            </x-ui.panel>

            {{--
                Elle parola. İki iş bilinçli olarak AYRI seçenektir:
                "cihaza yaz" sahadaki cihazı değiştirir, "yalnızca kaydet"
                panelin bildiğini düzeltir. İkisini tek düğmede toplamak,
                hangisinin olduğunu belirsiz bırakırdı.
            --}}
            @foreach ($switches as $switch)
                @can('rotate', $switch)
                    @unless ($switch->credentials->isEmpty())
                        <x-ui.modal :id="'elle-'.$switch->uuid" :title="$switch->hostname.' · parolayı elle ver'">
                            <form method="POST" action="{{ route('settings.passwords.manual', $switch) }}"
                            data-in-place
                                  data-confirm="{{ $switch->hostname }} cihazına verdiğiniz parola yazılacak. Geri alınabilir commit kullanılır; kurtarma hesabı açılır ve tur sonunda silinir."
                                  data-confirm-title="Elle parola"
                                  data-confirm-verify="{{ $switch->hostname }}">
                                @csrf
                                @method('PUT')
                                <div class="modal__body stack">
                                    <x-form.field name="password" label="Parola" :id="'elle-parola-'.$switch->uuid"
                                                  hint="En az 8 karakter; rakam ya da noktalama içermeli. Kaydedilen değer ekranda düz metin olarak okunabilir.">
                                        <x-form.input type="password" name="password" :id="'elle-parola-'.$switch->uuid"
                                                      autocomplete="new-password" required />
                                    </x-form.field>

                                    <x-form.field name="password_confirmation" label="Parola (tekrar)"
                                                  :id="'elle-parola-tekrar-'.$switch->uuid">
                                        <x-form.input type="password" name="password_confirmation"
                                                      :id="'elle-parola-tekrar-'.$switch->uuid"
                                                      autocomplete="new-password" required />
                                    </x-form.field>

                                    {{--
                                        "Yalnızca panele kaydet" diye bir seçenek YOK: panelin bildiği
                                        ile cihazdaki ayrı ayrı düzenlenebilseydi iki doğruluk kaynağı
                                        olurdu. Panelin kaydı, panelin cihaza yazdığı şeydir.
                                    --}}
                                    <x-ui.alert variant="info">
                                        Parola cihaza yazılır ve rastgele turla aynı güvenli sıradan geçer:
                                        kurtarma hesabı açılır, denenir, parola değişir, yeni parola denenir,
                                        panelin kaydı ancak ondan sonra güncellenir. İşlem birkaç dakika sürer.
                                    </x-ui.alert>
                                </div>
                                <x-ui.modal-actions submit="Cihaza yaz" />
                            </form>
                        </x-ui.modal>
                    @endunless
                @endcan
            @endforeach
        </div>
    </div>
</x-layout.app>
