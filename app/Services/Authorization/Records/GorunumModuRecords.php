<?php

declare(strict_types=1);

namespace App\Services\Authorization\Records;

/**
 * "Gelişmiş mod" yetkisinin tek kaydı.
 *
 * Yetki bir veritabanı satırına değil, panelin kendisine aittir; yetki
 * ekranı kayıt listesini buradan alır (bkz. SettingGroupRecords).
 */
class GorunumModuRecords implements PermissionRecords
{
    /** @return list<array{id: int, title: string, subtitle: string}> */
    public function records(): array
    {
        return [['id' => 1, 'title' => 'Gelişmiş moda geçebilir', 'subtitle' => 'Bütün alanlar görünür']];
    }
}
