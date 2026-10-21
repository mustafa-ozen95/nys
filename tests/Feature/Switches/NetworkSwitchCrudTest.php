<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchCredential;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NetworkSwitchCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'sw-test-9',
            'management_ip' => '198.18.30.99',
            'uplink_ip' => '10.0.0.99',
            'model' => 'EX2200-48P',
            'role' => 'access',
            'location' => 'plus',
            'ssh_username' => 'admin',
            'ssh_password' => 'cok-gizli-parola',
            'ssh_port' => 22,
            'snmp_community' => 'gizli-community',
            'snmp_port' => 161,
        ], $overrides);
    }

    private function operator(array $actions = ['view', 'create', 'update', 'delete']): User
    {
        $user = User::factory()->create();

        app(UserPermissionResolver::class)->syncResource($user, 'switches', $actions);

        return $user->fresh();
    }

    public function test_the_list_is_closed_to_a_user_without_permissions(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('switches.index'))
            ->assertForbidden();
    }

    public function test_the_list_shows_only_permitted_switches(): void
    {
        $user = User::factory()->create();
        $visible = NetworkSwitch::factory()->create(['hostname' => 'GORUNUR']);
        NetworkSwitch::factory()->create(['hostname' => 'GIZLI']);

        app(UserPermissionResolver::class)
            ->syncResource($user, 'switches', [], ['view' => [$visible->id]]);

        $this->actingAs($user->fresh())
            ->get(route('switches.index'))
            ->assertOk()
            ->assertSee('GORUNUR')
            ->assertDontSee('GIZLI');
    }

    public function test_the_list_can_be_filtered(): void
    {
        NetworkSwitch::factory()->create(['hostname' => 'ARANAN', 'management_ip' => '10.1.1.1']);
        NetworkSwitch::factory()->create(['hostname' => 'DIGER', 'management_ip' => '10.2.2.2']);

        $this->actingAs($this->operator())
            ->get(route('switches.index', ['q' => 'ARANAN']))
            ->assertOk()
            ->assertSee('ARANAN')
            ->assertDontSee('DIGER');
    }

    public function test_a_switch_can_be_created_with_credentials(): void
    {
        $this->actingAs($this->operator())
            ->post(route('switches.store'), $this->payload())
            ->assertRedirect();

        $switch = NetworkSwitch::query()->firstWhere('hostname', 'sw-test-9');

        $this->assertNotNull($switch);
        $this->assertDatabaseHas('switches', ['hostname' => 'sw-test-9', 'management_ip' => '198.18.30.99']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'created', 'subject_id' => $switch->id]);
        $this->assertCount(2, $switch->credentials);
    }

    public function test_credentials_are_written_encrypted(): void
    {
        $this->actingAs($this->operator())->post(route('switches.store'), $this->payload());

        $stored = DB::table('switch_credentials')
            ->where('protocol', CredentialProtocol::Ssh->value)
            ->value('password');

        $this->assertNotSame('cok-gizli-parola', $stored);
        $this->assertSame('cok-gizli-parola', Crypt::decryptString($stored));
    }

    public function test_creating_requires_permission(): void
    {
        $this->actingAs($this->operator(['view']))
            ->post(route('switches.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_a_duplicate_hostname_is_rejected(): void
    {
        NetworkSwitch::factory()->create(['hostname' => 'sw-test-9']);

        $this->actingAs($this->operator())
            ->post(route('switches.store'), $this->payload())
            ->assertSessionHasErrors('hostname');
    }

    public function test_an_invalid_ip_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('switches.store'), $this->payload(['management_ip' => 'ip-degil']))
            ->assertSessionHasErrors('management_ip');
    }

    public function test_a_switch_can_be_updated(): void
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'ESKI']);
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'onceki-parola']);

        $this->actingAs($this->operator())
            ->put(route('switches.update', $switch), $this->payload([
                'hostname' => 'YENI',
                'management_ip' => $switch->management_ip,
                'ssh_password' => '',
            ]))
            ->assertRedirect(route('switches.show', $switch));

        $this->assertSame('YENI', $switch->fresh()->hostname);
    }

    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'onceki-parola']);

        $this->actingAs($this->operator())
            ->put(route('switches.update', $switch), $this->payload([
                'hostname' => $switch->hostname,
                'management_ip' => $switch->management_ip,
                'ssh_password' => '',
                'snmp_community' => '',
            ]));

        $this->assertSame('onceki-parola', $switch->fresh()->sshCredential->password);
    }

    /**
     * Yönetim IP'si değişince kayıtlı parola yeni adrese gider; parolayı
     * görme yetkisi olmayan kullanıcı adresi kendi dinleyicisine çeviremez.
     */
    public function test_changing_the_management_ip_requires_the_password_again(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.50']);
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'onceki-parola']);

        $this->actingAs($this->operator())
            ->put(route('switches.update', $switch), $this->payload([
                'hostname' => $switch->hostname,
                'management_ip' => '198.51.100.9',
                'ssh_password' => '',
                'snmp_community' => '',
            ]))
            ->assertSessionHasErrors('ssh_password');

        $this->assertSame('198.18.30.50', $switch->fresh()->management_ip);
    }

    public function test_changing_the_management_ip_with_the_password_re_entered_is_allowed(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.50']);
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'onceki-parola']);

        $this->actingAs($this->operator())
            ->put(route('switches.update', $switch), $this->payload([
                'hostname' => $switch->hostname,
                'management_ip' => '198.18.30.51',
                'ssh_password' => 'yeniden-girilen-parola',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('198.18.30.51', $switch->fresh()->management_ip);
    }

    public function test_the_secret_holder_may_change_the_management_ip_with_blank_password(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.50']);
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'onceki-parola']);

        $this->actingAs($this->operator(['view', 'update', 'secret']))
            ->put(route('switches.update', $switch), $this->payload([
                'hostname' => $switch->hostname,
                'management_ip' => '198.18.30.51',
                'ssh_password' => '',
                'snmp_community' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('198.18.30.51', $switch->fresh()->management_ip);
    }

    public function test_a_switch_can_be_soft_deleted(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs($this->operator())
            ->delete(route('switches.destroy', $switch))
            ->assertRedirect(route('switches.index'));

        $this->assertSoftDeleted($switch);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted']);
    }

    public function test_deleting_requires_permission(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs($this->operator(['view']))
            ->delete(route('switches.destroy', $switch))
            ->assertForbidden();

        $this->assertNotSoftDeleted($switch);
    }

    public function test_the_detail_screen_never_prints_a_stored_secret(): void
    {
        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'asla-gorunmemeli']);

        $this->actingAs($this->operator())
            ->get(route('switches.show', $switch))
            ->assertOk()
            ->assertDontSee('asla-gorunmemeli');
    }

    public function test_the_detail_screen_does_not_run_extra_queries_per_relation(): void
    {
        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create();
        SwitchCredential::factory()->for($switch, 'switch')->snmp()->create();
        SwitchPort::factory()->count(12)->for($switch, 'switch')->create();

        $user = $this->operator();

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('switches.show', $switch))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        /*
         * Kullanıcı + yetki + switch + üç ilişki + erişim kaydı + adres
         * blokları.
         *
         * Erişim kaydı ve adres blokları SABİT maliyettir (biri zincirin
         * ucunu okur bir satır yazar, diğeri düzenleme formundaki adres
         * listelerini TEK okumada doldurur) ve ilişki sayısıyla büyümez;
         * testin asıl işi olan "ilişki başına ek sorgu" denetimi bozulmaz.
         * Bu sayı büyürse sebebi N+1'dir, kayıt değil.
         *
         * Rol de sabit bir sorgudur: isAdmin() her yetki kontrolünün ilk
         * sorusu ve User modelinde eager yükleniyor.
         */
        $this->assertLessThanOrEqual(11, $queries, "Beklenenden fazla sorgu: {$queries}");
    }
}
