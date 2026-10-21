{{--
    Alan "advanced" işaretliyse BASİT modda düzenlenmez.

    Kararı bu şablon değil sınıf verir (App\View\Components\Form\Field):
    işaretli alanın girdisi basit modda DOM'a hiç girmez. Bu önemlidir —
    panelde birçok alanda "gönderilmedi" cihazdaki satırın SİLİNMESİ demektir,
    dolayısıyla CSS ile gizlemek yetmez, girdinin gerçekten çizilmemesi
    gerekir. Ayarları kuran taraf da yalnızca GELEN alanları yazar (bkz.
    PanelModeTest).

    Cihazda varsayılan dışı bir değer varsa alan yine de görünür: girdi değil,
    salt okunur tek satır. Basit mod sadeleştirir, gerçeği saklamaz.
--}}
@if ($locked())
    <div {{ $attributes->except('id')->class('field field--kilitli') }}>
        <span class="field__label">{{ $label }}</span>
        <span class="field__deger">{{ $current }}</span>
        <span class="field__hint">Cihazda tanımlı. Değiştirmek için gelişmiş moda geçin.</span>
    </div>
@else
    {{-- Basit modda kapatılmış alan: görünmez ama girdisi formda kalır (bkz. Field::gizli). --}}
    <div {{ $attributes->except(['id', 'kural'])->class(['field', 'field--basit-gizli' => $gizli()]) }}>
        <label class="field__label" for="{{ $id ?? $name }}">{{ $label }}</label>

        {{ $slot }}

        @if ($hint)
            <span class="field__hint">{{ $hint }}</span>
        @endif

        @error($name)
            <span class="field__error">{{ $message }}</span>
        @enderror
    </div>
@endif
