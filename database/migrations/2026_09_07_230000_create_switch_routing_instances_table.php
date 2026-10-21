<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yönlendirme tabloları (routing-instances).
 *
 * Filtre tabanlı yönlendirmenin (FBF) merkezi (örnek):
 *
 *   firewall … term tablo-a-musteri then routing-instance tablo-a
 *   routing-instances tablo-a instance-type forwarding
 *   routing-instances tablo-a routing-options static route 0.0.0.0/0
 *       qualified-next-hop 198.51.100.141 metric 150
 *
 * Panel bugüne kadar terimdeki ATFI yazabiliyordu ama tablonun kendisini ne
 * görüyor ne kurabiliyordu: kullanıcı olmayan bir tablo adı yazabilir ve
 * hatayı ancak cihaz reddedince görürdü.
 *
 * Tablonun içindeki route'lar switch_routes'ta durur (routing_instance
 * sütunu); ayrı bir route modeli açmak aynı bilgiyi ikilemek olurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_routing_instances', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();
            $table->string('name', 63);
            $table->string('instance_type', 32)->nullable();
            $table->string('description')->nullable();

            // Panelin tanımadığı satırlar olduğu gibi korunur.
            $table->json('residual_xml')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'name']);
        });

        Schema::table('switch_routes', function (Blueprint $table): void {
            /*
             * Route bir yönlendirme tablosunun İÇİNDE olabilir. Boşsa ana
             * tablodadır (routing-options static).
             */
            $table->string('routing_instance', 63)->nullable()->after('rib');
        });
    }

    public function down(): void
    {
        Schema::table('switch_routes', function (Blueprint $table): void {
            $table->dropColumn('routing_instance');
        });

        Schema::dropIfExists('switch_routing_instances');
    }
};
