<x-layout.app title="Prefix anonsu">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Prefix anons talebi"
                            subtitle="Prefix listelerine eklenmesini istediğiniz blokları bildirin.">
                <x-slot:actions>
                    @can('viewAny', App\Models\AnnouncementRecipient::class)
                        <a href="{{ route('announcements.recipients.index') }}" class="btn btn--sm">Alıcıları yönet</a>
                    @endcan
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            <div class="split">
                <div class="split__main">
                <form method="POST" action="{{ route('announcements.preview') }}">
                    @csrf

                    <x-ui.panel title="Talep">
                        <x-form.field name="prefixes" label="Prefix listesi"
                                      hint="Her satıra bir blok yazın: 192.0.2.0/24">
                            <textarea class="textarea input--mono" name="prefixes" id="prefixes" rows="5"
                                      required>{{ old('prefixes') }}</textarea>
                        </x-form.field>

                        <div class="form-grid">
                            <x-form.field name="asn" label="Anons eden AS numarası"
                                          hint="Cihazlarda tanımlı ASN'ler listede. Müşteri kendi numarası için talep ediyorsa “elle gir” seçin.">
                                {{-- Liste kolaylıktır, kısıt değil: anons müşteri ASN'si için de yapılabilir. --}}
                                <x-form.picker name="asn" :options="$asnOptions" :value="$defaultAsn" manual
                                               placeholder="AS64500"
                                               manual-aria="AS numarasını elle girin" />
                            </x-form.field>

                            <x-form.field name="downstream" label="Downstream AS" hint="Zorunlu değil">
                                <x-form.input name="downstream" :value="old('downstream')" mono />
                            </x-form.field>
                        </div>

                        <x-form.field name="recipients" label="Alıcılar" class="field--separated">
                            <div class="row row--wrap recipient-list" data-selection-group>
                                @if ($recipients->isNotEmpty())
                                    <label class="checkbox recipient-list__all">
                                        <input type="checkbox" data-select-all>
                                        <span>Tümü</span>
                                    </label>
                                @endif

                                @forelse ($recipients as $recipient)
                                    <label class="checkbox">
                                        <input type="checkbox" name="recipients[]" value="{{ $recipient->uuid }}"
                                               @checked(in_array($recipient->uuid, old('recipients', []), true))>
                                        <span>{{ $recipient->label }}</span>
                                        <span class="text-sm text-muted mono">{{ $recipient->email }}</span>
                                        <x-ui.badge :variant="$recipient->locale->badge()">{{ $recipient->locale->short() }}</x-ui.badge>
                                    </label>
                                @empty
                                    <p class="text-sm text-muted">
                                        Tanımlı alıcı yok. Alıcıları yönetin bağlantısından ekleyin.
                                    </p>
                                @endforelse
                            </div>
                        </x-form.field>
                    </x-ui.panel>

                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary">Önizle</button>
                    </div>
                </form>

                @isset($messages)
                    <div class="stack announcement-result">
                        <x-announcement.authorization :report="$authorization" />

                        <h2>Gönderilecek metin</h2>

                        @foreach ($messages as $message)
                            <x-ui.panel :title="$message['label']" :subtitle="$message['email']">
                                <p class="text-sm text-muted">
                                    {{ $message['subject'] }} · {{ $message['locale'] === 'en' ? 'İngilizce' : 'Türkçe' }}
                                </p>
                                <pre class="announcement-preview">{{ $message['body'] }}</pre>
                            </x-ui.panel>
                        @endforeach

                        @if ($authorization->passes())
                            <form method="POST" action="{{ route('announcements.send') }}"
                                  data-confirm="Anons talebi alıcılara e-posta ile gönderilecek."
                                  data-confirm-title="Anonsu gönder"
                                  data-confirm-verify="GONDER">
                                @csrf
                                <input type="hidden" name="prefixes" value="{{ old('prefixes') }}">
                                <input type="hidden" name="asn" value="{{ old('asn') }}">
                                <input type="hidden" name="downstream" value="{{ old('downstream') }}">
                                @foreach (old('recipients', []) as $key)
                                    <input type="hidden" name="recipients[]" value="{{ $key }}">
                                @endforeach

                                <div class="form-actions">
                                    <button type="submit" class="btn btn--primary"
                                            data-confirm="{{ count($messages) }} alıcıya gerçek e-posta gönderilecek."
                                            data-confirm-title="Anonsu gönder">Gönder</button>
                                </div>
                            </form>
                        @else
                            {{-- Düğme çizilmez: gönderimi asıl durduran sunucudur (bkz.
                                 AnnouncementController::send), ekran onu tekrar etmez. --}}
                            <x-ui.alert variant="danger">{{ $authorization->refusal() }}</x-ui.alert>
                        @endif
                    </div>
                @endisset
                </div>

                <aside class="split__side">
                <x-ui.panel title="Gönderim geçmişi"
                            subtitle="Alıcı başına bir kayıt; metnin kendisi de saklanır." flush>
                    @forelse ($history as $mail)
                        <details class="mail-log" @if ($selectedMail?->is($mail)) open @endif>
                            <summary class="mail-log__head">
                                <x-ui.badge :variant="$mail->status->badge()">{{ $mail->status->label() }}</x-ui.badge>
                                <span class="mono mail-log__recipient">{{ $mail->recipient_email }}</span>
                                <span class="text-sm text-muted mono mail-log__prefix">{{ $mail->prefixes }}</span>
                                <span class="text-sm text-muted mail-log__language">{{ $mail->locale === 'en' ? 'EN' : 'TR' }}</span>
                                <span class="text-sm text-muted mail-log__time">{{ $mail->created_at->diffForHumans() }}</span>
                            </summary>

                            <div class="mail-log__body">
                                <x-ui.facts :rows="[
                                    'Alıcı' => $mail->recipient_label,
                                    'Konu' => $mail->subject,
                                    'AS numarası' => $mail->asn,
                                    'Downstream AS' => $mail->downstream_asn,
                                    'Gönderen' => $mail->user?->name,
                                    'Gönderim' => $mail->sent_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i'),
                                    'Hata' => $mail->error,
                                ]" />

                                <pre class="announcement-preview">{{ $mail->body }}</pre>
                            </div>
                        </details>
                    @empty
                        <x-ui.empty title="Henüz anons gönderilmedi"
                                    text="Gönderilen her mail burada metniyle birlikte saklanır." />
                    @endforelse
                </x-ui.panel>
                </aside>
            </div>
        </div>
    </div>
</x-layout.app>
