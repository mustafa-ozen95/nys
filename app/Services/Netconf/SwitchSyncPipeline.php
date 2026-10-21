<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchCommit;
use App\Services\Capabilities\CapabilityProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cihazdan okunanları envantere yazan sıra.
 *
 * SIRA ANLAMLIDIR ve her adımın gerekçesi kendi yorumunda: prefix list'ler
 * filtrelerden önce, politikalar BGP'den önce okunur. Bu yüzden adımlar
 * gevşek bir "her servis kendi işini yapsın" kümesi değil, TEK bir yerde
 * duran bir dizi.
 *
 * Kuyruk işinden ayrı bir sınıftır: bağımlılıklar iş içinde birden çok
 * closure'da tek tek sayılırsa okunacak yeni bir şey eklemek birkaç ayrı
 * listeyi güncellemek demek olur. Bağımlılıklar yapıcıda, sıra da burada.
 *
 * YALNIZCA OKUMA yapılır; cihaza hiçbir şey yazılmaz.
 */
class SwitchSyncPipeline
{
    /**
     * Değişim algısı açıkken bile bu kadar saatte bir her şey yeniden okunur.
     */
    private const FULL_RESYNC_HOURS = 6;

    public function __construct(
        private DeviceFactsReader $facts,
        private PortSynchronizer $ports,
        private IpAddressSynchronizer $addresses,
        private RouteSynchronizer $routes,
        private PrefixListSynchronizer $prefixLists,
        private FirewallSynchronizer $firewall,
        private PolicyStatementSynchronizer $policies,
        private BgpSynchronizer $bgp,
        private CommitHistoryReader $commits,
        private DeviceSettingsSynchronizer $settings,
        private SchemaProbe $schema,
        private CapabilityProbe $capabilities,
        private StormControlSynchronizer $stormControl,
        private VoipSynchronizer $voip,
        private AnalyzerSynchronizer $analyzers,
        private SecureAccessPortSynchronizer $secureAccess,
    ) {}

    /** Açık bir oturumda cihazın tamamını okur. */
    public function run(NetworkSwitch $switch, NetconfSession $session, bool $force = false): void
    {
        /*
         * Değişim algısı: cihazın son commit'i öncekiyle aynıysa
         * yapılandırma da aynıdır — Junos'ta yapılandırma ancak
         * bir commit ile değişir, CLI'dan yapılan değişiklik de
         * commit üretir. Tek RPC okunur ve tur burada biter.
         */
        $okunanCommitler = $this->commits->commits($session);

        if ($this->unchanged($switch, $okunanCommitler, $force)) {
            return;
        }

        $this->storeFacts($switch, $this->facts->facts($session));

        /*
         * Şema ailesi cihaza BİR KEZ sorulur (ELS öncesi mi,
         * ELS mi). Yazma yolları portun anahtarlama modunu buna
         * göre adlandırıyor; yanlış ad sessiz bir yanlış
         * yapılandırma değil, cihazın reddi olurdu ama panel de
         * o cihazı hiç yönetemezdi.
         *
         * Aynı oturumda yapılır: ayrı oturum ~2 saniye ve cihazda
         * bir mgd süreci daha demek.
         */
        if ($switch->schema_profile === null) {
            $profil = $this->schema->detect($session, $switch);

            if ($profil !== null) {
                $switch->forceFill(['schema_profile' => $profil])->save();
            }
        }

        /*
         * Ailenin ŞEKLİ yukarıda soruldu; burada da bir alanın
         * kabul ettiği DEĞERLER soruluyor. İkisi ayrı sorular:
         * EX2300 ile EX4300 aynı ailede ama aynı hız değerlerini
         * kabul etmeyebiliyor (bkz. .ai/rules/junos.md).
         *
         * Aynı oturumda ve yalnızca LOAD ile: commit check'e
         * girilmiyor, cihazda iz kalmıyor.
         */
        if ($this->capabilities->shouldProbe($switch)) {
            $olculen = $this->capabilities->detect($session, $switch);

            if ($olculen !== null) {
                $switch->forceFill(['device_capabilities' => $olculen])->save();
            }
        }

        // Port ve VLAN envanteri de cihazdan gelir; yazma yolundaki
        // geri okuma aynı sınıfı kullanır.
        $this->ports->sync($switch, $session);
        $this->addresses->sync($switch, $session);

        // Route birleştirme kendi işlemini açar; yazma yolundaki geri
        // okuma da aynı sınıfı kullanır.
        $this->routes->sync($switch, $session);

        $this->prefixLists->sync($switch, $session);

        // Filtreler prefix list'lerden SONRA okunur: hangi listenin
        // çıkış bekçisi olduğu filtrelerden çıkarılıyor.
        $this->firewall->sync($switch, $session);

        // Politikalar BGP'den ÖNCE okunur: grup formundaki import ve
        // export listeleri buradan doluyor.
        $this->policies->sync($switch, $session);

        // BGP oturum DURUMU da burada okunur: panel durumu gösterir ve
        // onu yazan iş bu turdur; yazılmasaydı ekran kalıcı olarak yanlış
        // bilgi verirdi.
        $this->bgp->sync($switch, $session);

        /*
         * Cihazın kendi ayarları: sistem adı, saat, isim/zaman/
         * günlük sunucuları, SNMP kimliği ve community'ler, RSTP.
         * Panelden yazılabildikleri için okunmak zorundalar.
         */
        $this->settings->sync($switch, $session);

        /*
         * Fırtına denetimi. Yayın fırtınası bir anahtarı dakikalar
         * içinde kullanılamaz hâle getirir; cihazdaki ayarın panelde
         * görünmemesi kabul edilemez.
         */
        $this->stormControl->sync($switch, $session);

        /*
         * VoIP (ses) VLAN'ı: telefonun etiketli VLAN'ı. Panelden
         * yazılabildiği için okunmak ZORUNDA — yazılan her alan
         * okunmazsa ilk güncellemede sessizce kaybolur.
         */
        $this->voip->sync($switch, $session);

        /*
         * Port aynalama. Aynalama bir portun bütün trafiğini
         * başka bir porta kopyalar;
         * envanterde görünmemesi, kimsenin fark etmediği bir
         * dinleme oturumu demektir.
         */
        $this->analyzers->sync($switch, $session);

        /*
         * DHCP snooping ve port güvenliği. Bir uplink'in
         * "dhcp-trusted" olup olmadığı ya da
         * bir portta MAC sınırı bulunduğu envanterde görünmüyorsa
         * yanlış verilmiş bir güveni kimse fark edemez.
         */
        $this->secureAccess->sync($switch, $session);

        DB::transaction(fn () => $this->storeCommits($switch, $okunanCommitler));
    }

    /**
     * @param  array{model: ?string, serial_number: ?string, junos_version: ?string, hostname: ?string, clock_offset_seconds: ?int}  $facts
     */
    private function storeFacts(NetworkSwitch $switch, array $facts): void
    {
        // hostname envanterin kendi alanıdır; cihazdaki ad ondan farklı
        // olabilir ve panelden verilen ad ezilmemeli.
        unset($facts['hostname']);

        /*
         * Saat sapması ayrı yazılır: değeri null OLABİLİR ve o "ölçülemedi"
         * demektir. array_filter null'ları atıyor; sapma oraya karışsaydı
         * eski bir ölçüm sonsuza kadar doğru sanılırdı.
         */
        $sapma = $facts['clock_offset_seconds'] ?? null;
        unset($facts['clock_offset_seconds']);

        $switch->saveObservation(array_filter($facts, static fn (?string $value): bool => $value !== null));

        if ($sapma !== null) {
            $switch->saveObservation([
                'clock_offset_seconds' => $sapma,
                'clock_checked_at' => now(),
            ]);
        }
    }

    /**
     * Cihazın yapılandırması son eşitlemeden beri değişmemiş mi.
     *
     * Junos'ta yapılandırma ancak bir commit ile değişir; sıra numarası 0
     * daima EN SON commit'tir. Onun zamanı aynıysa okunacak yeni bir şey
     * yoktur ve çok RPC'lik tam okuma atlanır — cihaza binen yük onda
     * birine iner.
     *
     * İki durumda yine de tam okuma yapılır:
     *   - Değişim algısı ayarlardan kapatılmışsa.
     *   - Uzun süredir hiç tam okuma yapılmadıysa. Panelin verisi cihazdan
     *     bağımsız bir sebeple (elle silme, taşıma, hatalı göç) bozulmuş
     *     olabilir; güvenlik ağı olarak arada bir her şey yeniden okunur.
     *
     * @param  list<array{sequence_number: int, committed_by: ?string, committed_at: ?CarbonImmutable, comment: ?string, client: ?string}>  $commits
     */
    private function unchanged(NetworkSwitch $switch, array $commits, bool $force): bool
    {
        if ($force || ! config('netconf.change_detection')) {
            return false;
        }

        if ($switch->last_sync_at === null || $switch->last_sync_at->lt(now()->subHours(self::FULL_RESYNC_HOURS))) {
            return false;
        }

        $latest = collect($commits)->firstWhere('sequence_number', 0)['committed_at'] ?? null;

        if ($latest === null) {
            return false;
        }

        $stored = $switch->commits()->where('sequence_number', 0)->value('committed_at');

        return $stored !== null && $stored->getTimestamp() === $latest->getTimestamp();
    }

    /**
     * @param  list<array{sequence_number: int, committed_by: ?string, committed_at: ?CarbonImmutable, comment: ?string, client: ?string}>  $commits
     */
    private function storeCommits(NetworkSwitch $switch, array $commits): void
    {
        $rows = array_map(fn (array $commit): array => [
            'switch_id' => $switch->id,

            // upsert model olaylarını atlar; uuid'yi HasUuid dolduramaz.
            'uuid' => (string) Str::uuid(),
            'sequence_number' => $commit['sequence_number'],
            'committed_by' => $commit['committed_by'],
            'committed_at' => $commit['committed_at'],
            'comment' => $commit['comment'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $commits);

        /*
         * Tek sorgu. Her commit için ayrı ayrı updateOrCreate çağırmak switch
         * başına yüze yakın sorgu demek olurdu.
         */
        if ($rows !== []) {
            SwitchCommit::query()->upsert(
                $rows,
                ['switch_id', 'sequence_number'],
                ['committed_by', 'committed_at', 'comment', 'updated_at'],
            );
        }

        // Cihaz yalnızca son 49 commit'i tutar; daha eskileri envanterden de
        // düşer, aksi hâlde sıra numaraları kayar ve kayıtlar birbirine karışır.
        $switch->commits()
            ->whereNotIn('sequence_number', array_column($commits, 'sequence_number'))
            ->delete();
    }
}
