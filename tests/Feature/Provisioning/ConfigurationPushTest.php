<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use App\Enums\CredentialProtocol;
use App\Models\ConfigurationPush;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use App\Services\Provisioning\ConfigurationPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihaza tam config yükleme.
 *
 * En geniş etkili yazma yolu budur: "tamamen değiştir" seçeneğiyle cihazdaki
 * yapılandırmanın tamamı silinip yerine gönderilen metin konur. Yetkisi
 * ayrıdır ve kayıt bazında verilir.
 */
final class ConfigurationPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_yetkisiz_kullanici_config_yukleyemez(): void
    {
        $switch = $this->switch();
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        $this->actingAs($user->fresh())
            ->post(route('provisioning.store'), $this->payload($switch))
            ->assertForbidden();
    }

    public function test_yetkili_kullanici_config_yukler(): void
    {
        $switch = $this->switch();
        $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('configuration_pushes', [
            'switch_id' => $switch->id,
            'mode' => 'set',
            'source' => 'manual',
            'committed' => true,
        ]);
    }

    public function test_set_satirlari_secimden_bagimsiz_set_olarak_yuklenir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch, mode: 'override'))
            ->assertRedirect();

        /*
         * "override" seçilmiş olsa da metin set satırlarından oluşuyor;
         * Junos onları ancak action="set" ile kabul eder.
         */
        $this->assertTrue(
            collect($device->written)->contains(fn (string $rpc): bool => str_contains($rpc, 'action="set"')),
        );

        $this->assertDatabaseHas('configuration_pushes', ['mode' => 'set']);
    }

    public function test_hiyerarsik_metinde_secilen_bicim_uygulanir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload(
                $switch,
                body: "system {\n    host-name yeni;\n}",
                mode: 'override',
            ))
            ->assertRedirect();

        $this->assertTrue(
            collect($device->written)->contains(fn (string $rpc): bool => str_contains($rpc, 'action="override"')),
        );
    }

    public function test_onizleme_commit_atmaz(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch, step: 'preview'))
            ->assertRedirect()
            ->assertSessionHas('preview');

        $this->assertFalse(
            collect($device->written)->contains(fn (string $rpc): bool => str_contains($rpc, 'commit-configuration')),
        );

        $this->assertDatabaseCount('configuration_pushes', 0);
    }

    /**
     * Önizleme cihazın SIRLARINI ekrana basmaz.
     *
     * Yüklenecek metnin cihazdaki karşılığı bir fark üretir ve o fark
     * cihazın kendi sırlarını taşır: encrypted-password "$9$...",
     * authentication-key, community adları. Junos'un $9$ biçimi geri
     * çevrilebilir — düz metin kadar hassastır.
     */
    public function test_onizleme_cihaz_sirlarini_maskeler(): void
    {
        $switch = $this->switch();

        $this->app->instance(
            NetconfTransport::class,
            new FakeJunosDevice(
                diff: '[edit snmp]'."\n".'+  community "gizlicommunity" { authorization read-only; }',
            ),
        );

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch, step: 'preview'))
            ->assertRedirect()
            ->assertSessionHas('preview');

        $diff = session('preview')['diff'] ?? '';

        $this->assertNotSame('', $diff);
        $this->assertStringNotContainsString('gizlicommunity', $diff);
        $this->assertStringContainsString('••••', $diff);
    }

    public function test_dis_cihaza_yalnizca_yonetici_yazar(): void
    {
        $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), [
                'target' => 'external',
                'host' => '10.9.9.9',
                'username' => 'admin',
                'password' => 'gizli',
                'source' => 'manual',
                'body' => 'set system host-name disarida',
                'mode' => 'merge',
                'step' => 'apply',
            ])
            ->assertForbidden();
    }

    public function test_set_metni_taniyan_kural(): void
    {
        $pusher = app(ConfigurationPusher::class);

        $this->assertSame('set', $pusher->styleOf("set system host-name a\nset system services ssh"));
        $this->assertSame('set', $pusher->styleOf("# yorum\nset system services ssh"));
        $this->assertSame('merge', $pusher->styleOf("system {\n host-name a;\n}"));
        $this->assertSame('merge', $pusher->styleOf(''));
    }

    /**
     * Junos'un metin yüklemesindeki iki katı kuralı; ikisi de cihazda
     * doğrulandı (jn32-test, 12.3R12.4):
     *
     *   - "set" satırları <configuration-set> içinde gider,
     *   - CDATA kullanılmaz; metin XML kaçışıyla gönderilir.
     */
    public function test_set_satirlari_configuration_set_icinde_gider(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch, 'set system host-name deneme'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('action="set"', $written);
        $this->assertStringContainsString('<configuration-set>', $written);
        $this->assertStringNotContainsString('<![CDATA[', $written);
    }

    public function test_hiyerarsik_metin_configuration_text_icinde_gider(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch, "system {\n    host-name deneme;\n}"))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<configuration-text>', $written);
        $this->assertStringNotContainsString('<![CDATA[', $written);
    }

    /**
     * Gövde KULLANICIDAN gelir: XML özel karakterleri belgeyi bozmamalı.
     */
    public function test_config_metnindeki_xml_karakterleri_kacirilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->provisioner())
            ->post(route('provisioning.store'), $this->payload($switch, 'set system host-name "a&b<c>"'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('a&amp;b&lt;c&gt;', $written);
        // Ham karakter belgeye sızmamalı.
        $this->assertStringNotContainsString('host-name "a&b', $written);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        NetworkSwitch $switch,
        string $body = 'set system host-name deneme',
        string $mode = 'merge',
        string $step = 'apply',
    ): array {
        return [
            'target' => 'switch',
            'switch' => $switch->uuid,
            'source' => 'manual',
            'body' => $body,
            'mode' => $mode,
            'step' => $step,
        ];
    }

    private function provisioner(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'provision']);

        return $user->fresh();
    }

    /**
     * Ekranın cihaz listesi süzülüyordu ama GEÇMİŞ tablosu süzülmüyordu:
     * bir switche yükleme yetkisi olan, bütün cihazların ne zaman kim
     * tarafından yazıldığını görebiliyordu.
     */
    public function test_gecmis_yalnizca_erisilebilen_cihazlari_gosterir(): void
    {
        $visible = $this->switch();
        $hidden = NetworkSwitch::factory()->create(['hostname' => 'baska-switch']);

        $this->push($visible->id, 'jn32-test');
        $this->push($hidden->id, 'baska-switch');

        // Yetki yalnızca TEK cihaza verilir; wildcard yok.
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', [], [
            'view' => [$visible->id],
            'provision' => [$visible->id],
        ]);

        $this->actingAs($user->fresh())
            ->get(route('provisioning.create'))
            ->assertOk()
            ->assertSee('jn32-test')
            ->assertDontSee('baska-switch');
    }

    /**
     * Envanterde olmayan cihaza yalnızca yönetici yazabilir; geçmişini de
     * yalnızca yönetici görür.
     */
    public function test_dis_cihaz_gecmisi_yalnizca_yoneticiye_gorunur(): void
    {
        $switch = $this->switch();
        $this->push(null, 'envanter-disi-cihaz');

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', [], [
            'view' => [$switch->id],
            'provision' => [$switch->id],
        ]);

        $this->actingAs($user->fresh())
            ->get(route('provisioning.create'))
            ->assertOk()
            ->assertDontSee('envanter-disi-cihaz');
    }

    private function push(?int $switchId, string $label): ConfigurationPush
    {
        return ConfigurationPush::create([
            'switch_id' => $switchId,
            'target_host' => '198.18.20.195',
            'target_label' => $label,
            'mode' => 'merge',
            'source' => 'manual',
            'body' => 'set system host-name x',
            'diff' => '[edit system]',
            'committed' => true,
            'warnings' => [],
        ]);
    }

    /**
     * POLICY VE SORGU AYNI ŞEYİ SÖYLER.
     *
     * Aynı kuralın iki yolu var: liste sorgusu (scopeVisibleTo) ve tekil
     * kayıt kontrolü (ConfigurationPushPolicy::view). Ayrıştıklarında listede
     * görünen bir kaydın detayı açılmaz — ya da tersi, listede olmayan bir
     * kayıt açılır. Bu test ikisini birbirine bağlar.
     */
    public function test_policy_ve_sorgu_ayni_seyi_soyler(): void
    {
        $visible = $this->switch();
        $hidden = NetworkSwitch::factory()->create(['hostname' => 'gizli-sw', 'management_ip' => '198.18.20.196']);

        $user = User::factory()->create();
        /* Cihazı görme ve ona yazma yetkisi birlikte verilir; policy ikisini de arar. */
        app(UserPermissionResolver::class)->syncResource($user, 'switches', [], [
            'view' => [$visible->id],
            'provision' => [$visible->id],
        ]);

        $records = collect([$visible, $hidden, null])->map(fn (?NetworkSwitch $sw): ConfigurationPush => ConfigurationPush::query()->create([
            'user_id' => $user->id,
            'switch_id' => $sw?->id,
            'target_host' => $sw?->management_ip ?? '10.9.9.9',
            'target_label' => $sw?->hostname ?? 'envanter dışı',
            'mode' => 'merge',
            'source' => 'form',
            'body' => 'set system host-name deneme',
            'committed' => true,
        ]));

        $user = $user->fresh();
        $list = ConfigurationPush::query()->visibleTo($user)->pluck('id');

        foreach ($records as $record) {
            $this->assertSame(
                $list->contains($record->id),
                $user->can('view', $record),
                "Listede görünmekle detayın açılması aynı yanıtı vermeli: {$record->target_label}",
            );
        }

        /* Kural gerçekten süzüyor: üç kayıttan yalnızca biri görünüyor. */
        $this->assertCount(1, $list);
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'jn32-test', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
