<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazın kendi ayarları ve spanning tree.
 *
 * Cihazda duran ama panelin göremediği yapılandırma vardı: sistem adı, saat
 * dilimi, DNS/NTP/syslog, SNMP konum-iletişim-community ve RSTP. Görülmeyen
 * ayar denetlenemez; terminalden değiştirildiğinde kimse fark etmez.
 *
 * Biçimlerin hepsi jn32-test (12.3R12.4) üzerinde commit check ile
 * doğrulandı; tahmin yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switches', function (Blueprint $table): void {
            // SNMP kimliği: ad zaten hostname'den yazılıyordu, bunlar eksikti.
            $table->string('snmp_location')->nullable()->after('archival_site');
            $table->string('snmp_contact')->nullable()->after('snmp_location');

            $table->string('time_zone', 64)->nullable()->after('snmp_contact');

            /*
             * Junos bu üçünü de LİSTE tutar ve sıra anlamlıdır (ilk yanıt
             * veren kullanılır); tek kolon temsil edemezdi.
             */
            $table->json('name_servers')->nullable()->after('time_zone');
            $table->json('ntp_servers')->nullable()->after('name_servers');
            $table->json('syslog_hosts')->nullable()->after('ntp_servers');

            /*
             * RSTP köprü ayarları. "disable" ile "hiç yazılmamış" aynı şey
             * değildir: ilki açıkça kapatır, ikincisi cihazın varsayılanını
             * bırakır. Bu yüzden enabled ayrı bir bayraktır.
             */
            $table->boolean('rstp_configured')->default(false)->after('syslog_hosts');
            $table->boolean('rstp_enabled')->default(true)->after('rstp_configured');
            $table->string('rstp_bridge_priority', 8)->nullable()->after('rstp_enabled');
            $table->boolean('rstp_bpdu_block_on_edge')->default(false)->after('rstp_bridge_priority');
        });

        /*
         * SNMP community'leri AYRI tablodur: cihazda birden fazla olur, her
         * biri kendi yetkisini ve istemci listesini taşır.
         *
         * Ad bir SIRDIR (okuma parolası gibi çalışır): şifreli saklanır,
         * modelde $hidden'dır ve cihaz farkında maskelenir.
         */
        Schema::create('switch_snmp_communities', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // Şifreli saklandığı için benzersizlik veritabanında kurulamaz;
            // kontrol uygulama tarafındadır.
            $table->text('name');
            $table->string('name_digest', 64)->index();

            $table->string('authorization', 12)->default('read-only');
            $table->json('clients')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'name_digest']);
        });

        /*
         * RSTP'nin arayüz başına ayarları. Ayrı tablodur çünkü Junos "all"
         * diye sanal bir arayüz adı da kabul eder; port tablosunda karşılığı
         * yoktur.
         */
        Schema::create('switch_rstp_interfaces', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            $table->string('interface_name', 64);
            $table->boolean('edge')->default(false);
            $table->boolean('no_root_port')->default(false);
            $table->unsignedInteger('cost')->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamps();

            $table->unique(['switch_id', 'interface_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_rstp_interfaces');
        Schema::dropIfExists('switch_snmp_communities');

        Schema::table('switches', function (Blueprint $table): void {
            $table->dropColumn([
                'snmp_location', 'snmp_contact', 'time_zone', 'name_servers',
                'ntp_servers', 'syslog_hosts', 'rstp_configured', 'rstp_enabled',
                'rstp_bridge_priority', 'rstp_bpdu_block_on_edge',
            ]);
        });
    }
};
