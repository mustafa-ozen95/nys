<?php

declare(strict_types=1);

use App\Enums\BadgeVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Roller veriye taşınır.
 *
 * Rol bir kayıttır, kodda sabit bir enum değil: yeni bir rol açmak kod
 * değişikliği gerektirmez. Rol panelden açılır, hazır yetkileri panelden
 * düzenlenir.
 *
 * Yönetici rolü "is_admin" taşır ve bütün kontrolleri geçer; "is_system"
 * roller silinemez, adı (slug) değiştirilemez — kod ve seeder onlara adıyla
 * atıfta bulunuyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Kod ve seeder bu adla atıfta bulunur; sistem rollerinde değişmez.
            $table->string('slug', 40)->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('badge', 20)->default(BadgeVariant::Gray->value);

            /*
             * Bütün kontrolleri geçen rol. Birden fazla olabilir ama en az
             * biri KALMAK ZORUNDA: yoksa paneli kimse yönetemez.
             */
            $table->boolean('is_admin')->default(false);

            // Yerleşik rol: silinemez, slug'ı değişmez.
            $table->boolean('is_system')->default(false);

            /*
             * Yeni kullanıcının varsayılan rolü. Yönetici olmayan biri
             * kullanıcı açtığında rol seçemez; bu rol verilir. TEK bir rol
             * varsayılan olabilir ve yönetici rolü varsayılan OLAMAZ —
             * "kullanıcı ekle" yetkisi, yönetici üretme yetkisi değildir.
             */
            $table->boolean('is_default')->default(false);

            /*
             * Rolün hazır yetki şablonu: kaynak => aksiyonlar. Rol tek başına
             * yetki DEĞİLDİR; bu şablon kullanıcıya uygulandığında yetkiye
             * dönüşür (bkz. RoleTemplate).
             */
            $table->json('template')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        $this->seedBuiltIn();

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('email')->constrained('roles');
        });

        $this->linkUsers();

        Schema::table('users', function (Blueprint $table): void {
            // Sütunun üzerinde indeks var; SQLite indeksli sütunu düşürmüyor.
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 40)->default('teknik')->after('email');
            $table->index('role');
        });

        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $slug = DB::table('roles')->where('id', $user->role_id)->value('slug');
                DB::table('users')->where('id', $user->id)->update(['role' => $slug ?? 'teknik']);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('roles');
    }

    /**
     * Yerleşik roller. Etiketler ve hazır yetkiler config dosyasından
     * buraya taşınır ki mevcut kurulumlar hiçbir şey kaybetmesin.
     */
    private function seedBuiltIn(): void
    {
        $templates = (array) config('permissions.templates', []);

        $roles = [
            ['slug' => 'admin', 'label' => 'Yönetici', 'badge' => BadgeVariant::Purple->value, 'is_admin' => true, 'position' => 0,
                'description' => 'Bütün yetki kontrollerini geçer. En az bir yönetici rolü kalmak zorundadır.'],
            ['slug' => 'network', 'label' => 'Ağ Ekibi', 'badge' => BadgeVariant::Blue->value, 'is_admin' => false, 'position' => 1,
                'description' => 'Cihazlar üzerinde genel yetkili. Kullanıcı yönetimi ve panel ayarları yöneticide kalır.'],
            ['slug' => 'teknik', 'label' => 'Teknik Ekip', 'badge' => BadgeVariant::Gray->value, 'is_admin' => false, 'position' => 2,
                'description' => 'Görür ve bağlanır; cihazda yalnızca porta müdahale eder.', 'is_default' => true],
        ];

        // Örnek veri kapalıyken YALNIZCA yönetici rolü kurulur; kurulumu
        // yapan kendi rollerini panelden açar.
        if (! config('app.sample_data')) {
            $roles = array_slice($roles, 0, 1);
        }

        foreach ($roles as $role) {
            DB::table('roles')->insert([
                'is_default' => false,
                ...$role,
                'uuid' => (string) Str::uuid(),
                'is_system' => true,
                'template' => json_encode($templates[$role['slug']] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function linkUsers(): void
    {
        $ids = DB::table('roles')->pluck('id', 'slug');
        $fallback = $ids['teknik'] ?? $ids->first();

        foreach ($ids as $slug => $id) {
            DB::table('users')->where('role', $slug)->update(['role_id' => $id]);
        }

        DB::table('users')->whereNull('role_id')->update(['role_id' => $fallback]);
    }
};
