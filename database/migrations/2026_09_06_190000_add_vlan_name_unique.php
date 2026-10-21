<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VLAN adı bir switchte TEKİLDİR.
 *
 * Ad cihazda VLAN'ın kimliğidir. Panel yalnızca etiketi tekil tutuyordu: var
 * olan bir adla "VLAN ekle" demek, merge yüzünden cihazdaki VLAN'ı yeniden
 * etiketliyor, açıklamasını ve l3-interface'ini siliyor ve bütün üye
 * portlarını yeni etikete taşıyordu.
 *
 * Kısıt eklenmeden önce çift kayıt varsa göç durur: hangi kaydın doğru
 * olduğuna panel karar veremez, cihazdan yeniden okunmalıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cift = DB::table('vlans')
            ->select('switch_id', 'name')
            ->groupBy('switch_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($cift->isNotEmpty()) {
            $liste = $cift->map(fn ($row): string => "switch {$row->switch_id}: {$row->name}")->implode(', ');

            throw new RuntimeException(
                'Aynı switchte aynı adla birden çok VLAN var; kısıt eklenemez. '
                ."Önce bunlar temizlenmeli — {$liste}"
            );
        }

        Schema::table('vlans', function (Blueprint $table): void {
            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('vlans', function (Blueprint $table): void {
            $table->dropUnique(['switch_id', 'name']);
        });
    }
};
