<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Netconf\ResidualXml;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Panelde gösterilmeyen ama KORUNAN cihaz satırları.
 *
 * Panel bir nesneyi güncellerken silip yeniden yazıyor (terim sırası anlamlı
 * olduğu için); modellemediği her satır o sırada kaybolurdu. Bu yüzden
 * modellenmeyen çocuk düğümler ham XML olarak saklanır ve geri yazılırken
 * aynen basılır.
 *
 * Kullanıcı neyin taşındığını bilmeli: ekranda "salt okunur" rozeti yerine
 * "N korunan satır" yazar.
 */
trait PreservesDeviceLines
{
    /**
     * Ham XML parçalarının bulunduğu kaynaklar. Bir nesnenin artığı birden
     * çok yerde durabilir: düğümün kendi çocukları, from'un içi, then'in içi.
     *
     * @return list<list<string>|null>
     */
    abstract protected function residualSources(): array;

    /**
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(function (): array {
            $names = [];

            foreach ($this->residualSources() as $source) {
                $names = [...$names, ...ResidualXml::names($source)];
            }

            return array_values(array_unique($names));
        });
    }
}
