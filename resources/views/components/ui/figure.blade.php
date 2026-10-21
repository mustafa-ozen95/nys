@props(['value', 'label', 'tone' => null])

<div {{ $attributes }}>
    <div @class(['figure__value', 'figure__value--'.$tone => $tone])>{{ $value }}</div>
    <div class="figure__label">{{ $label }}</div>
</div>
