<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Jobs\SyncSwitchNetconfJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommit;

/**
 * Cihazı eski bir commit'e döndürür.
 *
 * Bu, panelin en geniş etkili yazma yoludur: geri alma cihazın TAMAMINI
 * eski hâline döndürür — panelin yönetmediği satırlar da dahil. Yine de
 * aynı güvenlik zincirinden geçer (fark, commit check, geri alınabilir
 * commit, ayrı oturumda onay), yani yönetim erişimini koparan bir geri alma
 * kendiliğinden geri döner.
 *
 * İNDEKS PANELDEN GELMEZ. Junos'ta rollback numarası sıradır ve her yeni
 * commit'te bir kayar; ekran açıkken cihaza başka biri commit atarsa,
 * kullanıcının gördüğü satır ile o numaranın gösterdiği commit ayrışır ve
 * yanlış yapılandırma geri yüklenir. Bu yüzden hedef commit KİMLİĞİYLE
 * seçilir, indeks yazmadan hemen önce cihazdan yeniden okunur ve eşleşme
 * bulunamazsa işlem hiç yapılmaz.
 *
 * Okumanın YERİ de önemli: indeks yazma oturumunun içinde, switch kilidi
 * alınmış ve aday kopya açıkken çözülür. Ayrı bir oturumda okunsaydı iki
 * oturum arasında saniyelerce pencere kalır ve o pencerede CLI'dan atılan
 * tek bir commit numarayı kaydırmaya yeterdi.
 */
class CommitRollback
{
    public function __construct(
        private NetconfClient $client,
        private CommitHistoryReader $history,
        private ConfigurationWriter $writer,
    ) {}

    /**
     * Geri almanın ne değiştireceği; commit ATILMAZ.
     *
     * @return array{diff: string, warnings: list<string>, index: int}
     *
     * @throws NetconfException
     */
    public function preview(NetworkSwitch $switch, SwitchCommit $commit): array
    {
        $index = $this->currentIndex($switch, $commit);

        if ($index === 0) {
            return ['diff' => '', 'warnings' => [], 'index' => 0];
        }

        return [...$this->writer->previewRollback($switch, $index), 'index' => $index];
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch, SwitchCommit $commit): array
    {
        // Cihaz zaten o commit'teyse yükleme yapılmaz; bunu bilmek için
        // indeksi bir kez burada da okumak gerekiyor.
        if ($this->currentIndex($switch, $commit) === 0) {
            return ['diff' => '', 'committed' => false, 'warnings' => []];
        }

        $result = $this->writer->applyRollback(
            $switch,
            fn (NetconfSession $session): int => $this->indexIn($session, $commit),
            sprintf('Geri alindi: commit %s', $commit->committed_at?->format('Y-m-d H:i:s') ?? $index),
        );

        if ($result['committed']) {
            /*
             * Geri alma her şeyi etkiler; envanterin tamamı yeniden okunur.
             * Yazma yolunun kendi geri okuması yalnızca dokunduğu nesneyi
             * tazeler, burada dokunulan nesne "hepsi"dir.
             */
            SyncSwitchNetconfJob::dispatch($switch->id);
        }

        return $result;
    }

    /**
     * Hedef commit'in CİHAZDAKİ güncel rollback indeksi.
     *
     * Eşleşme zaman damgasıyla kurulur: cihaz saniye çözünürlüğünde verir ve
     * aynı saniyede iki commit olmaz.
     *
     * @throws NetconfException
     */
    private function currentIndex(NetworkSwitch $switch, SwitchCommit $commit): int
    {
        return $this->client->run(
            $switch,
            fn (NetconfSession $session): int => $this->indexIn($session, $commit),
        );
    }

    /**
     * Aynı soru, AÇIK BİR OTURUMDA. Yazma yolu bunu kendi oturumunun içinde
     * çağırır; kilit alınmışken okunan indeksle yüklenen indeks aynı olur.
     *
     * @throws NetconfException
     */
    private function indexIn(NetconfSession $session, SwitchCommit $commit): int
    {
        $target = $commit->committed_at?->format('Y-m-d H:i:s');

        if ($target === null) {
            throw new NetconfException('Commit kaydında zaman bilgisi yok; geri alma yapılamaz.');
        }

        foreach ($this->history->commits($session) as $row) {
            if ($row['committed_at']?->format('Y-m-d H:i:s') === $target) {
                return $row['sequence_number'];
            }
        }

        throw new NetconfException(
            'Bu commit cihazın geçmişinde bulunamadı; aradan yeni commitler geçmiş olabilir. '
            .'Listeyi yenileyip tekrar deneyin.'
        );
    }
}
