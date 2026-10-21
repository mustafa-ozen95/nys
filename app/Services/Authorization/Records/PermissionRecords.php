<?php

declare(strict_types=1);

namespace App\Services\Authorization\Records;

/**
 * Bir yetki kaynağının kayıtları veritabanından GELMEDİĞİNDE nereden geleceği.
 *
 * Kaynakların çoğunun kayıtları Eloquent modelidir (switchler, sunucular).
 * Panel ayarları öyle değil: bölümler config dosyasında tanımlı. Yetki
 * ekranının onları da listeleyebilmesi için kayıt listesi buradan sorulur.
 */
interface PermissionRecords
{
    /**
     * @return list<array{id: int, title: string, subtitle: ?string}>
     */
    public function records(): array;
}
