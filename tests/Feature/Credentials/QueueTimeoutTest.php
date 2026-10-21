<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Jobs\RotateSwitchPasswordJob;
use Tests\TestCase;

/**
 * PAROLA TURU İŞÇİ ZAMANAŞIMINA SIĞMAZ.
 *
 * Kuyruğun işçileri eşitleme işlerine göre ölçülmüş 90 saniyelik bir
 * zamanaşımıyla koşuyor. Parola turu ise cihazda ÜÇ geri alınabilir yazma
 * yapıyor — her biri "commit confirmed" artı yeni bir oturumda onay commit'i,
 * yani altı commit ve dokuz NETCONF oturumu.
 *
 * Yavaş modellerde (ör. EX3200, EX2200, EX2300) tur 90 saniyeyi aşabilir ve
 * işçi tarafından KESİLİR. Süreç SSH akışının ortasında öldüğü için hata
 * ekranda
 *
 *     NETCONF bağlantısı koptu: Invalid packet length
 *
 * diye görünür ve cihazın ya da ağın suçu sanılır; değildir. Asıl bedel,
 * kesilen turda kurtarma hesabının cihazda AÇIK kalmasıdır.
 *
 * İki değer birbirine bağlıdır ve ayrı dosyalarda durur; ayrışmasınlar:
 *
 *  - işin zamanaşımı, işçinin varsayılanından büyük olmalı,
 *  - kuyruğun retry_after'ı işin zamanaşımından büyük olmalı — küçük olsaydı
 *    kuyruk turu daha bitmeden ikinci kez dağıtırdı.
 */
final class QueueTimeoutTest extends TestCase
{
    public function test_tur_isinin_kendi_zamanasimi_vardir(): void
    {
        $is = new RotateSwitchPasswordJob(1);

        $this->assertSame((int) config('credentials.rotation.job_timeout'), $is->timeout);

        $this->assertGreaterThan(
            (int) config('sync.job_timeout'),
            $is->timeout,
            'Parola turu, eşitleme işlerine göre ölçülmüş işçi zamanaşımına sığmaz.'
        );
    }

    public function test_tekillik_kilidi_is_koşarken_dusmez(): void
    {
        $is = new RotateSwitchPasswordJob(1);

        $this->assertGreaterThan(
            $is->timeout,
            $is->uniqueFor,
            'Kilit iş hâlâ koşarken düşerse aynı cihaza ikinci bir tur girer.'
        );
    }

    public function test_kuyruk_turu_bitmeden_yeniden_dagitmaz(): void
    {
        $this->assertGreaterThan(
            (int) config('credentials.rotation.job_timeout'),
            (int) config('queue.connections.redis.retry_after'),
            'retry_after tur zamanaşımından küçük: kuyruk aynı turu ikinci kez dağıtır.'
        );
    }
}
