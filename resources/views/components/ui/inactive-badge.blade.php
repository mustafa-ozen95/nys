@props(['inactive' => false])

{{--
    Cihazda "deactivate" edilmiş nesne: yapılandırma duruyor ama ÇALIŞMIYOR.
    İşaret okunmasaydı panel devre dışı bir oturumu etkin gösterirdi.
--}}
@if ($inactive)
    <x-ui.badge :variant="\App\Enums\BadgeVariant::Gray"
                title="Cihazda deactivate edilmiş: yapılandırma duruyor ama çalışmıyor.">Devre dışı</x-ui.badge>
@endif
