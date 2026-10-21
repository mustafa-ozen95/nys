@props([
    'policy' => null,
    'prefix' => 'v',
    'prefixListOptions' => [],
    'communityChoices' => [],
    'asPathChoices' => [],
    'routeFilterTypeOptions' => [],
    'actionOptions' => [],
    'valueOperationOptions' => [],
    'communityOperationOptions' => [],
])

{{--
    Politikanın VARSAYILAN DAVRANIŞI: terimlerden sonra gelen from/then.

      term A { from { prefix-list-filter LISTE orlonger; } then accept; }
      then reject;

    Bu son satır bütün BGP süzgeçlerinin standart kapanışıdır. Okunmasaydı
    her güncellemede silinir ve politika "gerisini reddet"ten "gerisini de
    al"a dönerdi.
--}}
<x-ui.alert variant="info">
    Terimlerin hiçbiri eşleşmezse bu davranış uygulanır. “Reddet” seçili değilse
    politika, kalan her duyuruyu bir sonraki politikaya bırakır.
</x-ui.alert>

<x-policy.match-fields field="default_match" :prefix="$prefix.'-vm'" :match="$policy?->default_match"
                       :prefix-list-options="$prefixListOptions"
                       :as-path-choices="$asPathChoices"
                       :community-choices="$communityChoices"
                       :route-filter-type-options="$routeFilterTypeOptions" />

<x-policy.then-fields field="default_then" :prefix="$prefix.'-vt'" :then="$policy?->default_then"
                      action-name="default_then[action]"
                      :action-value="$policy?->default_then['action'] ?? null"
                      :action-options="$actionOptions"
                      :value-operation-options="$valueOperationOptions"
                      :community-operation-options="$communityOperationOptions"
                      :community-choices="$communityChoices" />
