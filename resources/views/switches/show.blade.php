<x-layout.app :title="$switch->hostname">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Genel bakış</span>
            </div>

            <div class="column__body column__body--padded">
                <div class="stack--loose stack">
                    <x-ui.panel title="Switch bilgileri">
                        <x-ui.facts :rows="[
                            'Switch adı' => $switch->hostname,
                            'Yönetim IP' => $switch->management_ip,
                            'Uplink IP' => $switch->uplink_ip,
                            'Model' => $switch->model,
                            'JunOS sürümü' => $switch->junos_version,

                            {{--
                                Şema ailesi panelin cihaza HANGİ SÖZDİZİMİ ile
                                yazacağını belirler (irb/vlan, interface-mode/
                                port-mode, hızın yeri…). Bilinmiyorsa panel ELS
                                ÖNCESİ varsayar; yanlış ailede bu, cihazın
                                yazmayı reddetmesi demektir. Operatör bunu
                                görmeden hatanın sebebini anlayamaz.
                            --}}
                            'Şema ailesi' => $switch->schema_profile?->label()
                                ?? 'Bilinmiyor — ELS öncesi varsayılıyor',
                            'Rol' => $switch->role->label(),
                            'Konum' => $switch->location->label(),
                        ]" />
                    </x-ui.panel>

                    <x-ui.panel title="Erişim">
                        <x-ui.facts :rows="$access" />
                    </x-ui.panel>
                </div>
            </div>
        </div>

        <aside class="detail">
            <div class="detail__head">
                <div class="row">
                    <x-ui.status-dot :status="$switch->status" class="detail__title detail__title--plain" />
                </div>
                <p class="detail__subtitle mono">{{ $switch->management_ip }}</p>
            </div>

            <div class="detail__body">
                <div class="detail__section">
                    <span class="eyebrow">Anlık ölçümler</span>
                    <div class="stack stack--tight detail__block">
                        <div class="row row--between">
                            <span class="facts__key">İşlemci</span>
                            <x-ui.meter :value="$switch->cpu_usage" metric="cpu" />
                        </div>
                        <div class="row row--between">
                            <span class="facts__key">Bellek</span>
                            <x-ui.meter :value="$switch->memory_usage" metric="memory" />
                        </div>
                    </div>

                    <x-ui.facts class="detail__block" :rows="[
                        'Sıcaklık' => $switch->temperature ? $switch->temperature.' °C' : null,
                        'Çalışma süresi' => $uptime,
                        'Son eşitleme' => $lastSync,
                    ]" />
                </div>

                <div class="detail__section">
                    <span class="eyebrow">Envanter</span>
                    <x-ui.facts class="detail__block" :rows="$inventory" />
                </div>
            </div>

            @canany(['connect', 'update', 'configure', 'delete'], $switch)
                <div class="detail__foot">
                    @can('connect', $switch)
                        <button type="button" class="btn btn--primary btn--sm"
                                data-uzak-baglan="{{ route('ajax.remote.connect') }}"
                                data-uzak-tur="switch"
                                data-uzak-kimlik="{{ $switch->uuid }}">Bağlan</button>
                    @endcan

                    @can('update', $switch)
                        <button type="button" class="btn btn--sm" data-modal-open="switch-duzenle">Düzenle</button>
                    @endcan

                    {{--
                        Cihazın kendi ayarları (SNMP adı, yapılandırma yedeği)
                        nesne sütunundaki "Ayarlar" ekranındadır; burada
                        düğme olarak dağılmıyorlar.
                    --}}
                    @can('configure', $switch)
                        <a href="{{ route('switches.settings', $switch) }}" class="btn btn--sm">Ayarlar</a>
                    @endcan

                    @can('delete', $switch)
                        <x-ui.delete-form :action="route('switches.destroy', $switch)"
                                          confirm="Bu switch ve ona bağlı tüm kayıtlar silinecek."
                                          title="Switchi sil"
                                          verify="{{ $switch->hostname }}" />
                    @endcan
                </div>
            @endcanany
        </aside>
    </div>

    @can('update', $switch)
        <x-ui.modal id="switch-duzenle" :title="$switch->hostname.' düzenle'" wide>
            <form method="POST" action="{{ route('switches.update', $switch) }}"
                  data-confirm="{{ $switch->hostname }} envanter kaydı güncellenecek."
                  data-confirm-title="Switchi düzenle"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                @method('PUT')

                <div class="modal__body form-grid">
                    @include('switches.form', ['prefix' => 'duzenle'])
                </div>

                <x-ui.modal-actions submit="Kaydet" />
            </form>
        </x-ui.modal>
    @endcan

</x-layout.app>
