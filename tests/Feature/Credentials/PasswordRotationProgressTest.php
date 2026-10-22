<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProtocol;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Jobs\RotateSwitchPasswordJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPasswordRotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Süren parola turlarının ekrandaki ilerlemesi.
 *
 * Tur kuyrukta çalışıyor ve bir switchte dört oturum ile üç commit sürüyor;
 * toplu turda onlarca cihaz aynı anda sırada bekliyor. Ekran o süre boyunca
 * yalnızca "kuyruğa alındı" derse hangi cihazın nerede olduğu ancak sayfa
 * yenilenince görülür ve kullanıcı düğmeye ikinci kez basar.
 *
 * Çubuğun tek görevi DOĞRU söylemek: ilerleme sunucudaki aşamadan gelir.
 */
final class PasswordRotationProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_suren_tur_asamayi_bildirir(): void
    {
        $switch = $this->switch();
        $this->round($switch, PasswordRotationStage::PasswordChanged);

        $this->actingAs($this->admin())
            ->getJson(route('settings.passwords.progress'))
            ->assertOk()
            ->assertJson([
                'calisiyor' => true,
                'cihazlar' => [
                    $switch->uuid => ['adim' => 4, 'toplam' => 6, 'yuzde' => 67],
                ],
            ]);
    }

    public function test_biten_tur_ilerlemede_gorunmez(): void
    {
        $switch = $this->switch();
        $this->round($switch, PasswordRotationStage::RecoveryRemoved, PasswordRotationStatus::Succeeded);

        $this->actingAs($this->admin())
            ->getJson(route('settings.passwords.progress'))
            ->assertOk()
            ->assertJson(['calisiyor' => false]);
    }

    /**
     * SIRAYA ALINDI ama işçi henüz başlamadı.
     *
     * Tur kaydı SwitchPasswordRotator içinde açılıyor; düğmeye basılan an ile
     * o an arasında veritabanında hiçbir iz yok. Ekranın o saniyelerde "bir
     * şey olmuyor" demesi, kullanıcıyı düğmeye ikinci kez basmaya iterdi.
     */
    public function test_kuyruga_alinan_tur_de_suruyor_sayilir(): void
    {
        Queue::fake();

        $switch = $this->switch();

        RotateSwitchPasswordJob::enqueue($switch->id);

        $this->actingAs($this->admin())
            ->getJson(route('settings.passwords.progress'))
            ->assertOk()
            ->assertJson([
                'calisiyor' => true,
                'cihazlar' => [
                    $switch->uuid => ['adim' => 0, 'durum' => 'pending'],
                ],
            ]);
    }

    /**
     * Uç PAROLA SIZDIRMAZ.
     *
     * Yeni parola ve kurtarma parolası turun en hassas alanları; ilerleme
     * çubuğunun hiçbirine ihtiyacı yok.
     */
    public function test_uc_parola_dondurmez(): void
    {
        $switch = $this->switch();

        $this->round($switch, PasswordRotationStage::PasswordChanged)->forceFill([
            'new_password' => 'yeni-gizli-parola',
            'recovery_password' => 'kurtarma-gizli-parola',
            'recovery_username' => 'nsm-kurtarma',
        ])->save();

        $body = $this->actingAs($this->admin())
            ->getJson(route('settings.passwords.progress'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('yeni-gizli-parola', $body);
        $this->assertStringNotContainsString('kurtarma-gizli-parola', $body);
    }

    /**
     * Ekranda çubuk çizilir ve o satırdaki düğmeler gizlenir.
     *
     * YÜZDE `style` DEĞİL `data-start` İLE BASILIR. Sunucu "width: 67%"
     * gibi bir stil basarsa sunucu sınaması geçer ama TARAYICI onu
     * uygulamaz: içerik CSP'si satır içi stil özniteliklerini engeller
     * (Chromium: "Applying inline style violates ... style-src") ve çubuk
     * tarayıcıda boş açılır.
     *
     * Değeri JS uygular (rotation-progress.js); CSSOM üzerinden yazmak
     * CSP'ye takılmaz.
     */
    public function test_ekranda_cubuk_cizilir_ve_dugme_gizlenir(): void
    {
        $switch = $this->switch();
        $this->round($switch, PasswordRotationStage::PasswordChanged);

        $response = $this->actingAs($this->admin())
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('data-progress-device="'.$switch->uuid.'"', false)
            ->assertSee('role="progressbar"', false)
            ->assertSee('data-start="67"', false)
            ->assertSee('Yeni parola deneniyor', false);

        /*
         * Tur sürerken "Değiştir" gösterilmemeli: ikinci iş, işin tekilliğine
         * takılıp sessizce düşerdi ve kullanıcı bunu hata sanardı.
         */
        $response->assertDontSee('form="parola-'.$switch->uuid.'"', false);
    }

    /** Süren tur yoksa sayfa sunucuya hiç soru sormaz. */
    public function test_suren_tur_yokken_yoklama_adresi_basilmaz(): void
    {
        $this->switch();

        $this->actingAs($this->admin())
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertDontSee('data-progress-address', false);
    }

    public function test_yetkisiz_kullanici_asamayi_goremez(): void
    {
        $this->switch();

        $this->actingAs(User::factory()->create())
            ->getJson(route('settings.passwords.progress'))
            ->assertForbidden();
    }

    private function round(
        NetworkSwitch $switch,
        PasswordRotationStage $stage,
        PasswordRotationStatus $status = PasswordRotationStatus::Running,
    ): SwitchPasswordRotation {
        return $switch->passwordRotations()->create([
            'status' => $status,
            'stage' => $stage,
            'started_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function switch(string $hostname = 'test1', string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => $hostname, 'management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'eskiparola',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
