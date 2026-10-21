<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NetworkSwitch;
use App\Services\Netconf\CommitLog;
use App\Services\Netconf\ConfigurationWriter;
use App\Services\Netconf\DeviceOperationRecorder;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\NetconfTarget;
use App\Services\Netconf\SwitchWriteLock;
use App\Support\Netconf\DeviceWriteScenarios;
use App\Support\Netconf\Scenario;
use App\Support\Netconf\ScenarioNotApplicable;
use Closure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Panelin CİHAZA YAZAN her yolunu gerçek switch üzerinde doğrular —
 * commit ATMADAN.
 *
 * Her yazma yolu belgelerini üretir, belgeler özel bir aday yapılandırmaya
 * yüklenir, cihaz "commit check" ile doğrular ve oturum kapanınca aday
 * atılır. Cihazda hiçbir iz kalmaz.
 *
 * Neden gerekli: panelin ürettiği Junos XML'i birim testlerde yalnızca
 * KENDİ beklentimizle karşılaştırılıyor. Cihazın kabul edip etmediğini
 * ancak cihaz söyler — "archive-sites delete" ile "allow + authentication-key"
 * hataları tam olarak böyle bulundu.
 *
 * Yetkilendirme: konsol komutudur, HTTP ucu yoktur. Yalnızca komut satırından
 * ve açıkça verilen switch üzerinde çalışır.
 */
class VerifyDeviceWritesCommand extends Command
{
    protected $signature = 'network:verify-writes
        {switch : Switch adı ya da yönetim IP’si}
        {--only= : Yalnızca adı bunu içeren senaryolar}';

    protected $description = 'Cihaza yazan bütün yolları commit atmadan gerçek switchte doğrular';

    public function handle(): int
    {
        $switch = NetworkSwitch::query()
            ->where('hostname', $this->argument('switch'))
            ->orWhere('management_ip', $this->argument('switch'))
            ->first();

        if ($switch === null) {
            $this->error('Switch bulunamadı.');

            return self::FAILURE;
        }

        $yazici = new CheckOnlyWriter(
            app(NetconfClient::class),
            app(CommitLog::class),
            app(SwitchWriteLock::class),
            app(DeviceOperationRecorder::class),
        );

        app()->instance(ConfigurationWriter::class, $yazici);

        $suzgec = (string) $this->option('only');
        $sayim = ['GECTI' => 0, 'RED' => 0, 'ATLANDI' => 0];

        foreach (app(DeviceWriteScenarios::class)->all($switch) as $ad => $senaryo) {
            if ($suzgec !== '' && ! str_contains($ad, $suzgec)) {
                continue;
            }

            $yazici->sonuncu = null;

            /*
             * Bir senaryo cihazın REDDETMESİNİ ölçüyor olabilir; o zaman
             * kırmızı doğru sonuçtur ve yeşil BULGUDUR (bkz. Scenario).
             */
            $beklenenRed = $senaryo instanceof Scenario;
            $calistir = $beklenenRed ? $senaryo->run : $senaryo;

            try {
                $calistir();
                $sonuc = $yazici->sonuncu ?? 'ATLANDI (cihaza gitmedi)';

                if ($beklenenRed) {
                    $sonuc = str_starts_with((string) $sonuc, 'GECTI')
                        ? 'BULGU: cihaz reddetmeliydi — '.$senaryo->reason
                        : 'BEKLENEN RED';
                }
            } catch (ScenarioNotApplicable $e) {
                /*
                 * Ön koşulu olmayan senaryo cihaza HİÇ gitmedi. Bunu panel
                 * hatasından ayırmak raporun tek işi: karışırsa okuyan kişi
                 * olmayan bir hatayı kovalar (bkz. ScenarioNotApplicable).
                 */
                $sonuc = 'ATLANDI (ön koşul): '.$e->getMessage();
            } catch (Throwable $e) {
                $sonuc = 'HATA (panel): '.trim(preg_replace('/\s+/', ' ', $e->getMessage()));
            }

            $sayim[$this->bucket($sonuc)]++;

            $this->line(str_pad($ad, 42).' '.$sonuc);

            /*
             * Cihaza nefes payı: her senaryo yeni bir SSH oturumu açıyor ve
             * arka arkaya elliye yakın oturum açıldığında Junos yenilerini
             * reddetmeye başlıyor ("Unable to request pty"). Panelin normal
             * kullanımında ulaşılmayan bir hız; yalnızca bu denetim için
             * yavaşlatılır.
             */
            usleep(400000);
        }

        $this->newLine();
        $this->line(sprintf(
            'GECTI %d · RED %d · ATLANDI %d',
            $sayim['GECTI'],
            $sayim['RED'],
            $sayim['ATLANDI'],
        ));

        if ($sayim['RED'] > 0) {
            $this->warn('Kırmızı satır kaldıysa ön koşul değil PANEL sorunudur; her biri incelenir.');
        }

        return $sayim['RED'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Satırı üç kovadan birine koyar. ATLANDI ile RED'in ayrı sayılması
     * raporun bütün değeri: ikisi karışınca panel hatası olmayan yerde
     * hata aranır.
     */
    private function bucket(string $sonuc): string
    {
        return match (true) {
            str_starts_with($sonuc, 'GECTI'),
            str_starts_with($sonuc, 'BEKLENEN RED') => 'GECTI',
            str_starts_with($sonuc, 'ATLANDI') => 'ATLANDI',
            default => 'RED',
        };
    }
}

/**
 * Commit atmayan yazıcı: yükler, doğrular, atar.
 *
 * ConfigurationWriter'ın yerine geçer; çağıran servisler farkı bilmez ve
 * ürettikleri belgeler olduğu gibi cihaza gider.
 */
final class CheckOnlyWriter extends ConfigurationWriter
{
    public ?string $sonuncu = null;

    public function __construct(
        private NetconfClient $istemci,
        CommitLog $log,
        SwitchWriteLock $lock,
        DeviceOperationRecorder $operations,
    ) {
        parent::__construct($istemci, $log, $lock, $operations);
    }

    public function apply(
        NetworkSwitch $switch,
        string|array|Closure $configuration,
        string $summary,
        ?Closure $afterConfirm = null,
    ): array {
        return $this->check($switch, is_string($configuration) ? [$configuration] : $configuration, 'xml', 'merge');
    }

    public function applyOn(
        NetconfTarget $target,
        string|array|Closure $configuration,
        string $summary,
        ?Closure $afterConfirm = null,
        bool $geriAlinabilir = true,
    ): array {
        $switch = NetworkSwitch::query()->find($target->switchId);

        return $switch === null
            ? ['diff' => '', 'committed' => false, 'warnings' => []]
            : $this->check($switch, is_string($configuration) ? [$configuration] : $configuration, 'xml', 'merge');
    }

    public function applyText(NetconfTarget $target, string $configuration, string $mode, string $summary): array
    {
        $switch = NetworkSwitch::query()->find($target->switchId);

        return $switch === null
            ? ['diff' => '', 'committed' => false, 'warnings' => []]
            : $this->check($switch, [$configuration], 'text', $mode);
    }

    public function applyRollback(
        NetworkSwitch $switch,
        int|Closure $index,
        string $summary,
        ?Closure $afterConfirm = null,
    ): array {
        return $this->check($switch, [], 'xml', 'merge', $index);
    }

    /**
     * @param  list<string>|Closure(NetconfSession): list<string>  $documents
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function check(NetworkSwitch $switch, array|Closure $documents, string $format, string $mode, int|Closure|null $rollback = null): array
    {
        try {
            $this->istemci->run($switch, function (NetconfSession $session) use ($documents, $format, $mode, $rollback): void {
                $session->rpc('<open-configuration><private/></open-configuration>');

                try {
                    if ($rollback !== null) {
                        // İndeks üretimde de oturumun içinde çözülüyor.
                        $index = $rollback instanceof Closure ? $rollback($session) : $rollback;

                        $session->rpc(sprintf('<load-configuration rollback="%d"/>', $index));
                    }

                    // Belgeler üretimde de oturumun içinde üretilebiliyor
                    // (bkz. ConfigurationWriter::apply); denetim aynı yoldan
                    // geçmezse doğruladığı belge cihaza gidenden başka olur.
                    $belgeler = $documents instanceof Closure ? $documents($session) : $documents;

                    foreach ($belgeler as $document) {
                        // Yük biçimi ÜRETİM koduyla aynı yerden gelir;
                        // ayrışırsa denetim yanlış şeyi doğrular.
                        $yuk = $format === 'text'
                            ? $this->textPayload($document, $mode)
                            : $document;

                        $session->rpc(sprintf(
                            '<load-configuration action="%s" format="%s">%s</load-configuration>',
                            $mode,
                            $format,
                            $yuk,
                        ));
                    }

                    $session->rpc('<commit-configuration><check/></commit-configuration>');
                } finally {
                    $session->rpc('<close-configuration/>');
                }
            });

            $this->sonuncu = 'GECTI';
        } catch (Throwable $e) {
            $this->sonuncu = 'HATA (cihaz): '.trim(preg_replace('/\s+/', ' ', $e->getMessage()));
        }

        // committed=false: çağıran servis kayda geçmez, geri okuma yapmaz.
        return ['diff' => '', 'committed' => false, 'warnings' => []];
    }
}
