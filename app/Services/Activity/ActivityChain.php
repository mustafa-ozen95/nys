<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Enums\ActivityChannel;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Etkinlik kaydını değiştirilemez kılan hash zinciri.
 *
 * Her satır kendinden öncekinin özetini taşır ve kendi özeti bir sonrakinin
 * içine girer. Bir satır sonradan değiştirilirse ya da silinirse ondan
 * sonraki bütün özetler tutmaz; doğrulama kırılmanın tam yerini söyler.
 * Amaç kaydı gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ kanıtlayabilmektir.
 *
 * Özet düz SHA değil, gizli anahtarla üretilen HMAC-SHA256'dır. Düz özet
 * kullanılsaydı veritabanına yazabilen biri bir satırı değiştirip zinciri
 * baştan hesaplayabilirdi; anahtar olmadan bunu yapamaz.
 *
 * Sıra önemlidir: iki kayıt aynı anda yazılırsa ikisi de aynı "önceki"yi
 * okuyup zinciri çatallandırabilir. Bu yüzden okuma ve yazma tek kilidin
 * içindedir.
 *
 * ZİNCİR İKİ TANEDİR: "audit" (oturum, CRUD, cihaza yazma) ve "access"
 * (sayfa açma). Sebep saklama süresidir — erişim kayıtları hacmin neredeyse
 * tamamıdır ve çok daha kısa saklanır. Tek zincirin ORTASINDAN satır silmek
 * zinciri kırardı; ayrı zincirde erişim kayıtları kendi BAŞINDAN budanır ve
 * çapası yenilenir. Koruma azalmaz: iki zincir de aynı HMAC ile korunur.
 */
class ActivityChain
{
    /** Denetim zinciri: oturum açma, CRUD, cihaza yazma. */
    public const AUDIT = 'audit';

    /** Erişim zinciri: sayfa açma ve form gönderme. */
    public const ACCESS = 'access';

    /** @var list<string> */
    public const CHAINS = [self::AUDIT, self::ACCESS];

    private const LOCK = 'activity:chain';

    /**
     * Özet biçiminin güncel sürümü.
     *
     * Payload'a yeni bir alan eklendiğinde bu sayı artırılır ve eski sürüm
     * hashFor() içinde KORUNUR. Aksi hâlde biçim değişikliği bütün geçmişi
     * "kırık" gösterir ve zinciri sıfırlamak gerekir — sıfırlamak, zincirin
     * var olma sebebini ortadan kaldırır.
     *
     * Sürüm 1: ... | description | ip
     * Sürüm 2: ... | description | reason | ip
     */
    private const VERSION = 2;

    /** Kilit için beklenecek en fazla süre (saniye). */
    private const WAIT = 5;

    /**
     * Zincire yeni bir satır ekler.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function append(array $attributes): ActivityLog
    {
        $chain = $this->chainFor($attributes['channel'] ?? null);

        /*
         * Kilit zincir başınadır: erişim kaydı yazılırken denetim kaydı
         * beklemez. Aynı zincire yazan iki istek yine sıraya girer.
         */
        return Cache::lock(self::LOCK.':'.$chain, 10)->block(self::WAIT, function () use ($attributes, $chain): ActivityLog {
            /*
             * Zincirin ucu: o zincirdeki en son ÖZETLİ satır. Zincirden önce
             * yazılmış satırlar arada durabilir; onların özeti yoktur ve ucu
             * onlar belirlemez.
             */
            $previous = ActivityLog::query()
                ->where('chain', $chain)
                ->whereNotNull('hash')
                ->latest('id')
                ->value('hash') ?? $this->anchor($chain);

            $log = new ActivityLog($attributes);
            $log->created_at = $attributes['created_at'] ?? now();
            $log->updated_at = $log->created_at;
            $log->previous_hash = $previous;
            $log->chain_version = self::VERSION;
            $log->chain = $chain;
            $log->hash = $this->hashFor($log, $previous);
            $log->save();

            $this->rememberTail($chain, $log);

            return $log;
        });
    }

    /**
     * Zincirin SON satırının (id, özet) noktası; veritabanının DIŞINDA durur.
     *
     * İleri yönlü bir zincir sondan silmeyi anlayamaz: son satırlar silinince
     * kalan her şey kendi içinde tutarlıdır ve doğrulama "ok" der. Çapa gibi
     * önbellekte tutulan bu nokta, doğrulamada o satırın hâlâ aynı özetle
     * yerinde olup olmadığını sorar. Veritabanına yazabilen ama önbelleğe
     * erişemeyen biri son satırları silerse yakalanır.
     *
     * Satır bir işlemin içinde yazılmış olabilir ve işlem geri alınabilir; bu
     * yüzden nokta yalnızca işlem KESİNLEŞTİKTEN sonra ilerler, aksi hâlde
     * geri alınan satır "silinmiş" görünürdü.
     */
    private function rememberTail(string $chain, ActivityLog $log): void
    {
        $nokta = ['id' => $log->id, 'hash' => $log->hash];

        DB::afterCommit(fn () => Cache::forever($this->tailKey($chain), $nokta));
    }

    /**
     * Zincirin tamamı budandıysa son nokta da gider; yoksa budama, doğru olan
     * bir zinciri "sondan silinmiş" gösterirdi. Başı budanan zincirde son satır
     * yerinde kaldığı için nokta korunur.
     */
    public function forgetTailIfChainEmpty(string $chain): void
    {
        $kalan = ActivityLog::query()->where('chain', $chain)->whereNotNull('hash')->exists();

        if (! $kalan) {
            Cache::forget($this->tailKey($chain));
        }
    }

    private function tailKey(string $chain): string
    {
        return $this->anchorKey($chain).':tail';
    }

    /**
     * Satırın hangi zincire gireceği. Karar kanalın kendisindedir; burada
     * yalnızca okunur.
     */
    private function chainFor(mixed $channel): string
    {
        if ($channel instanceof ActivityChannel) {
            return $channel->chain();
        }

        return ActivityChannel::tryFrom((string) $channel)?->chain() ?? self::AUDIT;
    }

    /**
     * Zinciri doğrular.
     *
     * Zincirden ÖNCE yazılmış satırlar (özeti olmayanlar) doğrulanmaz ve
     * doğrulanmış gibi de gösterilmez: sayıları ayrıca bildirilir. Onları
     * sonradan özetlemek dürüst olmazdı — o satırların değişmediğine dair
     * elimizde bir kanıt yok ve öyleymiş gibi davranmak, zincirin bütün
     * anlamını ortadan kaldırırdı.
     *
     * @return array{ok: bool, checked: int, unprotected: int, acknowledged: int, broken_id: ?int, broken_ids: list<int>, checked_at: string}
     */
    public function verify(): array
    {
        $checked = 0;
        $unprotected = 0;
        $broken = null;
        $brokenIds = [];
        $acknowledged = 0;

        foreach (self::CHAINS as $chain) {
            $sonuc = $this->verifyChain($chain);

            $acknowledged += $sonuc['acknowledged'];
            $checked += $sonuc['checked'];
            $unprotected += $sonuc['unprotected'];
            $broken ??= $sonuc['broken_id'];
            $brokenIds = [...$brokenIds, ...$sonuc['broken_ids']];
        }

        $result = [
            'ok' => $broken === null,
            'checked' => $checked,
            'unprotected' => $unprotected,
            'acknowledged' => $acknowledged,
            'broken_id' => $broken,
            'broken_ids' => array_slice($brokenIds, 0, 50),
            'checked_at' => now()->toIso8601String(),
        ];

        Cache::put((string) config('activity.verification_key'), $result, now()->addWeek());

        return $result;
    }

    /**
     * Tek bir zinciri doğrular.
     *
     * Kırık satır bulununca DURULMAZ: kırık satırın kendi özeti "önceki" kabul
     * edilip devam edilir. Aksi hâlde ilk kırıktan sonraki her değişiklik
     * (örneğin kırığı gizlemek için yapılan sonraki bir düzenleme) rapora hiç
     * girmezdi.
     *
     * @return array{checked: int, unprotected: int, acknowledged: int, broken_id: ?int, broken_ids: list<int>, fingerprints: array<int, string>}
     */
    private function verifyChain(string $chain): array
    {
        $previous = $this->anchor($chain);
        $checked = 0;
        $unprotected = 0;
        $broken = [];
        $fingerprints = [];
        $son = null;

        ActivityLog::query()
            ->where('chain', $chain)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$previous, &$checked, &$unprotected, &$broken, &$fingerprints, &$son): bool {
                foreach ($rows as $log) {
                    if ($log->hash === null) {
                        $unprotected++;

                        continue;
                    }

                    $checked++;

                    if ($log->previous_hash !== $previous || $log->hash !== $this->hashFor($log, $previous)) {
                        $broken[] = $log->id;
                        $fingerprints[$log->id] = $this->fingerprint($log);
                    }

                    $previous = $log->hash;
                    $son = ['id' => $log->id, 'hash' => $log->hash];
                }

                return true;
            });

        // Mühür kuyruk denetiminden ÖNCE uygulanır: kuyruğun silindiği
        // mühürle gizlenemez.
        [$broken, $acknowledged] = $this->applySeal($chain, $broken, $fingerprints);

        $this->checkTail($chain, $son, $broken);

        return [
            'checked' => $checked,
            'unprotected' => $unprotected,
            'acknowledged' => $acknowledged,
            'broken_id' => $broken[0] ?? null,
            'broken_ids' => array_slice($broken, 0, 50),
            'fingerprints' => $fingerprints,
        ];
    }

    /**
     * BİLİNEN ESKİ KIRIKLARIN MÜHRÜ.
     *
     * Zincir geçmişteki bir dönemde yazılan satırlarda tutmuyorsa (bağlantılar
     * sağlam, satır özetleri değil; sonrası tutuyor) satırları yeniden
     * özetlemek dürüst olmaz: o satırlar yazılırken ne yazıldığına dair elde
     * başka kanıt yok. Bunun yerine o anki kırık satırların her birinin İÇERİK PARMAK İZİ
     * anahtarla imzalı bir dosyaya yazılır. Doğrulama, mühürlü bir satırın
     * parmak izi hâlâ aynıysa onu "bilinen eski kırık" sayar; satır sonradan
     * değiştirildiyse parmak izi tutmaz ve yeniden KIRIK görünür. Mührün dışında
     * kalan her kırık olduğu gibi raporlanır.
     *
     * Mühür satırı onarmaz ve bütünlüğü geriye dönük kanıtlamaz; yalnızca
     * "bugünden itibaren bu kırıklar bilinen ve donmuş" der. Budamayla silinen
     * mühürlü satırlar sorun değildir (yalnızca var olanlar karşılaştırılır).
     *
     * @return array{chains: array<string, int>, total: int, path: string}
     */
    public function seal(string $note): array
    {
        $entries = [];
        $counts = [];

        foreach (self::CHAINS as $chain) {
            $sonuc = $this->verifyChain($chain);
            $entries[$chain] = $sonuc['fingerprints'];
            $counts[$chain] = count($sonuc['fingerprints']);
        }

        $body = ['version' => 1, 'created_at' => now()->toIso8601String(), 'note' => $note, 'entries' => $entries];
        $path = $this->sealPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }

        file_put_contents($path, json_encode(['body' => $body, 'signature' => $this->sealSignature($body)], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($path, 0640);

        return ['chains' => $counts, 'total' => array_sum($counts), 'path' => $path];
    }

    /**
     * @param  list<int>  $broken
     * @param  array<int, string>  $fingerprints
     * @return array{0: list<int>, 1: int}
     */
    private function applySeal(string $chain, array $broken, array $fingerprints): array
    {
        if ($broken === []) {
            return [[], 0];
        }

        $entries = $this->readSeal()[$chain] ?? [];
        $kalan = [];
        $kabul = 0;

        foreach ($broken as $id) {
            if (isset($entries[$id]) && hash_equals((string) $entries[$id], $fingerprints[$id])) {
                $kabul++;
            } else {
                $kalan[] = $id;
            }
        }

        return [$kalan, $kabul];
    }

    /**
     * İmzası geçerli mührün satır parmak izleri; mühür yoksa ya da imza
     * tutmuyorsa boş (hiçbir kırık kabul edilmez).
     *
     * @return array<string, array<int, string>>
     */
    private function readSeal(): array
    {
        $path = $this->sealPath();

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['body'] ?? null) || ! is_string($data['signature'] ?? null)) {
            return [];
        }

        if (! hash_equals($this->sealSignature($data['body']), $data['signature'])) {
            return [];
        }

        return is_array($data['body']['entries'] ?? null) ? $data['body']['entries'] : [];
    }

    private function sealPath(): string
    {
        return storage_path('app/private/zincir-muhru.json');
    }

    /** @param array<string, mixed> $body */
    private function sealSignature(array $body): string
    {
        return hash_hmac('sha256', 'muhur-v1|'.json_encode($body, JSON_THROW_ON_ERROR), (string) config('activity.chain_key'));
    }

    /** Satırın İÇERİĞİNİN (özet dahil) kısa, anahtarlı parmak izi. */
    private function fingerprint(ActivityLog $log): string
    {
        $payload = implode('|', [
            $log->id,
            (string) $log->hash,
            (string) $log->previous_hash,
            $log->created_at?->format('Y-m-d H:i:s') ?? '',
            (string) $log->user_id,
            (string) $log->subject_type,
            (string) $log->subject_id,
            $log->channel instanceof \BackedEnum ? (string) $log->channel->value : (string) $log->channel,
            (string) $log->action,
            (string) $log->description,
            (string) $log->reason,
            (string) $log->ip_address,
        ]);

        return substr(hash_hmac('sha256', 'parmak|'.$payload, (string) config('activity.chain_key')), 0, 24);
    }

    /**
     * Son nokta hâlâ yerinde mi (bkz. rememberTail)?
     *
     * Nokta henüz yoksa (bu özellikten önce yazılmış canlı zincir) doğrulanan
     * son satır nokta yapılır: var olan satırlar olduğu gibi doğrulanmaya
     * devam eder, koruma bu andan itibaren başlar. Zincir zaten kırıksa nokta
     * kurulmaz; kırık bir zincirin ucuna güvenilmez.
     *
     * @param  array{id: int, hash: string}|null  $son
     * @param  list<int>  $broken
     */
    private function checkTail(string $chain, ?array $son, array &$broken): void
    {
        $nokta = Cache::get($this->tailKey($chain));

        if (is_array($nokta) && isset($nokta['id'], $nokta['hash'])) {
            $hash = ActivityLog::query()->where('chain', $chain)->whereKey($nokta['id'])->value('hash');

            if ($hash !== $nokta['hash']) {
                $broken[] = (int) $nokta['id'];
            }

            return;
        }

        if ($son !== null && $broken === []) {
            Cache::forever($this->tailKey($chain), $son);
        }
    }

    /**
     * En son doğrulama sonucu. Ekran her açılışta yüz bin satırı yeniden
     * özetlemez; zamanlanmış doğrulamanın bıraktığı sonucu okur.
     *
     * @return array{ok: bool, checked: int, unprotected: int, broken_id: ?int, checked_at: string}|null
     */
    public function lastVerification(): ?array
    {
        $result = Cache::get((string) config('activity.verification_key'));

        return is_array($result) ? $result : null;
    }

    /**
     * Budama sonrası zincirin yeni başlangıç noktası.
     *
     * Saklama süresi dolan satırlar silindiğinde kalan ilk satırın "önceki"si
     * artık veritabanında yoktur. Silinen son satırın özeti çapa olarak
     * saklanır ve doğrulama oradan devam eder; bu olmadan her budama zinciri
     * kırık gösterirdi.
     */
    public function anchorTo(?string $hash, string $chain = self::AUDIT): void
    {
        Cache::forever($this->anchorKey($chain), $hash);
    }

    private function anchor(string $chain): ?string
    {
        $anchor = Cache::get($this->anchorKey($chain));

        return is_string($anchor) ? $anchor : null;
    }

    /**
     * Çapa anahtarı zincir başınadır; denetim zincirininki eski adıyla kalır
     * ki var olan kurulumdaki çapa kaybolmasın.
     */
    private function anchorKey(string $chain): string
    {
        $key = (string) config('activity.chain_anchor_key');

        return $chain === self::AUDIT ? $key : "{$key}:{$chain}";
    }

    /**
     * Satırın özeti.
     *
     * Özete giren alanlar kaydın ANLAMINI taşıyan alanlardır; biri
     * değiştirilirse özet tutmaz. Sıralama sabittir, aksi hâlde aynı satır
     * farklı özetler üretirdi.
     */
    private function hashFor(ActivityLog $log, ?string $previous): string
    {
        $ortak = [
            $previous ?? '',
            $log->created_at?->format('Y-m-d H:i:s.u') ?? '',
            (string) $log->user_id,
            (string) $log->subject_type,
            (string) $log->subject_id,
            $log->channel instanceof \BackedEnum ? (string) $log->channel->value : (string) $log->channel,
            (string) $log->action,
            (string) $log->description,
        ];

        // Satır kendi biçimini söyler; doğrulama o biçimle hesaplar.
        $payload = implode('|', ((int) $log->chain_version) >= 2
            ? [...$ortak, (string) $log->reason, (string) $log->ip_address]
            : [...$ortak, (string) $log->ip_address]);

        return hash_hmac('sha256', $payload, (string) config('activity.chain_key'));
    }
}
