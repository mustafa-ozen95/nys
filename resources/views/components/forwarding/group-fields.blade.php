@props(['prefix', 'group' => null])

{{--
    RIB grubu alanları.

    SIRA ANLAMLIDIR: Junos listenin ilk tablosunu birincil sayar ve o, grubun
    bağlandığı tablo olmalıdır. Bu yüzden alan serbest metin: sıralamayı
    kullanıcı belirler.
--}}
@if ($group === null)
    <x-form.field name="name" label="Grup adı" :id="$prefix.'-ad'"
                  hint="Harf, rakam, nokta, tire ve alt çizgi.">
        <x-form.input name="name" :id="$prefix.'-ad'" mono required placeholder="musteri-yollari" />
    </x-form.field>
@else
    <x-ui.facts :rows="['Grup adı' => $group->name]" />
@endif

<x-form.field name="import_ribs" label="Tablolar" :id="$prefix.'-tablolar'"
              hint="Her satıra bir tablo. İLK SATIR birincildir ve grubun bağlandığı tablo olmalıdır; örnek: inet.0, sonra musteri.inet.0.">
    <x-form.textarea name="import_ribs" :id="$prefix.'-tablolar'" rows="4" class="input--mono"
                     :value="$group === null ? '' : implode(PHP_EOL, $group->import_ribs ?? [])" />
</x-form.field>
