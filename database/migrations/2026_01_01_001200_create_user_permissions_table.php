<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // config/permissions.php içindeki kaynak anahtarı ('switches', 'servers'…).
            // Model sınıfı değildir; bu yüzden foreign key yoktur.
            $table->string('resource', 30);

            // 0 = kaynağın tamamı (wildcard), >0 = tek kayıt.
            $table->unsignedBigInteger('resource_id')->default(0);

            $table->string('action', 30);
            $table->string('effect', 10)->default('allow');
            $table->timestamps();

            $table->unique(['user_id', 'resource', 'resource_id', 'action'], 'user_permission_unique');
            $table->index(['user_id', 'resource', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
