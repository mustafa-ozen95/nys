<?php

declare(strict_types=1);

namespace Tests\Feature\Logs;

use App\Enums\ActivityChannel;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /* Bu sınıf yetki düzeneğini sınıyor; üretimde kapalı (bkz. TestCase). */
        $this->yetkiDagitimiAc();
    }

    /**
     * Kaydın TAMAMINI gören okuyucu.
     *
     * Ekranın listeleme ve süzme davranışı bununla ölçülür; kimin neyi
     * görebildiği ayrı bir sorudur ve kendi testleri aşağıda
     * (bkz. ActivityLog::scopeVisibleTo).
     */
    private function reader(): User
    {
        return User::factory()->admin()->create();
    }

    /** Yönetici olmayan, yalnızca kayıt okuma yetkisi verilmiş kişi. */
    private function sinirliOkuyucu(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'logs', ['view']);

        return $user->fresh();
    }

    public function test_a_user_without_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('logs.index'))
            ->assertForbidden();
    }

    public function test_records_are_listed_newest_first(): void
    {
        ActivityLog::factory()->create(['description' => 'ESKI KAYIT', 'created_at' => now()->subDay()]);
        ActivityLog::factory()->create(['description' => 'YENI KAYIT', 'created_at' => now()]);

        $response = $this->actingAs($this->reader())->get(route('logs.index'))->assertOk();

        $this->assertLessThan(
            strpos($response->content(), 'ESKI KAYIT'),
            strpos($response->content(), 'YENI KAYIT'),
        );
    }

    public function test_records_can_be_filtered_by_channel(): void
    {
        ActivityLog::factory()->create(['channel' => ActivityChannel::Ssh, 'description' => 'SSH KAYDI']);
        ActivityLog::factory()->create(['channel' => ActivityChannel::Crud, 'description' => 'CRUD KAYDI']);

        $this->actingAs($this->reader())
            ->get(route('logs.index', ['channel' => 'ssh']))
            ->assertOk()
            ->assertSee('SSH KAYDI')
            ->assertDontSee('CRUD KAYDI');
    }

    public function test_records_can_be_filtered_by_user(): void
    {
        $ali = User::factory()->create(['name' => 'Ali']);
        ActivityLog::factory()->for($ali)->create(['description' => 'ALININ KAYDI']);
        ActivityLog::factory()->create(['description' => 'BASKASININ KAYDI']);

        $this->actingAs($this->reader())
            ->get(route('logs.index', ['user' => $ali->uuid]))
            ->assertOk()
            ->assertSee('ALININ KAYDI')
            ->assertDontSee('BASKASININ KAYDI');
    }

    public function test_records_can_be_filtered_by_date_range(): void
    {
        ActivityLog::factory()->create(['description' => 'ARALIKTA', 'created_at' => now()->subDays(2)]);
        ActivityLog::factory()->create(['description' => 'ARALIK DISI', 'created_at' => now()->subDays(20)]);

        $this->actingAs($this->reader())
            ->get(route('logs.index', ['from' => now()->subDays(5)->toDateString()]))
            ->assertOk()
            ->assertSee('ARALIKTA')
            ->assertDontSee('ARALIK DISI');
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->reader())
            ->get(route('logs.index', ['from' => '2026-05-01', 'to' => '2026-04-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_an_unknown_channel_is_rejected(): void
    {
        $this->actingAs($this->reader())
            ->get(route('logs.index', ['channel' => 'uydurma']))
            ->assertSessionHasErrors('channel');
    }

    public function test_the_list_is_paginated(): void
    {
        ActivityLog::factory()->count(30)->create();

        $this->actingAs($this->reader())
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Sayfalama');
    }

    public function test_the_screen_does_not_query_per_record(): void
    {
        ActivityLog::factory()->count(25)->create();
        $user = $this->reader();

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('logs.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $count, "Beklenenden fazla sorgu: {$count}");
    }

    /* ── Kimin neyi gördüğü ── */

    /**
     * GÖREMEDİĞİ CİHAZIN GEÇMİŞİ DE GÖRÜNMEZ.
     *
     * Kayıt yetkisi yönetici olmayan birine de verilebiliyor; süzülmediğinde
     * o kişi göremediği switchlerin yazma geçmişini okuyabiliyordu.
     */
    public function test_goremedigi_cihazin_kaydi_gorunmez(): void
    {
        $okuyucu = $this->sinirliOkuyucu();

        $gorunur = NetworkSwitch::factory()->create(['hostname' => 'GORUNUR-SW']);
        $gizli = NetworkSwitch::factory()->create(['hostname' => 'GIZLI-SW']);

        app(UserPermissionResolver::class)
            ->syncResource($okuyucu, 'switches', [], ['view' => [$gorunur->id]]);

        ActivityLog::factory()->create([
            'description' => 'GORUNUR KAYIT',
            'subject_type' => $gorunur->getMorphClass(),
            'subject_id' => $gorunur->id,
        ]);

        ActivityLog::factory()->create([
            'description' => 'GIZLI KAYIT',
            'subject_type' => $gizli->getMorphClass(),
            'subject_id' => $gizli->id,
        ]);

        $this->actingAs($okuyucu->fresh())
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('GORUNUR KAYIT')
            ->assertDontSee('GIZLI KAYIT');
    }

    /**
     * BAŞKASININ SAYFA GEZİNTİSİ GÖRÜNMEZ.
     *
     * Erişim kanalındaki satırlar adreslerinde cihaz uuid'si taşıyor:
     * "göremezsin" denen envanter oradan sızıyordu. Kişi kendi hareketlerini
     * görmeye devam eder.
     */
    public function test_baskasinin_hareketi_gorunmez(): void
    {
        $okuyucu = $this->sinirliOkuyucu();
        $baskasi = User::factory()->create();

        ActivityLog::factory()->create(['user_id' => $okuyucu->id, 'description' => 'KENDI HAREKETIM']);
        ActivityLog::factory()->create(['user_id' => $baskasi->id, 'description' => 'BASKASININ HAREKETI']);

        $this->actingAs($okuyucu)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('KENDI HAREKETIM')
            ->assertDontSee('BASKASININ HAREKETI');
    }

    /** Yönetici hepsini görür: denetim kaydının varlık sebebi budur. */
    public function test_yonetici_hepsini_gorur(): void
    {
        $baskasi = User::factory()->create();
        ActivityLog::factory()->create(['user_id' => $baskasi->id, 'description' => 'BASKASININ HAREKETI']);

        $this->actingAs($this->reader())
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('BASKASININ HAREKETI');
    }

    /**
     * Süzgeç listesi de yetkiye bağlı: kadro listesi tek başına bilgidir.
     */
    public function test_suzgec_listesi_kadroyu_acmaz(): void
    {
        $okuyucu = $this->sinirliOkuyucu();
        User::factory()->create(['name' => 'LISTEDE OLMAMALI']);

        $this->actingAs($okuyucu)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertDontSee('LISTEDE OLMAMALI');
    }
}
