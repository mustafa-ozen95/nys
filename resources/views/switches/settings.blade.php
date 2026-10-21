<x-layout.app :title="$switch->hostname.' ayarları'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :objects="$objects" :switch="$switch" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Ayarlar</span>
            </div>

            <div class="column__body">
                <x-ui.flash />

                {{--
                    Cihaz kimliği. Ad cihazda da envanterde de aynı olmalı;
                    ikisi ayrışırsa hangisinin doğru olduğu belirsiz kalır,
                    o yüzden tek formda yazılır.
                --}}
                <x-ui.panel title="Cihaz kimliği"
                            subtitle="Cihaz adı, saat dilimi ve isim/zaman/günlük sunucuları. Hepsi tek commit’te yazılır.">
                    {{--
                        Saat sapması: cihazın saati yanlışsa (ör. pil bitmiş,
                        NTP yok) commit geçmişi, günlükler ve panelin
                        kayıtlarıyla yapılan her eşleştirme yanlış zamana
                        düşer; bu yüzden sapma burada açıkça gösterilir.
                    --}}
                    @if ($clockDrift)
                        <x-ui.alert variant="warning">
                            Cihazın saati panelden <strong>{{ $clockDrift['label'] }}</strong> sapmış.
                            NTP sunucusu tanımlı olsa bile fark bir saati aştığında NTP saati kendiliğinden
                            düzeltmez; “açılış sunucusu” tanımlanmalı ya da saat aşağıdan elle eşitlenmelidir.
                            @if ($clockDrift['checked_at'])
                                <span class="text-muted">(ölçüm: {{ $clockDrift['checked_at'] }})</span>
                            @endif

                            @can('configure', $switch)
                                <form method="POST" action="{{ route('switches.device-settings.clock', $switch) }}"
                                      data-confirm="Cihazın saati NTP sunucusundan hemen düzeltilecek. Yapılandırma değişmez."
                                      data-confirm-title="Saati eşitle">
                                    @csrf
                                    <button type="submit" class="btn btn--sm">Saati şimdi eşitle</button>
                                </form>
                            @endcan
                        </x-ui.alert>
                    @endif

                    <form method="POST" action="{{ route('switches.device-settings', $switch) }}"
                          data-confirm="Cihaz ayarları commit edilecek. Cihaz adı hem cihazda hem envanterde değişir."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('PUT')
                        <x-form.version :record="$switch" />

                        <div class="form-grid">
                            <x-form.field name="hostname" label="Cihaz adı" id="cihaz-adi"
                                          hint="Junos “system host-name”. Harf, rakam, nokta, tire ve alt çizgi.">
                                <x-form.input name="hostname" id="cihaz-adi" :value="$switch->hostname" mono required />
                            </x-form.field>

                            <x-form.field name="time_zone" label="Saat dilimi" id="saat-dilimi"
                                          hint="Örnek: Europe/Istanbul. Boş bırakılırsa cihazdan silinir.">
                                <x-form.input name="time_zone" id="saat-dilimi" :value="$switch->time_zone"
                                              mono placeholder="Europe/Istanbul" />
                            </x-form.field>

                            <x-form.field name="name_servers" label="DNS sunucuları" id="dns"
                                          hint="Her satıra bir adres. Sıra anlamlıdır: ilk yanıt veren kullanılır.">
                                <x-form.textarea name="name_servers" id="dns" rows="3"
                                                 class="input--mono" :value="$nameServers" />
                            </x-form.field>

                            <x-form.field name="ntp_servers" label="NTP sunucuları" id="ntp"
                                          hint="Her satıra bir adres.">
                                <x-form.textarea name="ntp_servers" id="ntp" rows="3"
                                                 class="input--mono" :value="$ntpServers" />
                            </x-form.field>

                            <x-form.field name="ntp_boot_server" label="NTP açılış sunucusu" id="ntp-boot"
                                          hint="Açılışta saati ZORLA düzeltir. Bu olmadan cihazın saati bir saatten
                                                fazla sapmışsa NTP onu asla toparlayamaz; sunucular listesi tek başına
                                                yetmez.">
                                <x-form.input name="ntp_boot_server" id="ntp-boot"
                                              class="input--mono" :value="$ntpBootServer" />
                            </x-form.field>

                            {{--
                                Protokol anahtarları. Erişim switchlerinde
                                sıkça tanımlıdırlar; cihazda duran ama panelde
                                olmayan her satır "panelden yönetiyoruz"
                                iddiasını zayıflatır.

                                KAPATMAK SİLER: kapsamı boşaltmak değil,
                                protokolü cihazdan kaldırır.

                                Bu ekranda basit/gelişmiş ayrımı YOKTUR:
                                cihaz ayarları yılda birkaç kez, bilerek
                                açılan bir iştir. Zaten seyrek olan bir işin
                                içinde ikinci bir seyreklik katmanı, alanı
                                korumaz; yalnızca onu bulunamaz kılar.
                            --}}
                            @foreach ([
                                'lldp' => ['LLDP', 'Komşu keşfi. Kapsam: arayüz adları ya da “all”.'],
                                'lldp_med' => ['LLDP-MED', 'IP telefonlar için ek bilgi. LLDP ile birlikte kullanılır.'],
                                'igmp_snooping' => ['IGMP snooping', 'Çoklu gönderim trafiğini yalnızca isteyen porta iletir. Kapsam: VLAN adları ya da “all”.'],
                            ] as $anahtar => [$etiket, $ipucu])
                                <x-form.field :name="$anahtar.'_enabled'" :label="$etiket"
                                              :id="$anahtar.'-acik'" :hint="$ipucu">
                                    <label class="checkbox">
                                        <input type="hidden" name="{{ $anahtar }}_enabled" value="0">
                                        <input type="checkbox" name="{{ $anahtar }}_enabled"
                                               id="{{ $anahtar }}-acik" value="1"
                                               @checked($protocols[$anahtar]['enabled'])>
                                        <span>Açık</span>
                                    </label>
                                </x-form.field>

                                <x-form.field :name="$anahtar.'_scope'" label="Kapsam"
                                              :id="$anahtar.'-kapsam'"
                                              hint="Her satıra bir ad. Boş bırakılırsa “all” yazılır.">
                                    <x-form.textarea :name="$anahtar.'_scope'" :id="$anahtar.'-kapsam'" rows="2"
                                                     class="input--mono" :value="$protocols[$anahtar]['scope']" />
                                </x-form.field>
                            @endforeach

                            <x-form.field name="syslog_hosts" label="Günlük (syslog) sunucuları" id="syslog"
                                          class="field--wide"
                                          hint="Her satıra bir adres. Cihaz “any notice” seviyesinde gönderir.">
                                <x-form.textarea name="syslog_hosts" id="syslog" rows="2"
                                                 class="input--mono" :value="$syslogHosts" />
                            </x-form.field>

                            {{--
                                Şasi alarmları. Bir port düştüğünde cihazın
                                ön panelindeki lambayı ve “show chassis alarms”
                                çıktısını belirler; izleme sistemi bunu okur.
                            --}}
                            <x-form.field name="alarm_ethernet_link_down" label="Port düşmesi alarmı" id="alarm-port"
                                          hint="Herhangi bir kullanıcı portu düştüğünde cihazın vereceği alarm.">
                                <x-form.select name="alarm_ethernet_link_down" id="alarm-port"
                                               :options="$alarmOptions"
                                               :selected="old('alarm_ethernet_link_down', $switch->alarm_ethernet_link_down)" />
                            </x-form.field>

                            <x-form.field name="alarm_management_link_down" label="Yönetim portu alarmı" id="alarm-yonetim"
                                          hint="Yönetim arayüzü düştüğünde verilecek alarm.">
                                <x-form.select name="alarm_management_link_down" id="alarm-yonetim"
                                               :options="$alarmOptions"
                                               :selected="old('alarm_management_link_down', $switch->alarm_management_link_down)" />
                            </x-form.field>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary btn--sm">Cihaza yaz</button>
                        </div>
                    </form>
                </x-ui.panel>

                <x-ui.panel title="SNMP kimliği"
                            subtitle="Cihazın snmp name, description ve engine-id değerleri bu adla yazılır. Konum ve iletişim izleme sisteminde görünür.">
                    <form method="POST" action="{{ route('switches.snmp-identity', $switch) }}"
                          data-confirm="Bu işlem cihaza commit atar. Geri alınabilir commit kullanılır: onay verilemezse cihaz kendini eski hâline döndürür."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('PUT')

                        <div class="form-grid">
                            <x-form.field name="name" label="SNMP adı (sysName)" id="snmp-adi"
                                          hint="Envanterdeki ad önerilir; cihazdaki ad ondan farklı olabilir.">
                                <x-form.input name="name" id="snmp-adi" :value="old('name', $snmpName)" mono required />
                            </x-form.field>

                            {{-- Boş bırakmak cihazdaki değeri SİLER. --}}
                            <x-form.field name="location" label="Konum" id="snmp-konum"
                                          hint="Cihazın bulunduğu yer. Boş bırakılırsa cihazdan silinir.">
                                <x-form.input name="location" id="snmp-konum"
                                              :value="old('location', $switch->snmp_location)" placeholder="Lokasyon-A" />
                            </x-form.field>

                            <x-form.field name="contact" label="İletişim" id="snmp-iletisim"
                                          hint="Sorumlu ekip ya da e-posta.">
                                <x-form.input name="contact" id="snmp-iletisim"
                                              :value="old('contact', $switch->snmp_contact)" placeholder="noc@ornek.test" />
                            </x-form.field>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary btn--sm">Cihaza yaz</button>
                        </div>
                    </form>
                </x-ui.panel>

                {{--
                    SNMP community'leri. Ad okuma parolası gibi çalışır:
                    ekranda hiç basılmaz, yalnızca son iki karakteri görünür.
                    Ad cihazda kimliktir; değiştirmek silip yeniden yaratmak
                    demektir, o yüzden düzenlemede sorulmaz.
                --}}
                <x-ui.panel title="SNMP community" subtitle="İzleme sisteminin cihazdan veri çekmek için kullandığı anahtarlar. Adlar ekranda gösterilmez.">
                    <x-slot:actions>
                        <button type="button" class="btn btn--primary btn--sm"
                                data-modal-open="community-ekle">Community ekle</button>
                    </x-slot:actions>

                    @forelse ($communities as $community)
                        <div class="grid-row grid-row--static ayar-row">
                            <span class="mono">{{ $community->masked_name }}</span>
                            <span>
                                <x-ui.badge :variant="$community->authorization === 'read-write' ? 'orange' : 'gray'">
                                    {{ $authorizationOptions[$community->authorization] ?? $community->authorization }}
                                </x-ui.badge>
                            </span>
                            <span class="mono text-muted truncate">
                                {{ ($community->clients ?? []) === [] ? 'Herkes' : implode(', ', $community->clients) }}
                            </span>
                            <span class="text-right">
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="community-{{ $community->uuid }}">Düzenle</button>
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-muted">
                            Cihazda tanımlı community yok. İzleme sistemi veri çekemez.
                        </p>
                    @endforelse
                </x-ui.panel>

                {{--
                    Panelin cihaza bağlandığı hesabın parolası. Ekranda
                    basılmaz; göz simgesine basıldığında ayrı bir istekle
                    alınır ve her gösterim kayda geçer.
                --}}
                @can('secret', $switch)
                    <x-ui.panel title="Cihaz erişim parolası"
                                subtitle="Panelin bu switche bağlanmak için kullandığı hesap. Göz simgesine basınca parolanın DÜZ hâli görünür (özet değil); her gösterim kayda geçer ve değer yarım dakika sonra kendiliğinden gizlenir.">
                        {{--
                            Sır ESNEK sütunda durur: açılan değer uzundur ve
                            sabit genişlikli bir hücrede yandaki sütunun
                            üstüne taşar.
                        --}}
                        <div class="grid-row grid-row--static sir-row">
                            <span class="text-muted">Hesap</span>
                            <span class="truncate">
                                <x-ui.secret :source="route('switches.password', $switch)"
                                             :username="$switch->netconfCredential?->username ?? $switch->sshCredential?->username" />
                            </span>
                            <span class="text-right">
                                @can('rotate', $switch)
                                    <a href="{{ route('settings.passwords') }}" class="btn btn--sm">Değiştir</a>
                                @endcan
                            </span>
                        </div>
                    </x-ui.panel>
                @endcan

                {{--
                    Cihazdaki yerel hesaplar. Panel kullanıcılarından ayrıdır:
                    konsoldan ya da SSH’tan bağlanan bunları kullanır. Panelin
                    bağlandığı hesap silinemez ve yetkisi düşürülemez.
                --}}
                <x-ui.panel title="Cihaz hesapları"
                            subtitle="Switche konsoldan ya da SSH’tan bağlananların kullandığı yerel hesaplar. Parolalar cihazda tutulur, panelde saklanmaz.">
                    <x-slot:actions>
                        <button type="button" class="btn btn--primary btn--sm"
                                data-modal-open="hesap-ekle">Hesap ekle</button>
                    </x-slot:actions>

                    @forelse ($loginUsers as $loginUser)
                        <div class="grid-row grid-row--static ayar-row">
                            <span class="mono">
                                {{ $loginUser->name }}
                                @if ($loginUser->is_panel_account)
                                    <x-ui.badge variant="blue">panel</x-ui.badge>
                                @endif
                            </span>
                            <span>
                                <x-ui.badge :variant="$loginUser->junos_class?->badge()->value ?? 'gray'">
                                    {{ $userClassOptions[$loginUser->class] ?? $loginUser->class }}
                                </x-ui.badge>
                            </span>
                            <span class="text-muted truncate">
                                {{ $loginUser->summary }}
                            </span>
                            <span class="text-right">
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="hesap-{{ $loginUser->uuid }}">Düzenle</button>
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-muted">
                            Cihazda okunmuş yerel hesap yok.
                        </p>
                    @endforelse
                </x-ui.panel>

                <x-ui.panel title="Yapılandırma yedeği"
                            subtitle="Cihaz her commit sonrası yapılandırmasını kendi yedek sunucusuna gönderir. Adres bu cihaza özgüdür ve kimlik taşıdığı için ekranda gösterilmez.">
                    <form method="POST" action="{{ route('switches.archival', $switch) }}"
                          data-confirm="{{ $switch->hostname }} cihazına yapılandırma yedeği ayarı yazılacak. Geri alınabilir commit kullanılır."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('PUT')
                        <x-form.version :record="$switch" />

                        <div class="form-grid">
                            <x-form.field name="enabled" label="Commit sonrası gönder" id="yedek-acik"
                                          hint="Junos’ta “transfer-on-commit”. Kapatılırsa ayar cihazdan silinir.">
                                <label class="checkbox">
                                    <input type="checkbox" name="enabled" value="1" id="yedek-acik"
                                           @checked(old('enabled', $switch->archival_enabled))>
                                    <span>Açık</span>
                                </label>
                            </x-form.field>

                            <x-form.field name="site" label="Yedek adresi" id="yedek-adres"
                                          :hint="$archivalCustom
                                              ? 'Bu cihaza özel adres kayıtlı. Boş bırakılırsa korunur.'
                                              : 'Girilmezse .env’deki varsayılan sunucu kullanılır.'">
                                <x-form.input type="password" name="site" id="yedek-adres" mono
                                              autocomplete="new-password"
                                              placeholder="ftp://kullanici:parola@192.0.2.51/sw1/" />
                            </x-form.field>

                            @if ($archivalCustom)
                                <x-form.field name="reset_site" label="Kayıtlı adres" id="yedek-sifirla"
                                              hint="Silinirse cihaz varsayılan sunucuya döner.">
                                    <label class="checkbox">
                                        <input type="checkbox" name="reset_site" value="1" id="yedek-sifirla">
                                        <span>Sil, varsayılana dön</span>
                                    </label>
                                </x-form.field>
                            @endif
                        </div>

                        @unless ($archivalTargetKnown)
                            <p class="text-sm text-muted">
                                Ne bu cihaza özel adres ne de varsayılan sunucu tanımlı; yedek açılamaz.
                            </p>
                        @endunless

                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary btn--sm">Cihaza yaz</button>
                        </div>
                    </form>
                </x-ui.panel>

            </div>
        </div>
    </div>

    <x-ui.modal id="community-ekle" title="SNMP community ekle">
        <form method="POST" action="{{ route('switches.snmp-communities.store', $switch) }}"
              data-confirm="Cihaza yeni bir SNMP community yazılacak."
              data-confirm-title="Cihaza yaz"
              data-confirm-verify="{{ $switch->hostname }}">
            @csrf
            <div class="modal__body stack">
                <x-form.field name="name" label="Community" id="community-adi"
                              hint="Cihazda kimliktir ve sonradan değiştirilemez. Ekranda bir daha gösterilmez.">
                    <x-form.input type="password" name="name" id="community-adi"
                                  autocomplete="new-password" mono required />
                </x-form.field>

                <x-form.field name="authorization" label="Yetki" id="community-yetki"
                              hint="Okuma yeterlidir; yazma yetkisi cihazı SNMP üzerinden değiştirilebilir kılar.">
                    <x-form.select name="authorization" id="community-yetki"
                                   :options="$authorizationOptions" selected="read-only" />
                </x-form.field>

                <x-form.field name="clients" label="İzinli ağlar" id="community-istemci"
                              hint="Her satıra bir ağ: 198.18.20.0/24. Boş bırakılırsa herkes erişebilir.">
                    <x-form.textarea name="clients" id="community-istemci" rows="3" class="input--mono" />
                </x-form.field>
            </div>
            <x-ui.modal-actions submit="Cihaza yaz" />
        </form>
    </x-ui.modal>

    @foreach ($communities as $community)
        <x-ui.modal :id="'community-'.$community->uuid" :title="$community->masked_name.' · community'">
            <form method="POST" action="{{ route('switches.snmp-communities.update', [$switch, $community]) }}"
                  data-confirm="Community ayarları cihaza yazılacak."
                  data-confirm-title="Cihaza yaz"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_duzenleme" value="1">
                <div class="modal__body stack">
                    <x-form.field name="authorization" label="Yetki" :id="'yetki-'.$community->uuid">
                        <x-form.select name="authorization" :id="'yetki-'.$community->uuid"
                                       :options="$authorizationOptions" :selected="$community->authorization" />
                    </x-form.field>

                    <x-form.field name="clients" label="İzinli ağlar" :id="'istemci-'.$community->uuid"
                                  hint="Her satıra bir ağ. Boş bırakılırsa herkes erişebilir.">
                        <x-form.textarea name="clients" :id="'istemci-'.$community->uuid" rows="3"
                                         class="input--mono" :value="$community->clients" />
                    </x-form.field>
                </div>
                <x-ui.modal-actions submit="Cihaza yaz">
                    <button type="submit" form="community-sil-{{ $community->uuid }}"
                            class="btn btn--danger btn--sm">Sil</button>
                    <span class="spacer"></span>
                </x-ui.modal-actions>
            </form>

            <form method="POST" id="community-sil-{{ $community->uuid }}" hidden
                  action="{{ route('switches.snmp-communities.destroy', [$switch, $community]) }}"
                  data-confirm="Bu community CİHAZDAN silinecek. İzleme sistemi bu anahtarı kullanıyorsa veri çekemez olur."
                  data-confirm-title="Community sil" data-confirm-variant="danger"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                @method('DELETE')
            </form>
        </x-ui.modal>
    @endforeach

    <x-ui.modal id="hesap-ekle" title="Cihaz hesabı ekle">
        <form method="POST" action="{{ route('switches.login-users.store', $switch) }}"
              data-confirm="Cihaza yeni bir giriş hesabı yazılacak."
              data-confirm-title="Cihaza yaz"
              data-confirm-verify="{{ $switch->hostname }}">
            @csrf
            <div class="modal__body stack">
                <x-form.field name="name" label="Hesap adı" id="hesap-adi"
                              hint="Cihazda kimliktir ve sonradan değiştirilemez.">
                    <x-form.input name="name" id="hesap-adi" mono required autocomplete="off" />
                </x-form.field>

                <x-form.field name="class" label="Yetki sınıfı" id="hesap-sinif"
                              hint="Yalnızca süper kullanıcı yapılandırma yazabilir.">
                    <x-form.select name="class" id="hesap-sinif"
                                   :options="$userClassOptions" selected="read-only" />
                </x-form.field>

                <x-form.field name="full_name" label="Tam ad" id="hesap-tam-ad"
                              hint="Kimin hesabı olduğunu yazmak, sonradan bakanın işini kolaylaştırır.">
                    <x-form.input name="full_name" id="hesap-tam-ad" />
                </x-form.field>

                <x-form.field name="uid" label="uid" id="hesap-uid"
                              hint="Boş bırakılırsa cihaz seçer. 100 ile 64000 arası.">
                    <x-form.input type="number" name="uid" id="hesap-uid" min="100" max="64000" mono />
                </x-form.field>

                <x-form.field name="password" label="Parola" id="hesap-parola"
                              hint="En az 8 karakter; rakam ya da noktalama içermeli. Cihazda saklanır, panelde tutulmaz.">
                    <x-form.input type="password" name="password" id="hesap-parola"
                                  autocomplete="new-password" />
                </x-form.field>

                <x-form.field name="password_confirmation" label="Parola (tekrar)" id="hesap-parola-tekrar">
                    <x-form.input type="password" name="password_confirmation" id="hesap-parola-tekrar"
                                  autocomplete="new-password" />
                </x-form.field>
            </div>
            <x-ui.modal-actions submit="Cihaza yaz" />
        </form>
    </x-ui.modal>

    @foreach ($loginUsers as $loginUser)
        <x-ui.modal :id="'hesap-'.$loginUser->uuid" :title="$loginUser->name.' · cihaz hesabı'">
            <form method="POST" action="{{ route('switches.login-users.update', [$switch, $loginUser]) }}"
                  data-confirm="Hesap ayarları cihaza yazılacak."
                  data-confirm-title="Cihaza yaz"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_duzenleme" value="1">
                <div class="modal__body stack">
                    @if ($loginUser->is_panel_account)
                        <x-ui.alert variant="warning">
                            Panel cihaza bu hesapla bağlanıyor. Hesap silinemez, yetkisi de
                            süper kullanıcının altına düşürülemez. Parolası buradan
                            değiştirilmez: cihazdaki parola değişir, paneldeki kayıt eski kalır
                            ve switch bir daha yönetilemez.
                            <a href="{{ route('settings.passwords') }}">Ayarlar → Parolalar</a>
                            ekranı parolayı cihaza yazarken panele de kaydeder.
                        </x-ui.alert>
                    @endif

                    <x-form.field name="class" label="Yetki sınıfı" :id="'sinif-'.$loginUser->uuid">
                        <x-form.select name="class" :id="'sinif-'.$loginUser->uuid"
                                       :options="$userClassOptions" :selected="$loginUser->class" />
                    </x-form.field>

                    <x-form.field name="full_name" label="Tam ad" :id="'tam-ad-'.$loginUser->uuid"
                                  hint="Boş bırakılırsa cihazdan silinir.">
                        <x-form.input name="full_name" :id="'tam-ad-'.$loginUser->uuid"
                                      :value="$loginUser->full_name" />
                    </x-form.field>

                    {{--
                        Panel hesabının parola alanı hiç çizilmez: sunucu zaten
                        reddediyor, ama olmayan bir seçeneği sunmak kullanıcıyı
                        boşuna denemeye çağırır.
                    --}}
                    @unless ($loginUser->is_panel_account)
                        <x-form.field name="password" label="Yeni parola" :id="'parola-'.$loginUser->uuid"
                                      hint="Boş bırakılırsa cihazdaki parola değişmez.">
                            <x-form.input type="password" name="password" :id="'parola-'.$loginUser->uuid"
                                          autocomplete="new-password" />
                        </x-form.field>

                        <x-form.field name="password_confirmation" label="Yeni parola (tekrar)"
                                      :id="'parola-tekrar-'.$loginUser->uuid">
                            <x-form.input type="password" name="password_confirmation"
                                          :id="'parola-tekrar-'.$loginUser->uuid" autocomplete="new-password" />
                        </x-form.field>
                    @endunless
                </div>
                <x-ui.modal-actions submit="Cihaza yaz">
                    @unless ($loginUser->is_panel_account)
                        <button type="submit" form="hesap-sil-{{ $loginUser->uuid }}"
                                class="btn btn--danger btn--sm">Sil</button>
                        <span class="spacer"></span>
                    @endunless
                </x-ui.modal-actions>
            </form>

            @unless ($loginUser->is_panel_account)
                <form method="POST" id="hesap-sil-{{ $loginUser->uuid }}" hidden
                      action="{{ route('switches.login-users.destroy', [$switch, $loginUser]) }}"
                      data-confirm="{{ $loginUser->name }} hesabı CİHAZDAN silinecek. Bu hesapla bağlananlar bir daha giremez."
                      data-confirm-title="Hesabı sil" data-confirm-variant="danger"
                      data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('DELETE')
                </form>
            @endunless
        </x-ui.modal>
    @endforeach
</x-layout.app>
