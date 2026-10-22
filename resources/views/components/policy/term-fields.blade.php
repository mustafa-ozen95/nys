@props([
    'term' => null,
    'positionOptions' => [],
    'prefix' => 'yeni',
    'prefixListOptions' => [],
    'communityOptions' => [],
    'communityChoices' => [],
    'asPathChoices' => [],
    'matchTypeOptions' => [],
    'routeFilterTypeOptions' => [],
    'actionOptions' => [],
    'valueOperationOptions' => [],
    'communityOperationOptions' => [],
])

<x-form.field name="name" label="Terim adı" :id="$prefix.'-term'"
              hint="Politika içinde terimin kimliğidir.">
    <x-form.input name="name" :id="$prefix.'-term'" :value="$term?->name" mono required placeholder="A" />
</x-form.field>

{{--
    Sıra politikanın kendisidir: Junos'ta ilk eşleşen terim kazanır ve
    kendinden sonrakiler hiç çalışmaz.
--}}
@if ($positionOptions !== [])
    <x-form.field rule="switch_policies.terim:position" name="position" label="Sıra" :id="$prefix.'-order'"
                  hint="Bu terim kaçıncı sırada değerlendirilsin? İlk eşleşen kazanır.">
        <x-form.select name="position" :id="$prefix.'-order'" :options="$positionOptions"
                       :selected="$term?->position" />
    </x-form.field>
@endif

@if ($prefixListOptions === [])
    <x-ui.alert variant="info">
        Bu switchte prefix list yok. Terim yine de yazılabilir; koşulsuz terim
        her şeye uyar ve genellikle zinciri kapatan son terimdir.
    </x-ui.alert>
@else
    <x-form.field rule="switch_policies.terim:match_prefix_lists" name="match_prefix_lists" label="Eşleşecek prefix listeler (filtre)"
                  hint="from prefix-list-filter. Hiçbiri seçilmezse bu koşul yazılmaz.">
        @foreach ($prefixListOptions as $list)
            <label class="checkbox">
                <input type="checkbox" name="match_prefix_lists[]" value="{{ $list }}"
                       @checked(in_array($list, $term?->match_prefix_lists ?? [], true))>
                <span class="mono">{{ $list }}</span>
            </label>
        @endforeach
    </x-form.field>

    {{--
        Eşleşme türü Junos'ta ZORUNLUDUR: türsüz filtre "syntax error,
        expecting <flag-value>" ile reddedilir.
    --}}
    <x-form.field rule="switch_policies.terim:match_type" name="match_type" label="Eşleşme türü" :id="$prefix.'-type'"
                  hint="Prefix list seçildiyse zorunludur.">
        <x-form.select name="match_type" :id="$prefix.'-type'" :options="$matchTypeOptions"
                       :selected="$term?->match_type?->value ?? 'orlonger'" />
    </x-form.field>
@endif

{{-- Koşulların geri kalanı: route-filter, as-path, community, protokol… --}}
<x-policy.match-fields field="match" :prefix="$prefix.'-m'" :match="$term?->match_conditions"
                       :prefix-list-options="$prefixListOptions"
                       :as-path-choices="$asPathChoices"
                       :community-choices="$communityChoices"
                       :route-filter-type-options="$routeFilterTypeOptions" />

<x-form.field rule="switch_policies.terim:community_delete" name="community_delete" label="Silinecek community" :id="$prefix.'-community'"
              hint="Kısayol: “then community delete”. Aynısı aşağıdaki community işlemlerinden de yapılabilir.">
    <x-form.select name="community_delete" :id="$prefix.'-community'" :options="$communityOptions"
                   :selected="$term?->community_delete" />
</x-form.field>

{{-- Eylemler: sonuç + BGP nitelikleri + community işlemleri. --}}
<x-policy.then-fields field="then" :prefix="$prefix.'-t'" :then="$term?->then_actions"
                      action-name="action" :action-value="$term?->action?->value"
                      :action-options="$actionOptions"
                      :value-operation-options="$valueOperationOptions"
                      :community-operation-options="$communityOperationOptions"
                      :community-choices="$communityChoices" />

{{--
    Panelin göstermediği ama KORUNAN satırlar. Kullanıcı neyin taşındığını
    bilmeli: kaydettiğinde bunlar cihazda olduğu gibi kalır.
--}}
@if ($term?->preserved_lines)
    <x-ui.alert variant="info">
        Bu terimde panelin göstermediği {{ count($term->preserved_lines) }} satır var
        ({{ implode(', ', $term->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
