<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adres bloğu VLAN'ı ETİKETİYLE değil KAYDIYLA işaret eder.
 *
 * Etiketle işaret etmenin üç sonucu vardı:
 *
 *  - Etiketsiz VLAN hedef olamıyordu. Junos "vlans <ad>" satırını vlan-id
 *    olmadan kabul ediyor ve böyle bir VLAN'ın üstünde de adres durabilir.
 *  - Cihazda VLAN'ın etiketi değişirse bağ sessizce BAŞKA bir VLAN'a kayardı;
 *    etiket tekil ama kalıcı değil.
 *  - VLAN silinip aynı etiketle yenisi açılırsa blok yeni VLAN'a bağlanmış
 *    görünürdü.
 *
 * Cihazda VLAN'ın kimliği ADIDIR; panelde de kaydın kendisi olmalı.
 *
 * Çözülemeyen satır varsa göç DURUR: hangi VLAN'ın kastedildiğine panel karar
 * veremez, cihazdan yeniden okunmalıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->foreignId('vlan_id')->nullable()->after('connection_type')
                ->constrained('vlans')->nullOnDelete();
        });

        $this->backfill();

        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropColumn('vlan_tag');
        });
    }

    public function down(): void
    {
        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('vlan_tag')->nullable()->after('connection_type');
        });

        DB::table('address_blocks as b')
            ->join('vlans as v', 'v.id', '=', 'b.vlan_id')
            ->update(['b.vlan_tag' => DB::raw('v.vlan_tag')]);

        Schema::table('address_blocks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vlan_id');
        });
    }

    /**
     * Etiketi, bloğun bağlı olduğu switchteki VLAN kaydına çevirir.
     *
     * Hedef switch erişim switchidir; yoksa omurga. Atama zaten o switchin
     * üstünde yapılıyor.
     */
    private function backfill(): void
    {
        $cozulemeyen = [];

        DB::table('address_blocks')
            ->whereNotNull('vlan_tag')
            ->orderBy('id')
            ->each(function (object $block) use (&$cozulemeyen): void {
                $switchId = $block->access_switch_id ?? $block->core_switch_id;

                $vlanId = $switchId === null ? null : DB::table('vlans')
                    ->where('switch_id', $switchId)
                    ->where('vlan_tag', $block->vlan_tag)
                    ->value('id');

                if ($vlanId === null) {
                    $cozulemeyen[] = "{$block->network}/{$block->prefix_length} → VLAN {$block->vlan_tag}";

                    return;
                }

                DB::table('address_blocks')->where('id', $block->id)->update(['vlan_id' => $vlanId]);
            });

        if ($cozulemeyen !== []) {
            throw new RuntimeException(
                'Bu blokların VLAN etiketi envanterde bulunamadı; göç durduruldu. '
                .'Önce switchlerin VLAN listesi cihazdan eşitlenmeli — '.implode(', ', $cozulemeyen)
            );
        }
    }
};
