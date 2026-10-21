@props([
    'name',
    'label',
    'options' => [],
    'selected' => [],
    'hint' => null,
    'id' => null,
    'empty' => 'Seçenek yok.',
])

{{--
    Çoklu seçim listesi.

    Port aynalamadaki dört liste (giriş/çıkış × arayüz/VLAN) aynı işaretlemeyi
    kullanır; dördü tek bileşende durur ki biri değiştiğinde diğerleri
    sessizce ayrışmasın.

    Değerler dizidir: alan adı "ad[]" olarak gönderilir.
--}}
<x-form.field :name="$name" :label="$label" :hint="$hint" :id="$id ?? $name">
    @if (count($options) === 0)
        <span class="text-sm text-muted">{{ $empty }}</span>
    @else
        <div class="choices">
            @foreach ($options as $value => $etiket)
                <label class="checkbox">
                    <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}"
                           @checked(in_array((string) $value, array_map('strval', (array) old($name, $selected)), true))>
                    <span class="mono">{{ $etiket }}</span>
                </label>
            @endforeach
        </div>
    @endif
</x-form.field>
