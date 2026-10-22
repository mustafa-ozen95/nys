<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Panelin yeni sürümü var mı?
 *
 * Çalışan sürüm (imaja derlemede yazılan commit, APP_VERSION) GitHub'daki
 * main dalının SON BAŞARILI İMAJ DERLEMESİYLE karşılaştırılır. Son commit
 * değil: imajı henüz derlenmemiş ya da derlemesi düşmüş bir commit
 * kurulamaz, onu "yeni sürüm" diye göstermek yanıltırdı.
 *
 * YÖN ÖLÇÜLÜR. "Farklı" demek yetmiyor: çalışan sürüm kayıttakinden YENİ de
 * olabilir (yeni commit'in derlemesi düşmüş, elle kurulmuş bir imaj). O
 * zaman "güncelleme" denen şey geri almak olurdu. Karşılaştırma GitHub'ın
 * compare'iyle yapılır ve kaç commit geride, kaç commit ileride olduğu ayrı
 * ayrı söylenir.
 *
 * JETON İSTEĞE BAĞLI. Depo açıkken jetonsuz sorulur (GitHub'ın jetonsuz
 * sınırı IP başına saatte 60 istek; günde bir tur ve elle basılan düğme
 * için yeter). Özel depo jeton ister; jetonsuz sorulan özel depo 404 döner.
 *
 * YALNIZCA BAKAR, KURMAZ. Güncelleme sunucudan, onaylı yapılır; panel
 * kendini güncelleyebilseydi, ele geçen bir panel sunucuyu yönetebilirdi.
 *
 * Sonuç depolama hacminde durur (storage/app/update/state.json): günlük
 * tur yazar, ekran ve uyarı şeridi okur. Jeton sonuçta ve iletilerde geçmez.
 *
 * Panele özgü olan yalnızca config/update.php'deki depo adıdır.
 */
class UpdateCheck
{
    public const STATUSES = ['guncel', 'yeni', 'ileride', 'ayrisik', 'bilinmiyor', 'hata'];

    /** Ekranda gösterilen yeni commit sayısı; fazlası "ve N commit daha" olur. */
    private const MAX_COMMITS = 20;

    private const API = 'https://api.github.com';

    private string $directory;

    public function __construct(string $directory = '')
    {
        $this->directory = $directory !== '' ? rtrim($directory, '/') : storage_path('app/update');
    }

    /** Çalışan commit (40 haneli); imaj sürüm bilgisi olmadan derlendiyse null. */
    public function version(): ?string
    {
        $version = strtolower(trim((string) config('update.version')));

        return preg_match('/^[0-9a-f]{40}$/', $version) === 1 ? $version : null;
    }

    public function repository(): string
    {
        return (string) config('update.repo');
    }

    /**
     * GitHub'a sorar, sonucu yazar ve döndürür. Hata FIRLATMAZ: sonuç
     * "hata" durumuyla yazılır; günlük tur da düğme de aynı yoldan geçer.
     *
     * @return array<string, mixed>
     */
    public function check(string $triggering): array
    {
        $result = ['calisan' => $this->version(), 'depo' => $this->repository(), 'tetikleyen' => $triggering];

        try {
            $result = [...$result, ...$this->ask($result['calisan'])];
        } catch (Throwable $e) {
            $result = [...$result, 'durum' => 'hata', 'aciklama' => $this->clean($e->getMessage())];
        }

        $result['zaman'] = date('c');
        $this->write($result);

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        $path = $this->directory.'/state.json';
        $veri = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($veri) && in_array($veri['durum'] ?? null, self::STATUSES, true) ? $veri : null;
    }

    /**
     * Uyarı şeridi için: son kontrol yeni sürüm bulduysa VE o kontrol şu an
     * çalışan sürümle yapıldıysa. Panel güncellendikten sonra eski sonuç
     * yeniden kontrol beklemeden susar.
     */
    public function hasNew(): bool
    {
        $last = $this->last();

        return ($last['durum'] ?? null) === 'yeni' && ($last['calisan'] ?? null) === $this->version();
    }

    /** @return array<string, mixed> */
    private function ask(?string $running): array
    {
        $token = trim((string) config('update.token'));

        $http = Http::baseUrl(self::API)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(10);

        if ($token !== '') {
            $http = $http->withToken($token);
        }

        $is = $this->take($http, '/repos/'.$this->repository().'/actions/workflows/'.config('update.workflow', 'image.yml').'/runs', [
            'branch' => config('update.branch', 'main'),
            'status' => 'success',
            'event' => 'push',
            'per_page' => 1,
        ])['workflow_runs'][0] ?? null;

        if (! is_array($is) || ! is_string($is['head_sha'] ?? null)) {
            return ['durum' => 'hata', 'aciklama' => 'Depoda başarılı bir imaj derlemesi bulunamadı.'];
        }

        $last = ['son' => $is['head_sha'], 'son_tarih' => $is['updated_at'] ?? null];

        if ($running === null) {
            return [...$last, 'durum' => 'bilinmiyor', 'aciklama' => 'Çalışan sürüm bilinmiyor: imaj sürüm bilgisi olmadan derlenmiş. Son derleme gösteriliyor.'];
        }

        if ($running === $is['head_sha']) {
            return [...$last, 'durum' => 'guncel', 'geride' => 0, 'ileride' => 0, 'aciklama' => 'Panel son sürümde.'];
        }

        $diff = $this->take($http, '/repos/'.$this->repository().'/compare/'.$running.'...'.$is['head_sha']);
        $behind = (int) ($diff['ahead_by'] ?? 0);
        $later = (int) ($diff['behind_by'] ?? 0);

        $commits = collect($diff['commits'] ?? [])
            ->reverse()
            ->take(self::MAX_COMMITS)
            ->map(fn (array $c): array => [
                'sha' => (string) ($c['sha'] ?? ''),
                'baslik' => mb_substr(strtok((string) ($c['commit']['message'] ?? ''), "\n") ?: '', 0, 200),
                'tarih' => $c['commit']['committer']['date'] ?? null,
            ])
            ->values()
            ->all();

        return [...$last, 'geride' => $behind, 'ileride' => $later, 'commitler' => $commits, ...match ($diff['status'] ?? null) {
            'ahead' => ['durum' => 'yeni', 'aciklama' => $behind.' commit geride.'],
            'identical' => ['durum' => 'guncel', 'aciklama' => 'Panel son sürümde.'],
            'behind' => ['durum' => 'ileride', 'aciklama' => 'Çalışan sürüm son başarılı derlemeden '.$later.' commit ileride: daha yeni bir commit\'ten kurulmuş. "Güncellemek" geri almak olur.'],
            default => ['durum' => 'ayrisik', 'aciklama' => 'Çalışan sürüm main dalında değil ('.$behind.' geride, '.$later.' ileride): ayrı bir daldan ya da elle derlenmiş.'],
        }];
    }

    /**
     * @param  array<string, mixed>  $sorgu
     * @return array<string, mixed>
     */
    private function take(PendingRequest $http, string $path, array $query = []): array
    {
        try {
            $response = $http->get($path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('GitHub\'a erişilemedi (ağ kapalı ya da yanıt yok).');
        }

        $tokenless = trim((string) config('update.token')) === '';

        return match (true) {
            $response->successful() => (array) $response->json(),
            $response->status() === 401 => throw new RuntimeException('GitHub jetonu reddetti (401): süresi dolmuş ya da silinmiş olabilir.'),
            $tokenless && $response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0' => throw new RuntimeException('GitHub jetonsuz istek sınırı doldu (403, saatte 60): bir saat sonra yeniden deneyin ya da UPDATE_TOKEN tanımlayın.'),
            $tokenless && in_array($response->status(), [403, 404], true) => throw new RuntimeException('Depo bulunamadı ya da özel ('.$response->status().'): '.$this->repository().' özelse UPDATE_TOKEN gerekir.'),
            in_array($response->status(), [403, 404], true) => throw new RuntimeException('Jeton bu depoyu görmüyor ('.$response->status().'): '.$this->repository().' için Contents ve Actions okuma yetkisi gerekir.'),
            default => throw new RuntimeException('GitHub yanıtı: '.$response->status().' '.$this->message($response)),
        };
    }

    private function message(Response $response): string
    {
        return mb_substr((string) ($response->json('message') ?? ''), 0, 200);
    }

    /** Jeton hiçbir iletide geçmez. */
    private function clean(string $message): string
    {
        $token = trim((string) config('update.token'));

        return mb_substr($token !== '' ? str_replace($token, '***', $message) : $message, 0, 300);
    }

    /** @param  array<string, mixed>  $sonuc */
    private function write(array $result): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Dizin açılamadı: '.$this->directory);
        }

        $temporary = $this->directory.'/.state.json.'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false
            || ! @rename($temporary, $this->directory.'/state.json')) {
            @unlink($temporary);
            throw new RuntimeException('Yazılamadı: '.$this->directory.'/state.json');
        }
    }
}
