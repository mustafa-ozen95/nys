<?php

declare(strict_types=1);

namespace App\Services\Netconf\Transport;

use App\Services\Netconf\NetconfException;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * NETCONF'u SSH subsystem'i olarak açar (RFC 6242, bölüm 3).
 *
 * phpseclib3 kullanılır; paket zaten kuruludur ve parola süreç listesinde
 * görünmez — sshpass ile komut satırına yazmaktan bu yüzden daha iyidir.
 *
 * Bir örnek yalnızca bir oturum taşır: cihazda her oturum bir mgd süreci
 * fork'lar, açık bırakılmamalıdır.
 *
 * TAMPON BURADADIR, phpseclib'de değil. phpseclib'in kendi read($ayırıcı)
 * yöntemi yalnızca "şu diziye kadar" okuyabiliyor; base:1.1 çerçevelemesi ise
 * uzunluk önekine uymak zorunda. İki okuma biçimi aynı baytları paylaştığı
 * için tampon TEK olmalı: phpseclib'in interactiveBuffer'ı ile bizim
 * tamponumuz karışsaydı biri diğerinin baytlarını yutardı. Bu yüzden
 * phpseclib'den yalnızca "sıradaki kanal paketi" istenir (READ_NEXT) ve
 * birleştirme burada yapılır.
 *
 * phpseclib istisnaları BURADA çevrilir. TimeoutException ve
 * ConnectionClosedException \RuntimeException'dan türer, NetconfException'dan
 * değil; çevrilmezlerse kod tabanındaki hiçbir catch (NetconfException) onları
 * yakalamaz ve kullanıcı hata mesajı yerine 500 sayfası görür, kuyruk işi de
 * anlamlı kaydını yazamadan düşer.
 */
final class SshSubsystemTransport implements NetconfTransport
{
    private ?SSH2 $ssh = null;

    private string $buffer = '';

    public function open(string $host, int $port, string $username, string $password, int $timeout): void
    {
        try {
            $ssh = new SSH2($host, $port, $timeout);
            $ssh->setTimeout($timeout);

            if (! $ssh->login($username, $password)) {
                throw NetconfException::transport(
                    "{$host} NETCONF kimlik doğrulaması başarısız.",
                    'auth',
                )->withStage('connect');
            }

            if (! $ssh->startSubsystem('netconf')) {
                throw NetconfException::transport(
                    "{$host} üzerinde netconf subsystem'i açılamadı.",
                    'closed',
                )->withStage('connect');
            }
        } catch (NetconfException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw NetconfException::transport(
                "{$host} NETCONF bağlantısı kurulamadı: {$exception->getMessage()}",
                'closed',
                $exception,
            )->withStage('connect');
        }

        $this->ssh = $ssh;
        $this->buffer = '';
    }

    public function write(string $data): void
    {
        $ssh = $this->connection();

        try {
            $ssh->write($data);
        } catch (Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    public function readUntil(string $terminator): string
    {
        while (($position = strpos($this->buffer, $terminator)) === false) {
            $this->fill();
        }

        return $this->take($position + strlen($terminator));
    }

    public function readExactly(int $bytes): string
    {
        if ($bytes < 0) {
            throw new NetconfException("Geçersiz okuma uzunluğu: {$bytes}");
        }

        while (strlen($this->buffer) < $bytes) {
            $this->fill();
        }

        return $this->take($bytes);
    }

    public function close(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
        $this->buffer = '';
    }

    /**
     * Tampondan baştan bu kadar bayt alır ve tampondan düşer.
     */
    private function take(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }

    /**
     * Tampona en az bir kanal paketi daha ekler.
     *
     * Kanal kapandığında phpseclib true döndürür. O noktada tampon bir daha
     * büyümez; beklemeye devam etmek sonsuz döngü olurdu.
     *
     * @throws NetconfException
     */
    private function fill(): void
    {
        $ssh = $this->connection();

        try {
            $packet = $ssh->read('', SSH2::READ_NEXT);
        } catch (Throwable $exception) {
            throw $this->translate($exception);
        }

        if (! is_string($packet) || $packet === '') {
            throw NetconfException::transport(
                'NETCONF bağlantısı yanıt tamamlanmadan kapandı.',
                'closed',
            )->withStage('read');
        }

        $this->buffer .= $packet;
    }

    private function translate(Throwable $exception): NetconfException
    {
        if ($this->ssh?->isTimeout() === true) {
            return NetconfException::transport(
                'NETCONF yanıtı zaman aşımına uğradı.',
                'timeout',
                $exception,
            )->withStage('read');
        }

        /*
         * Cihaz oturumu DÜŞÜRDÜYSE sonraki her istek SSH katmanında patlar
         * ve phpseclib'in iç dili ("Unable to request pty", "Connection
         * closed by server") operatörün önüne çıkardı. Junos'un oturumu
         * düşürdüğü ölçüldü: geçersiz bir öznitelik değeri
         * (get-configuration database="olmayan") oturumu anında kapatıyor.
         *
         * Bu durumda söylenecek şey iç hata değil, NE YAPILACAĞIDIR: oturum
         * gitti, yeni bir oturum gerekiyor. Panel zaten her işlem için yeni
         * oturum açar, yani sonraki işlem çalışır.
         */
        if ($this->sessionIsGone($exception)) {
            return NetconfException::transport(
                'Cihaz NETCONF oturumunu düşürdü; işlem yarıda kaldı. '
                .'Yeni bir oturumla tekrar denenebilir.',
                'closed',
                $exception,
            )->withStage('read');
        }

        return NetconfException::transport(
            "NETCONF bağlantısı koptu: {$exception->getMessage()}",
            'closed',
            $exception,
        )->withStage('read');
    }

    /**
     * Hata, oturumun karşı taraftan kapatılmış olmasından mı geliyor?
     */
    private function sessionIsGone(Throwable $exception): bool
    {
        $isaretler = ['request pty', 'channel', 'Connection closed', 'not connected', 'disconnected'];
        $mesaj = $exception->getMessage();

        foreach ($isaretler as $isaret) {
            if (stripos($mesaj, $isaret) !== false) {
                return true;
            }
        }

        return false;
    }

    private function connection(): SSH2
    {
        if ($this->ssh === null) {
            throw new NetconfException('NETCONF oturumu açık değil.');
        }

        return $this->ssh;
    }
}
