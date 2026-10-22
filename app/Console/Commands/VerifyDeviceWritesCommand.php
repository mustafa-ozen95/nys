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

        $writer = new CheckOnlyWriter(
            app(NetconfClient::class),
            app(CommitLog::class),
            app(SwitchWriteLock::class),
            app(DeviceOperationRecorder::class),
        );

        app()->instance(ConfigurationWriter::class, $writer);

        $filter = (string) $this->option('only');
        $count = ['GECTI' => 0, 'RED' => 0, 'ATLANDI' => 0];

        foreach (app(DeviceWriteScenarios::class)->all($switch) as $name => $scenario) {
            if ($filter !== '' && ! str_contains($name, $filter)) {
                continue;
            }

            $writer->last = null;

            /*
             * Bir senaryo cihazın REDDETMESİNİ ölçüyor olabilir; o zaman
             * kırmızı doğru sonuçtur ve yeşil BULGUDUR (bkz. Scenario).
             */
            $expectedRed = $scenario instanceof Scenario;
            $run = $expectedRed ? $scenario->run : $scenario;

            try {
                $run();
                $result = $writer->last ?? 'ATLANDI (cihaza gitmedi)';

                if ($expectedRed) {
                    $result = str_starts_with((string) $result, 'GECTI')
                        ? 'BULGU: cihaz reddetmeliydi — '.$scenario->reason
                        : 'BEKLENEN RED';
                }
            } catch (ScenarioNotApplicable $e) {
                /*
                 * Ön koşulu olmayan senaryo cihaza HİÇ gitmedi. Bunu panel
                 * hatasından ayırmak raporun tek işi: karışırsa okuyan kişi
                 * olmayan bir hatayı kovalar (bkz. ScenarioNotApplicable).
                 */
                $result = 'ATLANDI (ön koşul): '.$e->getMessage();
            } catch (Throwable $e) {
                $result = 'HATA (panel): '.trim(preg_replace('/\s+/', ' ', $e->getMessage()));
            }

            $count[$this->bucket($result)]++;

            $this->line(str_pad($name, 42).' '.$result);

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
            $count['GECTI'],
            $count['RED'],
            $count['ATLANDI'],
        ));

        if ($count['RED'] > 0) {
            $this->warn('Kırmızı satır kaldıysa ön koşul değil PANEL sorunudur; her biri incelenir.');
        }

        return $count['RED'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Satırı üç kovadan birine koyar. ATLANDI ile RED'in ayrı sayılması
     * raporun bütün değeri: ikisi karışınca panel hatası olmayan yerde
     * hata aranır.
     */
    private function bucket(string $result): string
    {
        return match (true) {
            str_starts_with($result, 'GECTI'),
            str_starts_with($result, 'BEKLENEN RED') => 'GECTI',
            str_starts_with($result, 'ATLANDI') => 'ATLANDI',
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
    public ?string $last = null;

    public function __construct(
        private NetconfClient $client,
        CommitLog $log,
        SwitchWriteLock $lock,
        DeviceOperationRecorder $operations,
    ) {
        parent::__construct($client, $log, $lock, $operations);
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
        bool $revertible = true,
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
            $this->client->run($switch, function (NetconfSession $session) use ($documents, $format, $mode, $rollback): void {
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
                    $documents = $documents instanceof Closure ? $documents($session) : $documents;

                    foreach ($documents as $document) {
                        // Yük biçimi ÜRETİM koduyla aynı yerden gelir;
                        // ayrışırsa denetim yanlış şeyi doğrular.
                        $load = $format === 'text'
                            ? $this->textPayload($document, $mode)
                            : $document;

                        $session->rpc(sprintf(
                            '<load-configuration action="%s" format="%s">%s</load-configuration>',
                            $mode,
                            $format,
                            $load,
                        ));
                    }

                    $session->rpc('<commit-configuration><check/></commit-configuration>');
                } finally {
                    $session->rpc('<close-configuration/>');
                }
            });

            $this->last = 'GECTI';
        } catch (Throwable $e) {
            $this->last = 'HATA (cihaz): '.trim(preg_replace('/\s+/', ' ', $e->getMessage()));
        }

        // committed=false: çağıran servis kayda geçmez, geri okuma yapmaz.
        return ['diff' => '', 'committed' => false, 'warnings' => []];
    }
}
