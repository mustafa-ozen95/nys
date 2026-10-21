<x-layout.app :title="$switch->hostname.' · Route’lar'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Route’lar</span>
                <span class="column__count">{{ $routes->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchRoutes', $switch)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="route-ekle">Route ekle</button>
                @endcan
            </div>

            <div class="column__body">
                <div class="grid-row__head ip-row ip-row__head">
                    <span class="ip-row__adres">Hedef</span>
                    <span class="ip-row__arayuz">Sonraki atlama</span>
                    <span class="ip-row__port">Protokol</span>
                    <span class="ip-row__eylem"></span>
                </div>

                @forelse ($routes as $route)
                    <div class="grid-row grid-row--static ip-row">
                        <span class="mono ip-row__adres">{{ $route->cidr }}</span>
                        {{--
                            BÜTÜN sonraki atlamalar gösterilir. Yalnızca ilki
                            gösterilirse kullanıcı yedekli çıkışın varlığını
                            hiç bilmez.
                        --}}
                        <span class="mono text-muted ip-row__arayuz">
                            {{ count($route->next_hops ?? []) > 1
                                ? implode(', ', $route->next_hops)
                                : ($route->next_hop ?: '—') }}
                        </span>
                        <span class="ip-row__port">
                            <x-ui.badge :variant="$route->protocol->badge()">{{ $route->protocol->label() }}</x-ui.badge>

                            {{-- Yapılandırmada duran ama routing tablosuna kurulmamış yol:
                                 sonraki atlaması erişilemiyor demektir. --}}
                            @unless ($route->is_active)
                                <x-ui.badge variant="yellow">Pasif</x-ui.badge>
                            @endunless

                            {{--
                                Yolun ayırt edici alanları rozet olarak
                                görünür: kullanıcı listeyi açmadan hangi
                                route'un yedek, etiketli ya da korunan satır
                                taşıdığını görsün.
                            --}}
                            @if ($route->preference !== null)
                                <x-ui.badge variant="gray" title="Tercih (preference)">pref {{ $route->preference }}</x-ui.badge>
                            @endif

                            @if ($route->qualified_next_hops)
                                <x-ui.badge variant="blue"
                                            title="Kendi tercihiyle çalışan yedek atlama">{{ count($route->qualified_next_hops) }} yedek</x-ui.badge>
                            @endif

                            @if ($route->tag !== null)
                                <x-ui.badge variant="gray" title="Politika eşleşmesinde kullanılan etiket">tag {{ $route->tag }}</x-ui.badge>
                            @endif

                            @foreach ($route->known_flags as $bayrak)
                                <x-ui.badge :variant="$bayrak->badge()" :title="$bayrak->label()">{{ $bayrak->value }}</x-ui.badge>
                            @endforeach

                            {{--
                                Panelin göstermediği ama KORUNAN satırlar.
                                Bu satırlar route'u salt okunur yapmaz: route
                                düzenlenebilir ve o satırlar kaydedildiğinde
                                olduğu gibi kalır.
                            --}}
                            @if ($route->preserved_lines)
                                <x-ui.badge variant="cyan"
                                            title="Panelde gösterilmeyen ama korunan satırlar: {{ implode(', ', $route->preserved_lines) }}">{{ count($route->preserved_lines) }} korunan satır</x-ui.badge>
                            @endif
                        </span>

                        <span class="ip-row__eylem">
                            {{-- Yalnızca statik yollar değiştirilebilir. Öğrenilen
                                 yollarda eylem yok; protokol rozeti zaten
                                 (Doğrudan / Yerel / Diğer) bunu söylüyor. --}}
                            @can('updateSwitchRoutes', $switch)
                                @if ($writable[$route->uuid])
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="route-{{ $route->uuid }}">Düzenle</button>
                                @endif
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="Route yok"
                                text="Tablo cihazdan NETCONF ile okunur. Henüz eşitleme yapılmadıysa network:sync-netconf çalıştırın." />
                @endforelse
            </div>
        </div>
    </div>

    @can('createSwitchRoutes', $switch)
        <x-ui.modal id="route-ekle" title="Yeni statik route">
            <form method="POST" action="{{ route('switches.routes.store', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar. Geri alınabilir commit kullanılır."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-switching.route-fields :network-options="$networkOptions"
                                              :next-hop-options="$nextHopOptions" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        @foreach ($routes as $route)
            @if ($writable[$route->uuid])
                <x-ui.modal :id="'route-'.$route->uuid" :title="$route->cidr">
                    <form method="POST" action="{{ route('switches.routes.update', [$switch, $route]) }}"
                          data-confirm="Bu işlem cihaza commit atar. Geri alınabilir commit kullanılır."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $route->cidr }}">
                        @csrf
                        @method('PUT')
                        <x-form.version :record="$route" />
                        <div class="modal__body stack">
                            <x-switching.route-fields :route="$route" :prefix="'route-'.$route->uuid"
                                                      :network-options="$networkOptions"
                                                      :next-hop-options="$nextHopOptions" />
                        </div>
                        <x-ui.modal-actions submit="Cihaza yaz">
                            <button type="submit" form="route-sil-{{ $route->uuid }}"
                                    class="btn btn--danger btn--sm">Sil</button>
                            <span class="spacer"></span>
                        </x-ui.modal-actions>
                    </form>

                    <form method="POST" id="route-sil-{{ $route->uuid }}" hidden
                          action="{{ route('switches.routes.destroy', [$switch, $route]) }}"
                          data-confirm="{{ $route->cidr }} route’u CİHAZDAN silinecek."
                          data-confirm-title="Route’u cihazdan sil" data-confirm-variant="danger"
                          data-confirm-verify="{{ $route->cidr }}">
                        @csrf
                        @method('DELETE')
                    </form>
                </x-ui.modal>
            @endif
        @endforeach
    @endcan
</x-layout.app>
