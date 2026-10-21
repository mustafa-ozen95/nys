<?php

declare(strict_types=1);

namespace App\Services\Sunucu;

use RuntimeException;

/**
 * Sunucu parolası değiştirilirken çıkan, KULLANICIYA GÖSTERİLEBİLİR hata.
 *
 * Mesajı ekrana basılır; bu yüzden içine komut çıktısı konmaz — PTY açıkken
 * yazdığımız her şey geri yankılanıyor ve o yankıda parola var.
 */
class DondurmeHatasi extends RuntimeException {}
