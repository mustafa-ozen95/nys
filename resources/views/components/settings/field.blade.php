@props(['item', 'value' => null])

{{--
    Tek bir ayar alanı. Hangi girdi türünün basılacağı config/settings.php
    içindeki tanımdan gelir; ekran, doğrulama ve saklama aynı tanımı okur.
--}}
<x-form.field :name="$item->field()" :label="$item->label" :hint="$item->hint" :id="$item->field()">
    @switch ($item->type)
        @case ('boolean')
            <label class="checkbox">
                <input type="hidden" name="{{ $item->field() }}" value="0">
                <input type="checkbox"
                       name="{{ $item->field() }}"
                       id="{{ $item->field() }}"
                       value="1"
                       @checked(old($item->field(), $value))>
                <span>Açık</span>
            </label>
            @break

        @case ('select')
            <x-form.select :name="$item->field()" :id="$item->field()"
                           :options="$item->options()" :selected="$value" />
            @break

        @case ('secret')
            <x-form.input :name="$item->field()" :id="$item->field()" type="password"
                          autocomplete="new-password" placeholder="Değiştirmek için yazın" mono />
            @break

        @case ('integer')
            <x-form.input :name="$item->field()" :id="$item->field()" type="number"
                          :value="$value" mono />
            @break

        @default
            <x-form.input :name="$item->field()" :id="$item->field()"
                          :value="$value" :mono="$item->mono" />
    @endswitch
</x-form.field>
