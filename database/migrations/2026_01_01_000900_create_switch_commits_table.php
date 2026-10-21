<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_commits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('junos_version')->nullable();

            // Switch üzerindeki JunOS kullanıcı adı — panel kullanıcısı değildir,
            // bu yüzden users tablosuna foreign key verilmez.
            $table->string('committed_by')->nullable();

            $table->text('comment')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'sequence_number']);
            $table->index('committed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_commits');
    }
};
