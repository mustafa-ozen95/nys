<?php

declare(strict_types=1);

namespace App\Services\Snmp;

use App\Models\NetworkSwitch;

/**
 * Cihazdan SNMP ile OKUMA.
 *
 * PHP'nin snmp eklentisi kurulu olmadığı için NET-SNMP komutu çalıştırılır.
 * Community komut satırına YAZILMAZ: geçici bir snmp.conf dosyasına konur ve
 * SNMPCONFPATH ile gösterilir, böylece süreç listesinde (ps) görünmez —
 * SSH parolasında yapılanla aynı yaklaşım.
 *
 * Yalnızca walk vardır; SET yoktur ve eklenmemelidir.
 */
class SnmpClient
{
    /**
     * Bir OID alt ağacını okur.
     *
     * @return array<string, string> OID sonu (indeks) => değer
     *
     * @throws SnmpException
     */
    public function walk(NetworkSwitch $switch, string $oid): array
    {
        $credential = $switch->snmpCredential;

        if ($credential?->community === null) {
            throw new SnmpException("{$switch->hostname} için SNMP community tanımlı değil.");
        }

        $directory = $this->writeConfig($credential->community);

        try {
            $output = $this->run($switch, $credential->port ?? 161, $oid, $directory);
        } finally {
            $this->removeConfig($directory);
        }

        return $this->parse($output, $oid);
    }

    /**
     * Community'yi yalnızca uygulama kullanıcısının okuyabileceği geçici bir
     * dosyaya yazar.
     */
    private function writeConfig(string $community): string
    {
        $directory = storage_path('app/snmp/'.bin2hex(random_bytes(8)));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new SnmpException('SNMP yapılandırma dizini oluşturulamadı.');
        }

        $file = $directory.'/snmp.conf';

        file_put_contents($file, "defversion 2c\ndefcommunity {$community}\n");
        chmod($file, 0600);

        return $directory;
    }

    private function removeConfig(string $directory): void
    {
        @unlink($directory.'/snmp.conf');
        @rmdir($directory);
    }

    private function run(NetworkSwitch $switch, int $port, string $oid, string $directory): string
    {
        $process = proc_open(
            [
                (string) config('snmp.binary'),
                // MIB yüklenmez: çıktı sayısaldır, MIB'e gerek yok ve
                // SNMPCONFPATH'i değiştirmek MIB aramasını bozuyor.
                '-m', '',
                /*
                 * q: tip ve tırnak yok, n: sayısal OID,
                 * t: timetick'ler ham sayı olarak (aksi halde
                 * "416:9:45:59.92" gibi biçimlenir ve ayrıştırılamaz).
                 */
                '-Oqnt',
                '-t', (string) config('snmp.timeout'),
                '-r', (string) config('snmp.retries'),
                "{$switch->management_ip}:{$port}",
                $oid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['SNMPCONFPATH' => '/etc/snmp:'.$directory],
        );

        if (! is_resource($process)) {
            throw new SnmpException('SNMP süreci başlatılamadı.');
        }

        $output = stream_get_contents($pipes[1]) ?: '';
        $error = stream_get_contents($pipes[2]) ?: '';

        array_map('fclose', $pipes);
        $status = proc_close($process);

        if ($status !== 0) {
            throw new SnmpException(trim($error) ?: "SNMP okuma başarısız: {$switch->hostname}");
        }

        return $output;
    }

    /**
     * "-Oqn" çıktısı: ".1.3.6.1... değer" satırları.
     *
     * @return array<string, string>
     */
    private function parse(string $output, string $oid): array
    {
        $prefix = '.'.ltrim($oid, '.').'.';
        $rows = [];
        $limit = (int) config('snmp.max_rows');

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, $prefix)) {
                continue;
            }

            [$fullOid, $value] = array_pad(explode(' ', $line, 2), 2, '');

            $rows[substr($fullOid, strlen($prefix))] = trim($value, " \t\"");

            if (count($rows) > $limit) {
                throw new SnmpException("SNMP tablosu beklenenden büyük ({$oid}); okuma durduruldu.");
            }
        }

        return $rows;
    }
}
