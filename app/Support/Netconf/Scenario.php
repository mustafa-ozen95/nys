<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use Closure;

/**
 * Bir doğrulama senaryosu ve BEKLENEN sonucu.
 *
 * Senaryoların çoğu "cihaz kabul etsin" der ve düz bir closure yeter. Bir
 * kısmı ise cihazın REDDETMESİNİ ölçer: panelin dayandığı korumanın cihazda
 * gerçekten var olduğunu kanıtlarlar (referans verilmiş bir prefix listesi
 * silinemez, arayüze bağlı bir filtre silinemez).
 *
 * Bu ikisi aynı kırmızı satıra düşünce rapor okunmaz oluyordu: beklenen bir
 * red, bulunmuş bir hata gibi görünüyordu. Şimdi ayrılırlar — ve tersi de
 * yakalanır: beklenen red GELMEZSE dayandığımız koruma cihazda yok demektir
 * ve o bir BULGUDUR.
 */
final class Scenario
{
    /**
     * @param  Closure(): mixed  $run
     * @param  string  $reason  neden reddedilmesi gerektiği; raporda görünür
     */
    private function __construct(
        public readonly Closure $run,
        public readonly string $reason,
    ) {}

    /**
     * Cihazın REDDETMESİ beklenen senaryo.
     *
     * @param  Closure(): mixed  $run
     */
    public static function expectsRejection(string $reason, Closure $run): self
    {
        return new self($run, $reason);
    }
}
