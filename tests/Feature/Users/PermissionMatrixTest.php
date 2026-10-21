<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\NetworkSwitch;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Authorization\PermissionMatrixService;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

final class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_admin_can_open_the_matrix(): void
    {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('users.permissions', $target))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.permissions', $target))
            ->assertOk();
    }

    /**
     * config/permissions.php'daki SÜTUN ADLARI gerçekten var olmalı.
     *
     * Config sütun adlarını dizge olarak taşır ("order", "search"). Bir sütun
     * göçle yeniden adlandırıldığında config sessizce çürür ve o kaynağın
     * yetki ekranı 500 verir (ör. address_start → range_start yeniden
     * adlandırması "addressing" kaynağını bu yolla kırar) ve diğer testler
     * bunu görmez.
     *
     * Sorguyu çalıştırmak YETMİYOR. SQLite çift tırnaklı bilinmeyen bir
     * tanımlayıcıyı sütun değil STRING SABİTİ sayar; Eloquent de
     * `order by "address_start"` ürettiği için sorgu sessizce geçer. Aynı
     * sorgu MySQL'de "Unknown column" ile düşer. Bu yüzden kontrol
     * veritabanının reddine değil, ŞEMANIN KENDİSİNE bakar — ve her
     * sürücüde çalışır.
     */
    public function test_yetki_yapilandirmasindaki_sutunlar_gercekten_var(): void
    {
        $kaynaklar = config('permissions.resources');

        $this->assertNotEmpty($kaynaklar);

        foreach ($kaynaklar as $kaynak => $tanim) {
            // Kayıtlarını kendi veren kaynakların tablosu yoktur.
            if (! isset($tanim['model'])) {
                continue;
            }

            $tablo = (new $tanim['model'])->getTable();

            $sutunlar = array_merge(
                isset($tanim['order']) ? [$tanim['order']] : [],
                $tanim['search'] ?? [],
            );

            foreach ($sutunlar as $sutun) {
                $this->assertTrue(
                    Schema::hasColumn($tablo, $sutun),
                    "'{$kaynak}' kaynağı '{$sutun}' sütununa bakıyor ama {$tablo} tablosunda böyle bir sütun yok.",
                );
            }
        }
    }

    /**
     * Her kaynağın kayıt listesi gerçekten kurulabilmeli.
     *
     * Şema kontrolü sütun adlarını yakalar; bu test de listenin PHP tarafını
     * (title/subtitle erişimi, sıralama, matris eşlemesi) çalıştırır.
     */
    public function test_her_kaynagin_kayit_listesi_kurulabilir(): void
    {
        $matris = app(PermissionMatrixService::class);
        $hedef = User::factory()->create();

        $kaynaklar = array_keys(config('permissions.resources'));

        $this->assertNotEmpty($kaynaklar);

        foreach ($kaynaklar as $kaynak) {
            try {
                $matris->forUser($hedef, $kaynak);
            } catch (Throwable $e) {
                $this->fail("'{$kaynak}' kaynağının kayıt listesi kurulamadı: ".$e->getMessage());
            }

            $this->addToAssertionCount(1);
        }
    }

    public function test_the_matrix_lists_the_records_of_the_selected_resource(): void
    {
        NetworkSwitch::factory()->create(['hostname' => 'MATRISTE-GORUNUR']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.permissions', ['user' => User::factory()->create(), 'resource' => 'switches']))
            ->assertOk()
            ->assertSee('MATRISTE-GORUNUR');
    }

    public function test_an_unknown_resource_falls_back_to_the_first_one(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.permissions', ['user' => User::factory()->create(), 'resource' => 'olmayan']))
            ->assertOk();
    }

    /**
     * SIRALI ANAHTAR KABUL EDİLMEZ.
     *
     * Matris kayıt kimliği olarak birincil anahtarı değil uuid taşır: sıralı
     * anahtar kaç kaydınız olduğunu söyler ve komşusunu denemeye davet eder.
     * Elle yazılmış bir sayı yetki üretmemeli.
     */
    public function test_sirali_anahtarla_yetki_verilemez(): void
    {
        $target = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', $target), [
                'resource' => 'switches',
                'cells' => ['view' => [(string) $switch->id => 'allow']],
            ])
            ->assertRedirect();

        $this->assertFalse(
            app(UserPermissionResolver::class)->allows($target->fresh(), 'switches', 'view', $switch->id),
        );
    }

    /** Ekran kayıt kimliği olarak uuid basar. */
    public function test_matris_ekrani_uuid_basar(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.permissions', ['user' => User::factory()->create(), 'resource' => 'switches']))
            ->assertOk()
            ->assertSee($switch->uuid)
            ->assertDontSee('cells[view]['.$switch->id.']', escape: false);
    }

    public function test_saving_writes_wildcard_and_record_level_rules(): void
    {
        $target = User::factory()->create();
        $allowed = NetworkSwitch::factory()->create();
        $denied = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', $target), [
                'resource' => 'switches',
                'wildcard' => ['view' => 'allow'],
                /* Form UUID taşır; sıralı birincil anahtar dışarı çıkmaz. */
                'cells' => [
                    'update' => [$allowed->uuid => 'allow'],
                    'view' => [$denied->uuid => 'deny', $allowed->uuid => 'inherit'],
                ],
            ])
            ->assertRedirect();

        $resolver = app(UserPermissionResolver::class);
        $target = $target->fresh();

        $this->assertTrue($resolver->allows($target, 'switches', 'view', $allowed->id));
        $this->assertFalse($resolver->allows($target, 'switches', 'view', $denied->id));
        $this->assertTrue($resolver->allows($target, 'switches', 'update', $allowed->id));
        $this->assertFalse($resolver->allows($target, 'switches', 'update', $denied->id));
    }

    public function test_inherit_cells_do_not_create_rows(): void
    {
        $target = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', $target), [
                'resource' => 'switches',
                'wildcard' => [],
                'cells' => ['view' => [$switch->id => 'inherit']],
            ]);

        $this->assertSame(0, UserPermission::query()->where('user_id', $target->id)->count());
    }

    public function test_saving_replaces_the_previous_rules_of_that_resource_only(): void
    {
        $target = User::factory()->create();

        UserPermission::factory()->for($target)->resource('switches', 'delete')->create();
        UserPermission::factory()->for($target)->resource('servers', 'view')->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', $target), [
                'resource' => 'switches',
                'wildcard' => ['view' => 'allow'],
            ]);

        $rows = $target->fresh()->permissions;

        $this->assertFalse($rows->contains(fn (UserPermission $row): bool => $row->resource === 'switches' && $row->action === 'delete'));
        $this->assertTrue($rows->contains(fn (UserPermission $row): bool => $row->resource === 'servers'));
    }

    public function test_an_unknown_resource_is_rejected_on_save(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', User::factory()->create()), [
                'resource' => 'uydurma',
                'wildcard' => ['view' => 'allow'],
            ])
            ->assertSessionHasErrors('resource');
    }

    public function test_an_unknown_cell_state_is_rejected(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', User::factory()->create()), [
                'resource' => 'switches',
                'cells' => ['view' => [$switch->id => 'her-seye-izin']],
            ])
            ->assertSessionHasErrors();
    }

    public function test_the_matrix_is_recorded_in_the_activity_log(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.permissions.update', User::factory()->create()), [
                'resource' => 'switches',
                'wildcard' => ['view' => 'allow'],
            ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'updated']);
    }
}
