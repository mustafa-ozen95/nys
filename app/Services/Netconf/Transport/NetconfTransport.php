<?php

declare(strict_types=1);

namespace App\Services\Netconf\Transport;

use App\Services\Netconf\NetconfException;

/**
 * NETCONF oturumunun altındaki taşıma katmanı.
 *
 * Bayt akışıdır, mesaj akışı DEĞİLDİR. Mesaj sınırını çerçeveleme belirler
 * (bkz. MessageFraming): base:1.0 bir ayırıcı dizesine kadar okur, base:1.1
 * ise uzunluk önekine güvenir. Bu yüzden burada iki ilkel vardır — "şu diziye
 * kadar" ve "tam şu kadar bayt" — ve ikisi AYNI tampondan beslenir. Ayrı
 * tamponlar kullanılsaydı biri diğerinin okuduğu baytları atlardı.
 *
 * Ayrı bir arayüz olmasının sebebi testtir: üretim sınıfı testte asla gerçek
 * bağlantı açmamalı (bkz. .ai/rules/services.md). Testler kaydedilmiş cihaz
 * yanıtlarını döndüren sahte bir taşıma kullanır.
 */
interface NetconfTransport
{
    /**
     * @throws NetconfException Bağlantı ya da kimlik doğrulama başarısızsa
     */
    public function open(string $host, int $port, string $username, string $password, int $timeout): void;

    /**
     * @throws NetconfException
     */
    public function write(string $data): void;

    /**
     * Verilen dize görünene kadar okur; dizeyi DE İÇEREN parçayı döndürür.
     *
     * @throws NetconfException Zaman aşımı ya da bağlantı koparsa
     */
    public function readUntil(string $terminator): string;

    /**
     * Tam olarak istenen kadar bayt okur.
     *
     * base:1.1 çerçevelemesi için gerekir: orada mesaj sınırı bir ayırıcı
     * dizeyle değil, parça başlığındaki UZUNLUKLA belirlenir. Veriyle
     * ayırıcıyı karıştırmamanın tek yolu uzunluğa uymaktır — parça verisi
     * "\n##\n" dizisini kendi içinde taşıyabilir.
     *
     * @throws NetconfException Zaman aşımı ya da bağlantı koparsa
     */
    public function readExactly(int $bytes): string;

    /**
     * Bağlantıyı kapatır. Zaten kapalıysa sessizce döner.
     */
    public function close(): void;
}
