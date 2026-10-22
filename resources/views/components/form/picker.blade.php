{{--
    Seçim kutusu ile elle giriş kutusu AYNI adı taşır; her an yalnızca birinde
    "name" bulunur. JavaScript kapalıyken de form doğru değeri gönderir.
--}}
@if (! $hasOptions() && ! $manual)
    <p class="text-sm text-muted">{{ $emptyMessage() }}</p>
@else
    <div class="picker" data-selection>
        @if ($hasOptions())
            <x-form.select :name="$manualActive ? null : $name" :id="$id ?? $name"
                           :options="$choices()" :selected="$selected()"
                           class="mono" data-selection-list="{{ $name }}" />
        @endif

        <input type="text"
               class="input input--mono"
               @if ($manualOpen()) name="{{ $name }}" @endif
               id="{{ $manualId() }}"
               value="{{ $manualValue() }}"
               placeholder="{{ $placeholder }}"
               data-selection-manual
               aria-label="{{ $manualAria }}"
               aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
               @unless ($manualOpen()) hidden @endunless>
    </div>
@endif
