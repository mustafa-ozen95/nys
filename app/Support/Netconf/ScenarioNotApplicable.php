<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use RuntimeException;

/**
 * Senaryonun ÖN KOŞULU bu cihazda yok.
 *
 * Doğrulamada iki ayrı şey birbirine karışıyordu: panelin ürettiği Junos'u
 * cihazın reddetmesi (GERÇEK bulgu) ile senaryonun cihazda olmayan bir
 * duruma ayar yazması (ARTEFAKT). İkisi de kırmızı satır olarak görünüyor
 * ve raporu okuyan kişi ayıramıyordu — örnek: ae0 tanımlı olmayan bir
 * switchte "toplama · ae ayarları" fiziksel porta yazılıp "syntax error"
 * alıyordu; panelde hata yoktu.
 *
 * Ön koşulu tutmayan senaryo artık cihaza HİÇ gitmez, gerekçesiyle atlanır
 * ve sayımda ayrı durur. Kırmızı satır kaldıysa paneldedir.
 */
final class ScenarioNotApplicable extends RuntimeException {}
