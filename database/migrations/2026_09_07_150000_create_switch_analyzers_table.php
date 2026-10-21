<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port aynalama (analyzer).
 *
 * Panel "ethernet-switching-options" altındaki aynalama oturumlarını hiç
 * yönetmiyordu: cihazda vardı, panelde yoktu. Aynalama bir teşhis aracıdır ve
 * aynı zamanda bir DİNLEME yoludur — bir portun bütün trafiği başka bir porta
 * kopyalanır — bu yüzden envanterde görünür olması güvenliğin parçasıdır.
 *
 * Giriş kaynakları AYRI TABLO DEĞİL json listelerdir. Gerekçe: kaynak satırı
 * yalnızca bir addır (ge-0/0/5.0 ya da bir VLAN adı); kendi özniteliği,
 * sırası ve kimliği yoktur, hiçbir rotada tek başına adreslenmez ve yazma
 * yolu analyzer'ı her seferinde replace="replace" ile bütün olarak yeniden
 * kurar. Ayrı tablo bir model, bir factory, bir uuid ve yeni bir IDOR yüzeyi
 * getirir, karşılığında hiçbir şey vermezdi. Bu kod tabanı aynı şekli zaten
 * json tutuyor (switch_prefix_lists.egress_interfaces,
 * switch_firewall_terms.interfaces); ayrı tablo ise kimliği ve SIRASI olan
 * çocuklar içindir (firewall/politika terimleri, prefix list girdileri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_analyzers', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('switch_id')->constrained('switches')->cascadeOnDelete();

            // Cihazda aynalama oturumunun KİMLİĞİ adıdır.
            $table->string('name', 64);

            // Kaç pakette bir aynalansın (1 = hepsi).
            $table->unsignedSmallInteger('ratio')->nullable();

            // low / medium-low / medium-high / high.
            $table->string('loss_priority', 16)->nullable();

            /*
             * Çıkış hedefi ya ARAYÜZ ya VLAN'dır; ikisi birlikte verilemez.
             * Kısıt formda ve doğrulamada da durur.
             */
            $table->string('output_interface', 64)->nullable();
            $table->string('output_vlan', 64)->nullable();

            // Aynalanacak kaynaklar: yön × (arayüz | vlan).
            $table->json('ingress_interfaces')->nullable();
            $table->json('ingress_vlans')->nullable();
            $table->json('egress_interfaces')->nullable();
            $table->json('egress_vlans')->nullable();

            /*
             * Panelin tanımadığı satırlar. Analyzer TEK BİR düğüm olarak
             * yeniden kurulduğu için artık her seviyede ayrı ayrı durur ve
             * yol adıyla saklanır: "" (analyzer), "input", "input/ingress",
             * "input/egress", "output", "output/vlan". Firewall terimindeki
             * üç ayrı kolonun (residual/from/then) aynı fikri; burada iç içe
             * altı seviye olduğu için kolon yerine yol->satırlar eşlemesi
             * tutulur.
             */
            $table->json('residual_xml')->nullable();

            $table->timestamps();
            $table->unique(['switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_analyzers');
    }
};
