<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;

/**
 * Kaydedilmiş cihaz yanıtlarını döndüren sahte taşıma.
 *
 * Üretim sınıfı testte asla gerçek bağlantı açmaz (bkz. .ai/rules/services.md);
 * bütün NETCONF testleri bunu kullanır.
 *
 * Gerçek taşıma gibi BAYT AKIŞIDIR: okuma tampondan yapılır, tampon bittiğinde
 * sıradaki yanıt tampona alınır. Mesaj sınırı çerçevelemenin işidir — bu
 * sayede base:1.1 parçalı çerçeveleme de sınanabiliyor.
 *
 * Tampon dolu ama aranan ayırıcı yoksa tampon olduğu gibi verilir: kayıtlı
 * yanıtların bir kısmı ayırıcı taşımıyor ve iki yanıt tek mesaja karışmamalı.
 */
final class FakeNetconfTransport implements NetconfTransport
{
    /** @var list<string> Sırayla tampona alınacak yanıtlar */
    private array $responses;

    private string $buffer = '';

    /** @var list<string> Cihaza yazılan ham veriler */
    public array $written = [];

    public bool $isOpen = false;

    public int $closeCount = 0;

    /** @var array{host: string, port: int, username: string, password: string, timeout: int}|null */
    public ?array $connection = null;

    /**
     * @param  list<string>  $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function open(string $host, int $port, string $username, string $password, int $timeout): void
    {
        $this->connection = compact('host', 'port', 'username', 'password', 'timeout');
        $this->isOpen = true;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function readUntil(string $terminator): string
    {
        $this->fill();

        $position = strpos($this->buffer, $terminator);

        return $position === false
            ? $this->take(strlen($this->buffer))
            : $this->take($position + strlen($terminator));
    }

    public function readExactly(int $bytes): string
    {
        while (strlen($this->buffer) < $bytes) {
            $before = strlen($this->buffer);
            $this->fill();

            if (strlen($this->buffer) === $before) {
                throw new NetconfException('Sahte taşımada yanıt kalmadı.');
            }
        }

        return $this->take($bytes);
    }

    public function close(): void
    {
        $this->closeCount++;
        $this->isOpen = false;
        $this->buffer = '';
    }

    private function fill(): void
    {
        if ($this->buffer !== '') {
            return;
        }

        if ($this->responses === []) {
            throw new NetconfException('Sahte taşımada yanıt kalmadı.');
        }

        $this->buffer = array_shift($this->responses);
    }

    private function take(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }
}
