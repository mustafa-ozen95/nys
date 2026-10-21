<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dış parola kasası (`kasa.*`) ayarlarını siler.
 *
 * Sunucuların parolası panelin kendisindedir ve panel onu kendisi değiştirir
 * (bkz. Services/Sunucu/ServerPasswordRotator). Aynı sunucunun parolası iki
 * yerde birden durursa hangisinin doğru olduğu bilinmez olur.
 *
 * AYARLAR SİLİNİR, sessizce bırakılmaz: kalan bir `kasa.jeton` satırı,
 * hiçbir şeyin okumadığı ama veritabanında duran bir SIRDIR.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'like', 'kasa.%')->delete();
    }

    /**
     * GERİ ALINAMAZ ve alınmamalı.
     *
     * Silinen şey bir sırdı; geri getirilecek bir değer elimizde yok ve
     * olmamalı da. Özellik geri istenirse ayarlar yeniden girilir.
     */
    public function down(): void
    {
        //
    }
};
