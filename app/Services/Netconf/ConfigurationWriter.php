<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Closure;
use Throwable;

/**
 * Cihaz yapılandırmasına YAZMA. Tek güvenli yol budur; başka hiçbir yerden
 * commit atılmaz.
 *
 * Akış iki oturumdur ve sırası güvenliğin kendisidir:
 *
 *  1. lock-configuration — aday yapılandırma kilitlenir; kilit boyunca
 *     başkası (CLI dahil) değişiklik yükleyemez.
 *  1b. Paylaşılan adayın TEMİZ olduğu doğrulanır. Başkasının bekleyen
 *     değişikliği varsa yazma REDDEDİLİR: commit onu da commit ederdi.
 *     Bunun için "open-configuration private" KULLANILMAZ: Junos özel aday
 *     içinde "commit confirmed"i KABUL ETMİYOR ("\"commit confirmed\" is not
 *     supported for private configuration", test4'te ölçüldü) — yani geri
 *     alma güvencesi ya da yazmanın kendisi, ikisinden biri kaybedilirdi.
 *     Kilit ikisini de korur.
 *  2. load-configuration merge — değişiklik aday kopyaya yüklenir.
 *  3. compare rollback 0 — tam fark okunur. Fark boşsa commit HİÇ atılmaz.
 *  4. commit-configuration confirmed — geri alınabilir commit. Cihaz
 *     doğrulamayı BU adımda yapar; ayrı bir "check" çağrılmaz, çünkü aynı
 *     işi ikinci kez yapmak cihazın en pahalı adımını ikiye katlar (ELS'te
 *     on saniyeyi aşabilir). Bağlantı koparsa cihaz kendini birkaç dakika içinde
 *     eski hâline döndürür.
 *  6. YENİ oturum açılır ve commit onaylanır. Yeni oturumun açılabilmesi,
 *     değişikliğin yönetim erişimini bozmadığının kanıtıdır. Açılamazsa
 *     onay verilmez ve cihaz kendiliğinden geri döner.
 *
 * Bütün akış switch başına bir kilidin içinde çalışır. Junos'ta bekleyen bir
 * confirmed commit varken başkasının attığı commit onu da onaylar; iki panel
 * kullanıcısı aynı switche aynı anda yazarsa geri dönüş güvenliği tam o anda
 * kaybolur. Ayrıntı: SwitchWriteLock.
 *
 * Junos uyarıları rpc-error olarak döner ve işi durdurmaz; kayda geçmeleri
 * için çağırana verilir.
 */
class ConfigurationWriter
{
    public function __construct(
        private NetconfClient $client,
        private CommitLog $log,
        private SwitchWriteLock $lock,
        private DeviceOperationRecorder $operations,
    ) {}

    /**
     * @param  string|list<string>|Closure(NetconfSession): list<string>  $configuration
     *                                                                                    <configuration> kök etiketiyle
     *                                                                                    Junos XML. Liste verilirse
     *                                                                                    sırayla yüklenir ve TEK
     *                                                                                    commit atılır: bir listeyi
     *                                                                                    değiştirmek (VLAN üyeliği
     *                                                                                    gibi) önce silme sonra
     *                                                                                    ekleme gerektirir, ikisi
     *                                                                                    ayrı commit olamaz.
     *                                                                                    KAPANIŞ verilirse belgeler
     *                                                                                    yazma oturumunun İÇİNDE,
     *                                                                                    kilit alınmış ve aday kopya
     *                                                                                    açılmışken üretilir; "bu
     *                                                                                    arayüzün son adresi mi"
     *                                                                                    gibi kararlar bayat
     *                                                                                    envantere değil cihaza
     *                                                                                    sorulabilsin diye
     * @param  string  $summary  Commit'e iliştirilecek özet; panel kullanıcısı
     *                           ile birlikte cihazın commit geçmişine yazılır
     * @param  ?Closure(NetconfSession): void  $afterConfirm  Commit onaylandıktan
     *                                                        sonra AYNI ikinci
     *                                                        oturumda çalışır;
     *                                                        yazılanı cihazdan
     *                                                        geri okumak için.
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(
        NetworkSwitch $switch,
        string|array|Closure $configuration,
        string $summary,
        ?Closure $afterConfirm = null,
    ): array {
        return $this->send(
            NetconfTarget::fromSwitch($switch),
            is_string($configuration) ? [$configuration] : $configuration,
            'merge',
            'xml',
            $summary,
            $afterConfirm,
        );
    }

    /**
     * Aynı akış, ama BELİRTİLEN kimlikle bağlanır.
     *
     * Parola değiştirirken gerekir: panelin kayıtlı kimliğiyle bağlanmak
     * ortada bir yerde imkânsız hâle gelir. Kurtarma hesabıyla bağlanıp asıl
     * hesabın parolasını değiştirmek, hem commit'i hem de ONAY oturumunu
     * parolası değişmeyen bir hesapla atmayı sağlar (bkz.
     * SwitchPasswordRotator).
     *
     * @param  string|list<string>  $configuration
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    /**
     * @param  bool  $geriAlinabilir  Geri alınabilir commit kullanılsın mı.
     *                                Yalnızca KENDİ ERİŞİMİMİZİ etkileyemeyecek
     *                                yazmalarda kapatılır; bkz. aşağıdaki not.
     */
    public function applyOn(
        NetconfTarget $target,
        string|array $configuration,
        string $summary,
        ?Closure $afterConfirm = null,
        bool $revertible = true,
    ): array {
        return $this->send(
            $target,
            is_string($configuration) ? [$configuration] : $configuration,
            'merge',
            'xml',
            $summary,
            $afterConfirm,
            revertible: $revertible,
        );
    }

    /**
     * Ham Junos METNİNİ cihaza uygular.
     *
     * İki biçim vardır ve ikisi de aynı güvenlik zincirinden geçer:
     *
     *   merge     — mevcut yapılandırmanın üstüne yazar, hiçbir şeyi silmez.
     *   override  — cihazdaki yapılandırmanın TAMAMINI atar ve yerine bu
     *               metni koyar. Terminaldeki "load override" ile aynıdır.
     *
     * Override'ın tehlikesi açıktır: yönetim erişimi de yapılandırmaya
     * bağlıdır, gönderilen metin mgmt/SSH tanımlarını içermiyorsa cihaz
     * erişilemez olur. Tam da bu yüzden geri alınabilir commit kullanılır:
     * onay ikinci bir oturumda verilir ve o oturum açılamazsa cihaz kendini
     * eski hâline döndürür.
     *
     * @param  'merge'|'override'|'set'  $mode
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyText(NetconfTarget $target, string $configuration, string $mode, string $summary): array
    {
        return $this->send($target, [$configuration], $mode, 'text', $summary, null);
    }

    /**
     * Cihazı ESKİ BİR COMMIT'E döndürür.
     *
     * Junos'ta geri alma bir yükleme biçimidir: <load-configuration
     * rollback="N"/> o commit'in yapılandırmasını aday kopyaya koyar,
     * gerisi normal akıştır. Bu yüzden aynı güvenlik zincirinden geçer —
     * fark okunur, commit check yapılır, geri alınabilir commit atılır ve
     * onay YENİ bir oturumda verilir.
     *
     * Geri alma cihazın TAMAMINI etkiler: panelin yönetmediği satırlar da
     * eski hâline döner. Çağıran bunu kullanıcıya söylemek zorundadır.
     *
     * @param  int|Closure(NetconfSession): int  $index  Junos rollback indeksi
     *                                                   (0 = şu anki commit).
     *                                                   Kapanış verilirse indeks
     *                                                   YAZMA OTURUMUNUN İÇİNDE,
     *                                                   kilit alınmışken çözülür:
     *                                                   indeks her yeni commit'te
     *                                                   kayıyor ve ayrı bir
     *                                                   oturumda okunması araya
     *                                                   commit girmesine açık
     *                                                   bir pencere bırakır
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyRollback(
        NetworkSwitch $switch,
        int|Closure $index,
        string $summary,
        ?Closure $afterConfirm = null,
    ): array {
        return $this->send(
            NetconfTarget::fromSwitch($switch),
            [],
            'merge',
            'xml',
            $summary,
            $afterConfirm,
            $index,
        );
    }

    /**
     * Metni cihaza YÜKLER ama commit ETMEZ; yalnızca farkı döndürür.
     *
     * Override gibi geri dönüşü zor bir işlemden önce ne olacağını görmek
     * gerekir. Aday yapılandırma özel oturumda kalır ve oturum kapanınca
     * atılır; cihazda hiçbir iz bırakmaz.
     *
     * @param  'merge'|'override'|'set'  $mode
     * @return array{diff: string, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function previewText(NetconfTarget $target, string $configuration, string $mode): array
    {
        return $this->client->runOn($target, function (NetconfSession $session) use ($configuration, $mode): array {
            $session->rpc('<open-configuration><private/></open-configuration>');

            try {
                $session->rpc(sprintf(
                    '<load-configuration action="%s" format="text">%s</load-configuration>',
                    $mode,
                    $this->textPayload($configuration, $mode),
                ));

                return ['diff' => $this->diff($session), 'warnings' => $session->warnings()];
            } finally {
                // Commit yok: aday yapılandırma oturumla birlikte atılır.
                $session->rpc('<close-configuration/>');
            }
        });
    }

    /**
     * Geri almanın NE DEĞİŞTİRECEĞİNİ commit atmadan gösterir.
     *
     * Aday yapılandırma özel oturumda kalır ve oturum kapanınca atılır;
     * cihazda hiçbir iz bırakmaz. Geri alma cihazın tamamını etkilediği için
     * önce bu fark okunmadan commit atılmamalıdır.
     *
     * @return array{diff: string, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function previewRollback(NetworkSwitch $switch, int $index): array
    {
        return $this->client->run($switch, function (NetconfSession $session) use ($index): array {
            $session->rpc('<open-configuration><private/></open-configuration>');

            try {
                $session->rpc(sprintf('<load-configuration rollback="%d"/>', $index));

                return ['diff' => $this->diff($session), 'warnings' => $session->warnings()];
            } finally {
                // Commit yok: aday yapılandırma oturumla birlikte atılır.
                $session->rpc('<close-configuration/>');
            }
        });
    }

    /**
     * @param  list<string>|Closure(NetconfSession): list<string>  $documents
     * @param  'merge'|'override'|'set'  $mode
     * @param  'xml'|'text'  $format
     * @param  ?Closure(NetconfSession): void  $afterConfirm
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    private function send(
        NetconfTarget $target,
        array|Closure $documents,
        string $mode,
        string $format,
        string $summary,
        ?Closure $afterConfirm,
        int|Closure|null $rollback = null,
        bool $revertible = true,
    ): array {
        $confirm = (int) config('netconf.confirm_minutes');
        $log = htmlspecialchars($this->log->message($summary), ENT_XML1 | ENT_QUOTES);
        $start = microtime(true);

        /*
         * Yazmanın TAMAMI kayda sarılır: kilit çakışması da, cihaz reddi de,
         * onay oturumunun açılamaması da. Yoksa kullanıcı yalnızca bir toast
         * görür, sayfayı kapatınca hiçbir iz kalmaz.
         */
        try {
            $result = $this->lock->runFor(
                $target->lockKey(),
                $target->label,
                fn (): array => $this->write($target, $documents, $mode, $format, $confirm, $log, $afterConfirm, $rollback, $revertible),
            );
        } catch (Throwable $exception) {
            throw $this->operations->failure(
                $exception,
                $rollback === null ? 'write' : 'rollback',
                $target->label,
                $this->switchOf($target),
                [
                    'summary' => $summary,
                    'document' => $this->documentPreview($documents),
                    'duration_ms' => $this->elapsed($start),
                ],
            );
        }

        $info = [
            'summary' => $summary,
            'diff' => $result['diff'],
            'duration_ms' => $this->elapsed($start),
            'warnings' => $result['warnings'],
        ];

        // Uyarı "işi durdurmadı" demektir, "önemsiz" demek değil; kayda
        // geçmeli.
        $this->operations->warnings(
            $result['warnings'],
            $rollback === null ? 'write' : 'rollback',
            $target->label,
            $this->switchOf($target),
            $info,
        );

        $this->operations->success(
            $rollback === null ? 'write' : 'rollback',
            $target->label,
            $this->switchOf($target),
            $info,
        );

        return $result;
    }

    private function switchOf(NetconfTarget $target): ?NetworkSwitch
    {
        return $target->switchId === null
            ? null
            : NetworkSwitch::query()->find($target->switchId);
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Reddedilen belgenin kendisi kayda girer: "ne gönderdik" sorusu
     * cevapsız kalmamalı. Kapanışla üretilen belgeler henüz yoktur.
     *
     * @param  list<string>|Closure  $documents
     */
    private function documentPreview(array|Closure $documents): ?string
    {
        if ($documents instanceof Closure) {
            return null;
        }

        return $documents === [] ? null : implode("\n", $documents);
    }

    /**
     * Kilidin içindeki asıl akış.
     *
     * @param  list<string>|Closure(NetconfSession): list<string>  $documents
     * @param  ?Closure(NetconfSession): void  $afterConfirm
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    private function write(
        NetconfTarget $target,
        array|Closure $documents,
        string $mode,
        string $format,
        int $confirm,
        string $log,
        ?Closure $afterConfirm,
        int|Closure|null $rollback = null,
        bool $revertible = true,
    ): array {
        $result = $this->client->runOn($target, function (NetconfSession $session) use ($documents, $mode, $format, $confirm, $log, $rollback, $revertible, $afterConfirm): array {
            $session->rpc('<lock-configuration/>');

            try {
                $this->guardPendingChanges($session);

                /*
                 * Geri alma bir YÜKLEME biçimidir: belge yerine indeks
                 * gönderilir, gerisi aynı akıştır.
                 */
                if ($rollback !== null) {
                    /*
                     * İndeks BURADA çözülür. Junos'ta rollback numarası sıradır
                     * ve her yeni commit'te bir kayar; ayrı bir oturumda
                     * okunursa araya giren bir commit numarayı kaydırır ve
                     * kullanıcının gördüğünden BAŞKA bir yapılandırma
                     * yüklenir. Kilit alınmış ve aday kopya açıkken çözmek
                     * o pencereyi kapatır.
                     */
                    $index = $rollback instanceof Closure ? $rollback($session) : $rollback;

                    $session->rpc(sprintf('<load-configuration rollback="%d"/>', $index));
                }

                /*
                 * Belgeler burada üretilir: kilit alınmış, aday kopya açık ve
                 * cihaz elimizde. "Bu arayüzün son adresi mi" gibi kararlar
                 * envanterden değil cihazdan okunabilsin diye.
                 */
                $documents = $documents instanceof Closure ? $documents($session) : $documents;

                foreach ($documents as $document) {
                    /*
                     * Metin yükü CDATA içinde gider: Junos yapılandırma
                     * metni "<", "&" gibi karakterler taşıyabilir ve XML
                     * gövdesine olduğu gibi konursa belge bozulur.
                     */
                    $payload = $format === 'text'
                        ? $this->textPayload($document, $mode)
                        : $document;

                    try {
                        $session->rpc(sprintf(
                            '<load-configuration action="%s" format="%s">%s</load-configuration>',
                            $mode,
                            $format,
                            $payload,
                        ));
                    } catch (NetconfException $exception) {
                        throw $exception->withStage('load');
                    }
                }

                $diff = $this->diff($session);

                // Cihaz zaten istenen durumda: commit atmaya gerek yok.
                if ($diff === '') {
                    return ['diff' => '', 'committed' => false, 'warnings' => $session->warnings()];
                }

                /*
                 * AYRI bir "commit check" YAPILMAZ.
                 *
                 * Junos, "commit confirmed" sırasında zaten aynı doğrulamayı
                 * çalıştırıyor ve başarısız olursa hiçbir şey commit
                 * edilmiyor — güvenlik aynı. Ama doğrulama cihazın EN PAHALI
                 * adımı: ELS'te on saniyeyi aşabilir, ELS öncesinde birkaç
                 * saniyedir. İki kez yapmak bir port değişikliğini dakikalara
                 * çıkarır.
                 *
                 * Doğrulama hatasının aşama etiketi 'commit' olur. Hata metni
                 * cihazdan geldiği için içeriği aynıdır.
                 */
                try {
                    $session->rpc($revertible
                        ? sprintf(
                            '<commit-configuration><confirmed/><confirm-timeout>%d</confirm-timeout><log>%s</log></commit-configuration>',
                            $confirm,
                            $log,
                        )
                        : "<commit-configuration><log>{$log}</log></commit-configuration>");
                } catch (NetconfException $exception) {
                    throw $exception->withStage('commit');
                }

                /*
                 * Geri alınabilir olmayan yazmada onay oturumu hiç açılmaz;
                 * kapanış okuması AYNI oturumda yapılır. Yeni oturum cihazda
                 * bir mgd fork'u ve ~2 saniye demektir.
                 */
                $readError = null;

                if (! $revertible && $afterConfirm !== null) {
                    $readError = $this->readBack($afterConfirm, $session);
                }

                return [
                    'diff' => $diff,
                    'committed' => true,
                    'warnings' => [...$session->warnings(), ...($readError === null ? [] : [$readError])],
                ];
            } finally {
                $session->rpc('<unlock-configuration/>');
            }
        });

        if ($result['committed'] && $revertible) {
            $readError = $this->confirm($target, $log, $afterConfirm);

            if ($readError !== null) {
                $result['warnings'][] = $readError;
            }
        }

        return $result;
    }

    /**
     * Geri alınabilir commit'i onaylar.
     *
     * Ayrı oturumda yapılır: oturum açılabiliyorsa yönetim erişimi ayaktadır.
     * Burada bir hata olursa onay verilmez ve cihaz confirm-timeout dolunca
     * kendini eski hâline döndürür — istisna bilerek yukarı bırakılır.
     *
     * Geri okuma da burada yapılır: oturum zaten açık, yeni oturum açmak
     * cihazda bir mgd fork'u daha demek olurdu.
     *
     * Onay kesinleştikten sonraki geri okuma hatası İSTİSNA OLARAK ÇIKMAZ;
     * uyarı olarak döner (bkz. readBack).
     *
     * @param  ?Closure(NetconfSession): void  $afterConfirm
     * @return ?string Geri okuma uyarısı
     *
     * @throws NetconfException
     */
    private function confirm(NetconfTarget $target, string $log, ?Closure $afterConfirm): ?string
    {
        return $this->client->runOn($target, function (NetconfSession $session) use ($log, $afterConfirm): ?string {
            $session->rpc('<lock-configuration/>');

            try {
                // Açıklama onay commit'ine de yazılır: cihazın geçmişinde
                // kalıcı olan satır budur.
                $session->rpc("<commit-configuration><log>{$log}</log></commit-configuration>");
            } catch (NetconfException $exception) {
                throw $exception->withStage('confirm');
            } finally {
                $session->rpc('<unlock-configuration/>');
            }

            return $afterConfirm === null ? null : $this->readBack($afterConfirm, $session);
        });
    }

    /** Geri okuma uyarılarının başlığı; iş kaydı bunu tanıyıp kullanıcıya söyler. */
    public const READBACK_WARNING = 'Değişiklik cihazda kalıcı oldu ama envanter geri okunamadı';

    /**
     * Commit KESİNLEŞTİKTEN sonra yazılanı cihazdan geri okur.
     *
     * Bu noktada değişiklik artık kalıcıdır; geri okumanın (RPC zaman aşımı,
     * envanter yazarken veritabanı hatası) istisna fırlatması yazmayı
     * "Failed" gösterir ve etkinlik zincirine hiç girmesini engellerdi —
     * cihazda yaşayan bir değişiklik kayıtsız kalırdı. Hata bu yüzden
     * UYARIYA çevrilir: iş tamamlandı sayılır, envanterin bayat olabileceği
     * söylenir; sonraki eşitleme turu envanteri zaten tazeler.
     *
     * @param  Closure(NetconfSession): void  $afterConfirm
     */
    private function readBack(Closure $afterConfirm, NetconfSession $session): ?string
    {
        try {
            $afterConfirm($session);

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return self::READBACK_WARNING.': '.$exception->getMessage();
        }
    }

    /**
     * Paylaşılan adayda BAŞKASININ bekleyen değişikliği var mı?
     *
     * Kilit yeni değişiklik girmesini engeller ama ZATEN duran bir değişikliği
     * kaldırmaz. Commit atılırsa o satırlar da commit edilirdi — panelin hiç
     * görmediği, kullanıcının onaylamadığı bir yapılandırma yayına girerdi.
     *
     * "private" aday bunu doğal olarak sağlardı ama kullanılamaz; kilitle
     * koruma açıkça yazılmak zorunda (bkz. sınıf açıklaması).
     *
     * @throws NetconfException
     */
    private function guardPendingChanges(NetconfSession $session): void
    {
        if ($this->diff($session) === '') {
            return;
        }

        throw NetconfException::transport(
            'Cihazda kaydedilmemiş bir yapılandırma değişikliği duruyor. '
            .'Panel yazmayı reddetti: commit o değişikliği de yayına alırdı. '
            .'CLI’da "rollback" ya da "commit" ile temizlendikten sonra yeniden deneyin.',
            'candidate-dirty',
        );
    }

    /**
     * Metin yükünün Junos'un beklediği hâli.
     *
     * İki şey cihazda denenerek düzeltildi (jn32-test, 12.3R12.4):
     *
     * 1. CDATA KULLANILMAZ. Bu Junos "<![CDATA[...]]>" dizisini içerik değil
     *    ETİKET sanıyor ve "internal communications error (tag '![CDATA[...')"
     *    diyor. Metin XML kaçışıyla gönderilir; "&" ve "<" cihaza olduğu gibi
     *    ulaşır.
     *
     * 2. Sarmalayan eleman EYLEME GÖRE değişir: "set" satırları
     *    <configuration-set> içinde gider, hiyerarşik metin (merge/override)
     *    <configuration-text> içinde. Yanlışı gönderilirse cihaz
     *    "expecting configuration-set" ile reddeder.
     *
     * İkisinden biri yanlışsa tam config yükleme gerçek cihazda hiç çalışmaz;
     * sahte cihaz her yükü kabul ettiği için testler bunu yakalayamaz.
     */
    protected function textPayload(string $configuration, string $mode): string
    {
        $escaped = htmlspecialchars($configuration, ENT_XML1 | ENT_QUOTES);

        return $mode === 'set'
            ? '<configuration-set>'.$escaped.'</configuration-set>'
            : '<configuration-text>'.$escaped.'</configuration-text>';
    }

    private function diff(NetconfSession $session): string
    {
        $reply = $session->rpc('<get-configuration compare="rollback" rollback="0" format="text"/>');

        return trim((string) ($reply->xpath('//*[local-name()="configuration-output"]')[0] ?? ''));
    }
}
