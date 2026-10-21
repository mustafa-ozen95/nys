<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PANELİN YÖNETİMİ YALNIZCA YÖNETİCİDE.
 *
 * Asıl kural KODDA duruyor (UserPermissionResolver); bu göç veriyi
 * onunla hizalar — ekranda "var" görünüp hiçbir işe yaramayan yetki kalmasın.
 *
 * Yönetim kaynakları config'ten okunur (permissions.yonetim_kaynaklari):
 * ayarlar, kullanıcılar, etkinlik kaydı. Cihaz ekranları (switchler,
 * sunucular, adresleme, BGP, prefix anonsu) DOKUNULMAZ — onlar işin kendisi.
 *
 * YETKİ DARALTILIYOR; göç kimseye yeni bir kapı açmıyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kaynaklar = (array) config('permissions.yonetim_kaynaklari', ['settings', 'users', 'logs']);

        foreach (DB::table('roles')->where('is_admin', false)->get() as $rol) {
            $sablon = json_decode((string) $rol->template, true) ?: [];
            $kalan = array_diff_key($sablon, array_flip($kaynaklar));

            if ($kalan === $sablon) {
                continue;
            }

            DB::table('roles')->where('id', $rol->id)->update([
                'template' => json_encode($kalan, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_permissions')->whereIn('resource', $kaynaklar)->delete();
    }

    /**
     * Geri alırken YETKİ GERİ VERİLMEZ.
     *
     * Geri alma bir kurtarma işlemidir; kimseye yetki kazandırmamalı.
     */
    public function down(): void
    {
        // Kasıtlı olarak boş.
    }
};
