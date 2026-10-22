<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;

/**
 * "get-configuration" süzgecini LEHÇEYE göre kurar.
 *
 * Junos, istenmeyen bir kökü sessizce boş döndürmez: o kök bu ailede YOKSA
 * bütün isteği reddeder ("syntax error, expecting </configuration>"). Tek bir
 * fazla kök yüzünden eşitlemenin tamamı düşer.
 *
 * Örnek: ELS bir cihazdan ELS öncesi "ethernet-switching-options" kökü
 * istenirse eşitleme hiç tamamlanmaz — ekranda eski veri durur, hata
 * yalnızca cihaz işlem kaydında görünür.
 *
 * Kural: okunacak kökler DEĞİŞMEZ bir liste değildir; lehçeye sorulur.
 */
final class ConfigurationFilter
{
    /**
     * @param  list<string>  $roots  istenen kökler, sırayla
     */
    public static function build(?NetworkSwitch $switch, array $roots): string
    {
        $none = ($switch?->schema_profile ?? JunosSchemaProfile::PreEls)
            ->dialect()
            ->absentConfigurationRoots();

        $remaining = array_values(array_diff($roots, $none));

        return '<configuration>'
            .implode('', array_map(static fn (string $root): string => "<{$root}/>", $remaining))
            .'</configuration>';
    }
}
