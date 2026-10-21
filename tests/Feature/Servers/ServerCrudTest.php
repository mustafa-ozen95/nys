<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Models\Server;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ServerCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'short_name' => 'LNX-09',
            'long_name' => 'Test sunucusu',
            'ip_address' => '198.18.20.9',
            'os_type' => 'linux',
            'os_version' => 'Ubuntu 24.04 LTS',
            'connection_protocol' => 'ssh',
            'connection_port' => 22,
            'connection_username' => 'root',
            'connection_password' => 'cok-gizli-sunucu-parolasi',
            'status' => 'online',
        ], $overrides);
    }

    private function operator(array $actions = ['view', 'create', 'update', 'delete']): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'servers', $actions);

        return $user->fresh();
    }

    public function test_the_list_is_closed_without_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('servers.index'))
            ->assertForbidden();
    }

    public function test_only_permitted_servers_are_listed(): void
    {
        $user = User::factory()->create();
        $visible = Server::factory()->create(['short_name' => 'GORUNUR']);
        Server::factory()->create(['short_name' => 'GIZLI']);

        app(UserPermissionResolver::class)
            ->syncResource($user, 'servers', [], ['view' => [$visible->id]]);

        $this->actingAs($user->fresh())
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('GORUNUR')
            ->assertDontSee('GIZLI');
    }

    public function test_a_server_can_be_created(): void
    {
        $this->actingAs($this->operator())
            ->post(route('servers.store'), $this->payload())
            ->assertRedirect(route('servers.index'));

        $this->assertDatabaseHas('servers', ['short_name' => 'LNX-09', 'ip_address' => '198.18.20.9']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'created']);
    }

    public function test_the_access_password_is_stored_encrypted(): void
    {
        $this->actingAs($this->operator())->post(route('servers.store'), $this->payload());

        $stored = DB::table('servers')->value('connection_password');

        $this->assertNotSame('cok-gizli-sunucu-parolasi', $stored);
        $this->assertSame('cok-gizli-sunucu-parolasi', Crypt::decryptString($stored));
    }

    public function test_the_default_port_is_applied_per_protocol(): void
    {
        $this->actingAs($this->operator())
            ->post(route('servers.store'), $this->payload([
                'connection_protocol' => 'rdp',
                'connection_port' => '',
            ]));

        $this->assertSame(3389, Server::query()->firstWhere('short_name', 'LNX-09')->connection_port);
    }

    public function test_a_duplicate_short_name_is_rejected(): void
    {
        Server::factory()->create(['short_name' => 'LNX-09']);

        $this->actingAs($this->operator())
            ->post(route('servers.store'), $this->payload())
            ->assertSessionHasErrors('short_name');
    }

    public function test_an_unknown_protocol_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('servers.store'), $this->payload(['connection_protocol' => 'telnet']))
            ->assertSessionHasErrors('connection_protocol');
    }

    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        $server = Server::factory()->create(['connection_password' => 'onceki-sunucu-parolasi']);

        $this->actingAs($this->operator())
            ->put(route('servers.update', $server), $this->payload([
                'short_name' => $server->short_name,
                // Hedef aynı kalıyor: hedef değişseydi parola yeniden istenirdi.
                'ip_address' => $server->ip_address,
                'connection_protocol' => $server->connection_protocol->value,
                'connection_port' => $server->connection_port,
                'connection_password' => '',
            ]))
            ->assertRedirect(route('servers.index'));

        $this->assertSame('onceki-sunucu-parolasi', $server->fresh()->connection_password);
    }

    /**
     * Hedef değişince kayıtlı parola yeni hedefe gider; parolayı dışarı
     * çıkarma yetkisi olmayan kullanıcı hedefi kendi dinleyicisine çeviremez.
     */
    public function test_changing_the_target_requires_the_password_again(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '198.18.20.9', 'connection_protocol' => 'ssh', 'connection_port' => 22,
            'connection_password' => 'onceki-sunucu-parolasi',
        ]);

        foreach (['ip_address' => '198.51.100.10', 'connection_port' => 2222] as $alan => $deger) {
            $this->actingAs($this->operator())
                ->put(route('servers.update', $server), $this->payload([
                    'short_name' => $server->short_name,
                    'connection_password' => '',
                    $alan => $deger,
                ]))
                ->assertSessionHasErrors('connection_password');
        }

        $this->assertSame('198.18.20.9', $server->fresh()->ip_address);
        $this->assertSame(22, $server->fresh()->connection_port);
    }

    public function test_changing_the_target_with_the_password_re_entered_is_allowed(): void
    {
        $server = Server::factory()->create(['ip_address' => '198.18.20.9', 'connection_password' => 'eski']);

        $this->actingAs($this->operator())
            ->put(route('servers.update', $server), $this->payload([
                'short_name' => $server->short_name,
                'ip_address' => '198.18.20.10',
                'connection_password' => 'yeniden-girilen-parola',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('198.18.20.10', $server->fresh()->ip_address);
    }

    public function test_an_unchanged_target_keeps_working_with_a_blank_password(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '198.18.20.9', 'connection_protocol' => 'ssh', 'connection_port' => 22,
            'connection_password' => 'onceki',
        ]);

        $this->actingAs($this->operator())
            ->put(route('servers.update', $server), $this->payload([
                'short_name' => $server->short_name,
                'connection_password' => '',
                'notes' => 'yalnızca not değişti',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('yalnızca not değişti', $server->fresh()->notes);
    }

    public function test_the_list_never_prints_a_stored_password(): void
    {
        Server::factory()->create(['connection_password' => 'asla-gorunmemeli']);

        $this->actingAs($this->operator())
            ->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('asla-gorunmemeli');
    }

    public function test_creating_requires_permission(): void
    {
        $this->actingAs($this->operator(['view']))
            ->post(route('servers.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_a_server_can_be_soft_deleted(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->operator())
            ->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'));

        $this->assertSoftDeleted($server);
    }

    public function test_selecting_a_server_opens_its_detail_with_notes(): void
    {
        $server = Server::factory()->create([
            'short_name' => 'LNX-42',
            'notes' => 'Bakım penceresi pazar 03:00.',
        ]);

        $this->actingAs($this->operator())
            ->get(route('servers.index', ['server' => $server->uuid]))
            ->assertOk()
            ->assertSee('Bakım penceresi pazar 03:00.');
    }

    public function test_a_server_without_notes_says_so(): void
    {
        $server = Server::factory()->create(['notes' => null]);

        $this->actingAs($this->operator())
            ->get(route('servers.index', ['server' => $server->uuid]))
            ->assertOk()
            ->assertSee('Not girilmemiş.');
    }

    public function test_the_detail_pane_is_closed_until_a_server_is_selected(): void
    {
        Server::factory()->create(['notes' => 'GIZLI NOT']);

        $this->actingAs($this->operator())
            ->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('GIZLI NOT');
    }

    public function test_an_invisible_server_cannot_be_opened_through_the_address(): void
    {
        $user = User::factory()->create();
        $hidden = Server::factory()->create(['notes' => 'BASKASININ NOTU']);

        app(UserPermissionResolver::class)
            ->syncResource($user, 'servers', [], ['view' => [Server::factory()->create()->id]]);

        $this->actingAs($user->fresh())
            ->get(route('servers.index', ['server' => $hidden->id]))
            ->assertOk()
            ->assertDontSee('BASKASININ NOTU');
    }

    public function test_the_detail_never_prints_the_stored_password(): void
    {
        $server = Server::factory()->create(['connection_password' => 'asla-gorunmemeli']);

        $this->actingAs($this->operator())
            ->get(route('servers.index', ['server' => $server->uuid]))
            ->assertOk()
            ->assertDontSee('asla-gorunmemeli')
            ->assertSee('Kayıtlı');
    }
}
