<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\SwitchWriteStatus;
use App\Jobs\ApplySwitchWriteJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchWriteJob;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cihaza yazan uçların ortak yanıtı.
 *
 * Üç şey her yazma ucunda aynıdır ve ayrı ayrı yazıldığında kaçınılmaz olarak
 * ayrışır:
 *
 *  1. Fark boşsa commit atılmamıştır; kullanıcıya "yazıldı" DENMEZ. Cihaz
 *     zaten istenen durumdaydı.
 *  2. Yazılan şey etkinlik kaydına cihazın kendi farkıyla geçer — panelin
 *     niyeti değil, cihazın kabul ettiği değişiklik. Farktaki sırlar (oturum
 *     anahtarı, community, parola) maskelenir: kayıt silinemez ve dışa
 *     aktarılabilir, sır oraya girmemeli.
 *  3. Kullanıcı geldiği listeye döner.
 *
 * Yetkilendirme: bu bir controller değil, controller'ların paylaştığı bir
 * trait'tir. Yazma yetkisi onu kullanan her controller'da durur
 * (can:configure,switch); burada bir uç yoktur.
 */
trait RespondsToDeviceWrite
{
    abstract protected function recorder(): ActivityRecorder;

    /**
     * Yazma bittiğinde dönülecek rota adı.
     */
    abstract protected function indexRoute(): string;

    /**
     * Cihaz yazmasını KUYRUĞA alır ve hemen döner.
     *
     * Yazma ELS switchte bir dakikaya yaklaşabilir (iki gerçek commit, iki
     * oturum); HTTP isteği o kadar tutulursa sayfa kilitlenir. İstek
     * beklemez, kullanıcı sonucu ekranda oluşan işlem satırından izler.
     *
     * Doğrulama SENKRON kalır: Form Request hatası anında görünür. Kuyruğa
     * yalnızca cihaza gerçekten gidecek olan iş alınır.
     *
     * @param  class-string  $configurator  cihaza yazan servis
     * @param  string  $method  o servisin metodu; ilk argümanı switch'tir
     * @param  array<int, mixed>  $arguments  switch'ten SONRAKİ argümanlar
     */
    protected function queueWrite(
        NetworkSwitch $switch,
        string $configurator,
        string $method,
        array $arguments,
        string $message,
    ): RedirectResponse {
        $job = $this->queueDeviceWrite($switch, $configurator, $method, $arguments, $message);

        return $this->backToSwitch($switch, "{$message}: cihaza yazılıyor.")
            ->with('write_job_url', route('switches.write-jobs.show', [$switch, $job]));
    }

    /**
     * Yazmayı kuyruğa alır ve İŞİ döndürür.
     *
     * Kendi adresine dönen ekranlar (port ekranı seçili portu korur) yönlendirmeyi
     * kendileri kurar; ortak yol onlara uymaz.
     *
     * @param  class-string  $configurator
     * @param  array<int, mixed>  $arguments
     */
    protected function queueDeviceWrite(
        NetworkSwitch $switch,
        string $configurator,
        string $method,
        array $arguments,
        string $message,
    ): SwitchWriteJob {
        /*
         * İsteğin kimliği İŞLE BİRLİKTE taşınır: etkinlik kaydı cihazın
         * kabul ettiği FARKLA yazılmalı ve o ancak iş bitince biliniyor.
         * Kuyrukta istek olmadığı için kullanıcı, gerekçe ve IP burada
         * yakalanır — yoksa kayıt sahipsiz kalırdı.
         */
        $subject = $this->writeSubject($arguments);

        $this->guardPendingWrite($subject);

        $job = SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'user_id' => Auth::id(),
            'summary' => $message,
            'status' => SwitchWriteStatus::Queued,
            'reason' => request()->input('reason'),
            'ip_address' => request()->ip(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        ApplySwitchWriteJob::dispatch($job, $switch, $configurator, $method, $arguments);

        return $job;
    }

    /**
     * Yazmanın HEDEFİ: argümanlar arasındaki ilk kayıt.
     *
     * Çağrı yerlerinin hepsi hedefi ilk argüman olarak veriyor
     * (apply($switch, $port, ...), delete($switch, $vlan)). Bunu türetmek,
     * her çağrıya ayrıca hedef yazdırmaktan iyidir: eklenen
     * her yeni uç kendiliğinden doğru davranır.
     *
     * Yeni kayıt oluşturan uçlarda hedef YOKTUR (henüz var olmayan bir şey
     * kilitlenemez) ve orada kilit de gerekmez.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function writeSubject(array $arguments): ?Model
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Model && $argument->exists) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Aynı kayda ikinci bir yazma, birincisi bitmeden KABUL EDİLMEZ.
     *
     * Kuyruk ikisini sırayla çalıştırırdı ama ikincisi birincinin sonucunu
     * GÖRMEDEN hazırlanmış olurdu: kullanıcı henüz gerçekleşmemiş bir duruma
     * bakarak karar verir ve "gönderilmeyen alan silinir" kuralı yüzünden
     * birinci yazmanın etkisi sessizce geri alınabilir.
     *
     * @throws ValidationException
     */
    private function guardPendingWrite(?Model $subject): void
    {
        if ($subject === null) {
            return;
        }

        $lasting = SwitchWriteJob::query()->pending()->forSubject($subject)->first();

        if ($lasting === null) {
            return;
        }

        throw ValidationException::withMessages([
            'write_job' => "Bu kayıt üzerinde bir işlem sürüyor ({$lasting->summary}); "
                .'bitmeden yeni bir değişiklik gönderilemez.',
        ]);
    }

    protected function backToSwitch(NetworkSwitch $switch, string $status): RedirectResponse
    {
        return redirect()->route($this->indexRoute(), $switch)->with('status', $status);
    }
}
