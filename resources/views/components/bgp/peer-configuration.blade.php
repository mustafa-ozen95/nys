@props(['neighbor', 'configuration', 'ours'])

<div class="modal__body stack">
    @unless ($configuration['complete'])
        <x-ui.alert variant="warning">
            Karşı tarafın configi EKSİK. Aşağıdaki notlarda yazan alanlar
            tamamlanmadan gönderilmemeli.
        </x-ui.alert>
    @endunless

    <p class="text-sm text-muted">
        Karşı taraf kendi configini <span class="mono">configure</span> modunda
        yapıştırıp <span class="mono">commit</span> eder. Blok yalnızca komut içerir;
        araya açıklama karıştırılmamalıdır.
    </p>

    <x-form.field kural="switch_bgp.karsi-taraf:peer_config" name="peer_config" label="Karşı tarafın configi" :id="'peer-metin-'.$neighbor->uuid"
                  hint="Bizim tarafın aynasıdır: bizim komşu adresimiz onların yerel adresi olur.">
        <textarea class="textarea input--mono" id="peer-metin-{{ $neighbor->uuid }}"
                  rows="{{ min(count($configuration['lines']) + 1, 18) }}" readonly
                  data-kopyala-kaynak>{{ implode(PHP_EOL, $configuration['lines']) }}</textarea>
    </x-form.field>

    @if ($configuration['interface'] !== [])
        <x-form.field kural="switch_bgp.karsi-taraf:peer_interface" name="peer_interface" label="Karşı tarafın bacak adresi"
                      :id="'peer-arayuz-'.$neighbor->uuid"
                      hint="ARAYUZ yerine kendi port adlarını yazmalılar; o yüzden ayrı duruyor.">
            <textarea class="textarea input--mono" id="peer-arayuz-{{ $neighbor->uuid }}"
                      rows="2" readonly
                      data-kopyala-kaynak>{{ implode(PHP_EOL, $configuration['interface']) }}</textarea>
        </x-form.field>
    @endif

    {{--
        Bizim taraf: cihaza yazmak için DEĞİL. Panel yazmayı kendisi yapıyor;
        bu metin kaydı gözden geçirmek ve gerekirse aynı oturumu başka bir
        cihaza kurmak için var.
    --}}
    <x-form.field kural="switch_bgp.karsi-taraf:our_config" name="our_config" label="Bizim taraf" :id="'bizim-metin-'.$neighbor->uuid"
                  hint="Bu oturumun bizdeki hâli. Cihaza yazmak için değil; panel yazmayı kendisi yapar.">
        <textarea class="textarea input--mono" id="bizim-metin-{{ $neighbor->uuid }}"
                  rows="{{ min(count($ours['lines']) + 1, 18) }}" readonly
                  data-kopyala-kaynak>{{ implode(PHP_EOL, $ours['lines']) }}</textarea>
    </x-form.field>

    @if ($configuration['notes'] !== [] || $ours['notes'] !== [])
        <div class="stack stack--tight">
            <span class="eyebrow">Notlar</span>
            @foreach ([...$configuration['notes'], ...$ours['notes']] as $note)
                <p class="text-sm text-muted">{{ $note }}</p>
            @endforeach
        </div>
    @endif
</div>

<x-ui.modal-actions :submit="false" cancel="Kapat">
    <button type="button" class="btn"
            data-kopyala="#bizim-metin-{{ $neighbor->uuid }}">Bizim tarafı kopyala</button>
    <button type="button" class="btn btn--primary"
            data-kopyala="#peer-metin-{{ $neighbor->uuid }}">Karşı tarafı kopyala</button>
</x-ui.modal-actions>
