<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adres aralığı SAYI yerine PAKETLENMİŞ BAYT olarak saklanır.
 *
 * 128 bit IPv6 adresi PHP tamsayısına da veritabanı bigint'ine de sığmıyor;
 * bu yüzden adresleme modülü baştan sona IPv4'e kilitliydi (ip2long, max:32).
 * inet_pton çıktısı sözlük sırasında karşılaştırıldığında ağ sırasıyla aynı
 * sonucu verir, yani aynı sorgu iki aile için de çalışır.
 *
 * Karşılaştırma yalnızca AYNI AİLE içinde anlamlıdır — bir IPv4 bloğu bir
 * IPv6 bloğuyla zaten çakışamaz — ve sorgular aileyi de süzer.
 *
 * Eski tamsayı sütunları KALDIRILIR: iki kaynak bırakmak, birinin bayatlaması
 * demektir. Göç önce doldurur, sonra siler; geri alma da adresten yeniden
 * hesaplar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->binary('range_start', 16)->nullable()->after('prefix_length');
            $table->binary('range_end', 16)->nullable()->after('range_start');
        });

        $this->fill();

        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropIndex(['address_start', 'address_end']);
            $table->dropColumn(['address_start', 'address_end']);
        });

        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->index(['family', 'range_start', 'range_end']);
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropIndex(['family', 'range_start', 'range_end']);
            $table->unsignedBigInteger('address_start')->nullable()->after('prefix_length');
            $table->unsignedBigInteger('address_end')->nullable()->after('address_start');
        });

        foreach (DB::table('address_blocks')->select('id', 'range_start', 'range_end')->cursor() as $row) {
            DB::table('address_blocks')->where('id', $row->id)->update([
                'address_start' => $row->range_start === null ? null : $this->toLong($row->range_start),
                'address_end' => $row->range_end === null ? null : $this->toLong($row->range_end),
            ]);
        }

        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->index(['address_start', 'address_end']);
            $table->dropColumn(['range_start', 'range_end']);
        });
    }

    /**
     * Var olan blokların aralığını ADRESTEN yeniden hesaplar.
     *
     * Eski tamsayı sütunundan çevirmek yerine ağ adresi + maskeden üretmek
     * daha güvenli: bayat ya da eksik bir tamsayı varsa o da düzelir.
     */
    private function fill(): void
    {
        foreach (DB::table('address_blocks')->select('id', 'network', 'prefix_length')->cursor() as $row) {
            $binary = @inet_pton((string) $row->network);

            if ($binary === false) {
                continue;
            }

            DB::table('address_blocks')->where('id', $row->id)->update([
                'range_start' => $this->maskBinary($binary, (int) $row->prefix_length),
                'range_end' => $this->lastBinary($binary, (int) $row->prefix_length),
            ]);
        }
    }

    private function maskBinary(string $binary, int $prefixLength): string
    {
        $bytes = str_split($binary);
        $tam = intdiv($prefixLength, 8);
        $kalan = $prefixLength % 8;

        for ($i = 0; $i < count($bytes); $i++) {
            if ($i < $tam) {
                continue;
            }

            $bytes[$i] = $i === $tam && $kalan > 0
                ? chr(ord($bytes[$i]) & (0xFF << (8 - $kalan)) & 0xFF)
                : "\0";
        }

        return implode('', $bytes);
    }

    private function lastBinary(string $binary, int $prefixLength): string
    {
        $bytes = str_split($this->maskBinary($binary, $prefixLength));
        $hostBits = (strlen($binary) * 8) - $prefixLength;

        for ($bit = 0; $bit < $hostBits; $bit++) {
            $index = count($bytes) - 1 - intdiv($bit, 8);
            $bytes[$index] = chr(ord($bytes[$index]) | (1 << ($bit % 8)));
        }

        return implode('', $bytes);
    }

    private function toLong(string $binary): ?int
    {
        return strlen($binary) === 4 ? (int) ip2long((string) inet_ntop($binary)) : null;
    }
};
