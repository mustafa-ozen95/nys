<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Models\AddressBlock;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adresleme ekranı, kayıt düzeyindeki "görme" yasağını ağaçta ve seçili
 * blokta da uygular; yalnızca kaynak geneline bakmak yetmiyordu.
 */
final class RecordLevelViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_gorme_yasagi_olan_blok_agacta_ve_detayda_gorunmez(): void
    {
        $gizli = AddressBlock::factory()->network('10.8.0.0/16')->create(['description' => 'GIZLI-NOT-ALANI']);
        $acik = AddressBlock::factory()->network('10.9.0.0/16')->create();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)
            ->syncResource($user, 'addressing', ['view'], [], ['view' => [$gizli->id]]);
        $user = $user->fresh();

        $this->actingAs($user)
            ->get(route('addressing.index'))
            ->assertOk()
            ->assertSee('10.9.0.0/16')
            ->assertDontSee('10.8.0.0/16');

        $this->actingAs($user)
            ->get(route('addressing.index', ['block' => $gizli->uuid]))
            ->assertOk()
            ->assertDontSee('GIZLI-NOT-ALANI')
            ->assertDontSee('10.8.0.0/16');

        $this->actingAs($user)
            ->get(route('addressing.index', ['block' => $acik->uuid]))
            ->assertOk()
            ->assertSee('10.9.0.0/16');
    }

    public function test_yasak_yoksa_her_blok_gorunur(): void
    {
        AddressBlock::factory()->network('10.8.0.0/16')->create();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'addressing', ['view']);

        $this->actingAs($user->fresh())
            ->get(route('addressing.index'))
            ->assertOk()
            ->assertSee('10.8.0.0/16');
    }
}
