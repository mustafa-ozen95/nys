<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Yerleşik network rolünün şablonuna gelişmiş mod yetkisi eklenir.
 *
 * Yetki yeni ("gorunum_modu"); rol şablonları veritabanında durduğu için
 * config'teki şablon var olan network rolüne kendiliğinden geçmez. Yönetici
 * rolü bütün kontrolleri zaten geçiyor, ona yazılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('slug', 'network')->where('is_admin', false)->get() as $rol) {
            $sablon = json_decode((string) $rol->template, true) ?: [];

            if (in_array('view', (array) ($sablon['gorunum_modu'] ?? []), true)) {
                continue;
            }

            $sablon['gorunum_modu'] = ['view'];

            DB::table('roles')->where('id', $rol->id)->update([
                'template' => json_encode($sablon, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('slug', 'network')->get() as $rol) {
            $sablon = json_decode((string) $rol->template, true) ?: [];
            unset($sablon['gorunum_modu']);
            DB::table('roles')->where('id', $rol->id)->update(['template' => json_encode($sablon, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
