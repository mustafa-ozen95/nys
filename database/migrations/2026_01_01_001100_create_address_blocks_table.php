<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('address_blocks')->cascadeOnDelete();

            // Ağ adresi ve prefix ayrı tutulur; çakışma/kapsama sorguları
            // sayısal aralık üzerinden SQL'de yapılır, uygulamada değil.
            $table->string('network', 45);
            $table->unsignedTinyInteger('prefix_length');
            $table->unsignedBigInteger('address_start')->nullable();
            $table->unsignedBigInteger('address_end')->nullable();
            $table->string('family', 10)->default('inet');

            $table->string('description')->nullable();
            $table->boolean('is_subnetted')->default(false);

            // Bloğun izlediği yol: omurgadan erişim switchine, oradan bir
            // VLAN'a veya porta. Omurga boş olabilir; blok router üzerinde
            // duruyor olabilir.
            $table->foreignId('core_switch_id')->nullable()->constrained('switches')->nullOnDelete();
            $table->foreignId('access_switch_id')->nullable()->constrained('switches')->nullOnDelete();

            // Switchte henüz tanımlı olmayan VLAN/port da işaretlenebilsin diye
            // bunlar bilinçli olarak foreign key değildir.
            $table->string('connection_type', 30)->nullable();
            $table->unsignedSmallInteger('vlan_tag')->nullable();
            $table->string('port_name')->nullable();

            $table->timestamps();

            $table->unique(['network', 'prefix_length']);
            $table->index(['address_start', 'address_end']);
            $table->index(['parent_id', 'is_subnetted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_blocks');
    }
};
