<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Services\Announcement\Transport\IrrdException;
use App\Services\Announcement\Transport\IrrdResponse;
use App\Services\Announcement\Transport\WhoisIrrdTransport;
use RuntimeException;
use Tests\TestCase;

/**
 * IRR sorgusunun kendisi: çerçeveleme ve soket davranışı.
 *
 * ÜRETİM sınıfı sınanır, sahtesi değil — ama 127.0.0.1'deki tek bağlantılık
 * bir sunucuya karşı; dışarıya hiç çıkılmaz (bkz. .ai/rules/services.md).
 */
final class IrrQueryTest extends TestCase
{
    /**
     * Sahte sunucuyu başlatır, taşımayı ona yöneltir ve sunucunun okuduğu
     * ham komutları geri verir.
     *
     * @param  array<int, string>  $prefixes
     * @return array{objects: array<string, ?string>, request: string}
     */
    private function ask(string $response, array $prefixes): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../Support/irrd/server.php', base64_encode($response)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Sahte IRR sunucusu başlatılamadı.');
        }

        try {
            $port = (int) trim((string) fgets($pipes[1]));

            $this->assertGreaterThan(0, $port, 'Sahte sunucu port bildirmedi.');

            config([
                'announcements.authorization.irr.host' => '127.0.0.1',
                'announcements.authorization.irr.port' => $port,
                'announcements.authorization.irr.timeout' => 5,
            ]);

            $objects = (new WhoisIrrdTransport)->routeObjects($prefixes);

            return ['objects' => $objects, 'request' => (string) base64_decode(trim((string) fgets($pipes[1])), true)];
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /**
     * Toplu kip: TEK bağlantıda çok sorgu, sonunda `!q`.
     *
     * Ve sorgu `!r` iledir — TAM EŞLEŞME. Kapsayan blokları da getiren bir
     * sorgu kullanılsaydı, 192.0.2.0/24 için AS64498'in 192.0.0.0/21
     * nesnesi dönerdi ve başkasının toplayıcı kaydı bizim yetkimiz sayılırdı.
     */
    public function test_toplu_kipte_tam_eslesme_sorulur(): void
    {
        /*
         * Bildirilen uzunluk gövdenin KENDİ satır sonunu da kapsar: gerçek
         * sunucu "A16\nAS64496 AS64496\nC\n" döndürüyor, 16 bayt son
         * satır sonuyla birlikte sayılıyor.
         */
        $result = $this->ask(
            "A7\nroute:\nC\nD\n",
            ['192.0.2.0/24', '203.0.113.0/24'],
        );

        $this->assertSame(
            "!!\n!r192.0.2.0/24\n!r203.0.113.0/24\n!q\n",
            $result['request'],
        );

        $this->assertSame("route:\n", $result['objects']['192.0.2.0/24']);
        $this->assertNull($result['objects']['203.0.113.0/24']);
    }

    /**
     * Uzunluk önekine uyulur, ayırıcı aranmaz.
     *
     * RPSL gövdesi "C" ile başlayan satırlar taşır (`country:`, `changed:`);
     * satır arayan bir okuyucu nesneyi ortasından keserdi.
     */
    public function test_govdedeki_c_satiri_nesneyi_bolmez(): void
    {
        $object = "route:          192.0.2.0/24\nchanged:        biri@ornek.test\ncountry:        TR\norigin:         AS64496\nsource:         RIPE\n";

        $objects = IrrdResponse::split(
            'A'.strlen($object)."\n".$object."C\n",
            ['192.0.2.0/24'],
        );

        $this->assertSame($object, $objects['192.0.2.0/24']);
    }

    public function test_yarim_yanit_sessizce_bos_donmez(): void
    {
        $this->expectException(IrrdException::class);

        IrrdResponse::split("A400\nroute:\n", ['192.0.2.0/24']);
    }

    public function test_sunucu_hatasi_kayit_yokluguna_donusmez(): void
    {
        $this->expectException(IrrdException::class);

        IrrdResponse::split("F unknown command\n", ['192.0.2.0/24']);
    }

    public function test_ulasilamayan_sunucu_istisna_verir(): void
    {
        config([
            // 1. port ayrıcalıklıdır ve dinleyen bir servis yoktur.
            'announcements.authorization.irr.host' => '127.0.0.1',
            'announcements.authorization.irr.port' => 1,
            'announcements.authorization.irr.timeout' => 2,
        ]);

        $this->expectException(IrrdException::class);

        (new WhoisIrrdTransport)->routeObjects(['192.0.2.0/24']);
    }
}
