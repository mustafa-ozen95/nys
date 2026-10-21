@props(['variant' => 'info'])

<div {{ $attributes->class(['alert', 'alert--'.$variant]) }} role="alert">
    <div>{{ $slot }}</div>
</div>
