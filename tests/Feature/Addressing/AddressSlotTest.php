<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Models\AddressBlock;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AddressSlotTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'addressing', ['view', 'create']);

        return $user->fresh();
    }

    public function test_it_returns_every_slot_and_marks_the_taken_ones(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/22')->create();
        AddressBlock::factory()->network('10.0.1.0/24')->create(['parent_id' => $root->id]);

        $response = $this->actingAs($this->operator())
            ->getJson(route('ajax.addressing.slots', $root).'?prefix=24')
            ->assertOk()
            ->assertJsonPath('parent', '10.0.0.0/22')
            ->assertJsonCount(4, 'slots');

        $slots = collect($response->json('slots'))->keyBy('cidr');

        $this->assertTrue($slots['10.0.0.0/24']['available']);
        $this->assertFalse($slots['10.0.1.0/24']['available']);
        $this->assertTrue($slots['10.0.2.0/24']['available']);
        $this->assertSame(254, $slots['10.0.0.0/24']['usable']);
    }

    public function test_a_prefix_wider_than_the_parent_is_rejected(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/22')->create();

        $this->actingAs($this->operator())
            ->getJson(route('ajax.addressing.slots', $root).'?prefix=22')
            ->assertStatus(422);
    }

    public function test_the_prefix_is_required(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/22')->create();

        $this->actingAs($this->operator())
            ->getJson(route('ajax.addressing.slots', $root))
            ->assertStatus(422);
    }

    public function test_it_refuses_a_user_without_view_permission(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/22')->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('ajax.addressing.slots', $root).'?prefix=24')
            ->assertForbidden();
    }
}
