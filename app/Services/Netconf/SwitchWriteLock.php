<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Bir switche aynı anda tek yazma.
 *
 * Bu kilit bir hız iyileştirmesi değil, GÜVENLİK ÖNLEMİDİR. Junos'ta bekleyen
 * bir "commit confirmed" varken BAŞKA BİRİNİN attığı commit, bekleyeni de
 * onaylar. Kilit olmadan şu olur:
 *
 *   A kullanıcısı geri alınabilir commit atar ve onay için ikinci oturumu
 *   açmadan önce B kullanıcısı kendi değişikliğini commit eder. B'nin
 *   commit'i A'nın değişikliğini de kalıcılaştırır. A'nın değişikliği yönetim
 *   erişimini bozuyorsa cihazın kendini geri döndürme mekanizması TAM O ANDA
 *   devre dışı kalır — güvenlik ağımız delinir.
 *
 * Ters yönü de aynı derecede kötüdür: A'nın onay adımı, B'nin o sırada
 * yüklediği ve henüz doğrulanmamış değişikliği onaylayabilir.
 *
 * Bu yüzden kilit yükleme, commit ve ONAYIN tamamını kapsar; onay bitmeden
 * bırakılmaz.
 *
 * Eşitleme işleri de aynı kilide bakar ama beklemez: yazma sürerken cihazı
 * okumak yarım bir yapılandırmayı envantere yazmak demektir.
 */
class SwitchWriteLock
{
    /** Kilit için beklenecek en fazla süre (saniye). */
    private const WAIT = 5;

    /**
     * Kilidi alır ve işi çalıştırır. Süresinde alınamazsa iş HİÇ çalışmaz.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws NetconfException
     */
    public function run(NetworkSwitch $switch, Closure $callback): mixed
    {
        return $this->runFor($this->key($switch), (string) $switch->hostname, $callback);
    }

    /**
     * Envanterde olmayan hedefler için: kilit anahtarı doğrudan verilir.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws NetconfException
     */
    public function runFor(string $key, string $label, Closure $callback): mixed
    {
        try {
            return Cache::lock($key, $this->seconds())->block(self::WAIT, $callback);
        } catch (LockTimeoutException) {
            throw NetconfException::transport(
                "{$label} üzerinde başka bir değişiklik sürüyor. Birkaç saniye sonra tekrar deneyin.",
                'lock',
            )->withStage('lock');
        }
    }

    /**
     * Kilidi almayı dener, alamazsa işi atlar ve null döner.
     *
     * Okuma yolları içindir: bekleyip yarım durumu okumaktansa o turu
     * atlamak doğrudur, bir sonraki tur zaten gelecek.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|null
     */
    public function attempt(NetworkSwitch $switch, Closure $callback): mixed
    {
        $lock = Cache::lock($this->key($switch), $this->seconds());

        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Kilidin en fazla ne kadar tutulacağı.
     *
     * Geri alınabilir commit penceresi kadar sürebilir; süreç ortadan
     * kaybolursa kilit sonsuza kadar kalmasın diye üst sınır konur.
     */
    private function seconds(): int
    {
        return (int) config('netconf.confirm_minutes') * 60 + 60;
    }

    /**
     * Anahtar biçimi NetconfTarget::lockKey() ile aynıdır; ikisi ayrışırsa
     * aynı switch iki farklı kilit altında görünür ve kilit hiçbir şeyi
     * korumaz. Bu yüzden buradan da o üretilir.
     */
    private function key(NetworkSwitch $switch): string
    {
        return NetconfTarget::fromSwitch($switch)->lockKey();
    }
}
