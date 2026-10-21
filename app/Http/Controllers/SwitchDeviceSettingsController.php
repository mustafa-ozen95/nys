<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\DeviceSettingsRequest;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Netconf\DeviceClockSynchronizer;
use App\Services\Netconf\DeviceSettingsWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cihazın kendi ayarları: adı, saat dilimi, isim/zaman/günlük sunucuları.
 *
 * Envanterdeki hostname de aynı istekte güncellenir: cihazın adı değişip
 * panelinki değişmeseydi iki isim ayrışır ve hangisinin doğru olduğu
 * belirsiz kalırdı.
 */
class SwitchDeviceSettingsController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private DeviceSettingsWriter $writer,
        private DeviceClockSynchronizer $clocks,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:configure,switch')];
    }

    /**
     * Cihazın saatini NTP sunucusundan HEMEN düzeltir.
     *
     * "ntp server" tek başına yetmiyor: ntpd, fark 1000 saniyeyi aşınca
     * saati adımlamayı reddediyor. Saati yıllarca geride kalmış bir cihaz
     * (pil bitmiş, NTP'siz kalmış) sunucu tanımlı olsa da kendiliğinden
     * toparlamaz — düzeltmenin tek yolu bu komut ya da açılışta çalışan
     * "boot-server".
     *
     * Yapılandırma değişmediği için yazma kuyruğundan geçmez; işlem birkaç
     * saniye sürer ve sonucu kullanıcı hemen görmeli.
     */
    public function clock(NetworkSwitch $switch): RedirectResponse
    {
        $sonuc = $this->clocks->synchronize($switch);

        $this->activity->record(
            $switch,
            'configured',
            "{$switch->hostname} · cihaz saati NTP ile eşitlendi",
            ActivityChannel::Config,
        );

        return $this->backToSwitch($switch, 'Cihazın saati eşitlendi.');
    }

    public function __invoke(DeviceSettingsRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        /*
         * Envanterdeki ad CİHAZA yazıldıktan sonra güncellenir; yazma
         * kuyrukta olduğu için bunu iş yapar (bkz. DeviceSettingsWriter).
         * İstek burada güncelleseydi, cihaz reddettiğinde panel kendini
         * cihazdan farklı bir adla gösterirdi.
         */
        return $this->queueWrite(
            $switch,
            DeviceSettingsWriter::class,
            'apply',
            [$request->settings()],
            'cihaz ayarları yazıldı',
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.settings';
    }
}
