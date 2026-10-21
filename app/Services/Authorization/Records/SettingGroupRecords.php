<?php

declare(strict_types=1);

namespace App\Services\Authorization\Records;

use App\Services\Settings\SettingsCatalog;

/**
 * Panel ayarlarının bölümleri, yetki ekranına kayıt olarak verilir.
 *
 * Kimlik config'teki "permission_id"dir ve ASLA değişmez: kullanıcı izinleri
 * o sayıya bağlı saklanıyor. Sayısı olmayan bölüm listelenmez — kimliksiz
 * bir kayda yetki verilemez, verilse bile hangi bölüme ait olduğu yarın
 * bilinemezdi.
 */
class SettingGroupRecords implements PermissionRecords
{
    public function __construct(private SettingsCatalog $catalog) {}

    /**
     * @return list<array{id: int, title: string, subtitle: ?string}>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->catalog->groups() as $group) {
            if ($group['permission_id'] === null) {
                continue;
            }

            $records[] = [
                'id' => $group['permission_id'],
                'title' => $group['label'],
                'subtitle' => $group['slug'],
            ];
        }

        return $records;
    }
}
