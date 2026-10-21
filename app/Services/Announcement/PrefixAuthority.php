<?php

declare(strict_types=1);

namespace App\Services\Announcement;

use App\Support\Net\Cidr;

/**
 * Bir prefixin anons yetkisini doğrulayan dış kaynak.
 *
 * Yeni bir kaynak eklemek: bu arayüzü uygulayan BİR sınıf ve
 * config/announcements.php içinde BİR satır. Çıkarmak: aynı iki yerden
 * silmek — çağıran taraflarda kaynak adı geçmez.
 *
 * Sorgu TOPLU yapılır: hem RIPEstat hem IRRd tek turda çok prefix
 * cevaplayabiliyor, tek tek sorulursa on prefixlik bir talep dakikalara
 * çıkar.
 */
interface PrefixAuthority
{
    /**
     * Önbellek anahtarında ve ekranda kullanılan kısa ad.
     */
    public function key(): string;

    public function label(): string;

    /**
     * @param  array<int, Cidr>  $prefixes
     * @return array<string, AuthorityVerdict> Anahtar: prefix metni
     */
    public function check(array $prefixes, string $asn): array;
}
