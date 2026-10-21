<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kayıt zinciri İKİYE ayrılır: denetim ve erişim.
 *
 * Sebep saklama süresidir. Sayfa açma kayıtları (erişim) hacmin neredeyse
 * tamamıdır ve denetim kayıtlarıyla (oturum açma, cihaza yazma, CRUD) aynı
 * süreyi paylaşmak zorunda kalıyordu: tek zincirin ORTASINDAN satır silmek
 * zinciri kırar, çünkü sonraki satırın "önceki özeti" ortada kalmaz.
 *
 * Her kanal kendi zincirine yazınca, erişim kayıtları kendi zincirinin
 * BAŞINDAN budanabilir ve çapa yenilenir; denetim zinciri hiç etkilenmez.
 * İki zincir de aynı güçle korunmaya devam eder — erişim kayıtları zincir
 * dışına ATILMAZ, kullanıcının her hareketi kanıtlanabilir kalır.
 *
 * Var olan satırların hepsi 'audit' olarak işaretlenir: onlar gerçekten o
 * tek zincirin içindedir ve orada kalmalıdır. Erişim zinciri bugünden
 * sonraki satırlarla başlar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->string('chain', 10)->default('audit')->after('chain_version');
            $table->index(['chain', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['chain', 'id']);
            $table->dropColumn('chain');
        });
    }
};
