<?php

declare(strict_types=1);

namespace App\Services\Announcement\Transport;

/**
 * IRR kayıt sunucusuna (whois, 43) toplu sorgu.
 *
 * Ayrı bir arayüz olmasının sebebi testtir: üretim sınıfı testte asla gerçek
 * bağlantı açmamalı (bkz. .ai/rules/services.md).
 */
interface IrrdTransport
{
    /**
     * Verilen prefixlerin RPSL nesnelerini TEK bağlantıda getirir.
     *
     * @param  array<int, string>  $prefixes
     * @return array<string, ?string> Kayıt yoksa null
     *
     * @throws IrrdException
     */
    public function routeObjects(array $prefixes): array;
}
