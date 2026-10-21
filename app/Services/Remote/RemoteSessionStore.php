<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\ConnectionProtocol;
use Illuminate\Support\Facades\Cache;

/**
 * Açık uzak oturumların kaydı.
 *
 * Oturumlar kısa ömürlüdür ve sunucu yeniden başlatılınca anlamlarını yitirir;
 * bu yüzden veritabanında değil önbellekte tutulur.
 */
class RemoteSessionStore
{
    private function key(string $token): string
    {
        return config('remote.session.cache_prefix').$token;
    }

    private function portKey(int $port): string
    {
        return config('remote.session.cache_prefix')."port:{$port}";
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function put(string $token, array $session): void
    {
        Cache::put($this->key($token), $session, (int) config('remote.session.ttl'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        return Cache::get($this->key($token));
    }

    public function touch(string $token): bool
    {
        $session = $this->get($token);

        if ($session === null) {
            return false;
        }

        $this->put($token, $session);

        if (isset($session['port'])) {
            Cache::put($this->portKey($session['port']), $token, (int) config('remote.session.ttl'));
        }

        return true;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * Portu bu oturuma ayırır; port zaten kullanımdaysa false döner.
     */
    public function reservePort(int $port, string $token): bool
    {
        return Cache::add($this->portKey($port), $token, (int) config('remote.session.ttl'));
    }

    public function releasePort(int $port): void
    {
        Cache::forget($this->portKey($port));
    }

    /**
     * Bir porta ayrılmış oturum. Proxy bekçisi bunu kullanır.
     *
     * @return array<string, mixed>|null
     */
    public function forPort(int $port): ?array
    {
        $token = Cache::get($this->portKey($port));

        return is_string($token) ? $this->get($token) : null;
    }

    /**
     * Kullanıcının açık oturumu. Guacamole proxy'sinde port yoktur; oturum
     * kullanıcı ve protokol üzerinden bulunur.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(int $userId, ConnectionProtocol $protocol): ?array
    {
        $token = Cache::get($this->userKey($userId, $protocol));

        return is_string($token) ? $this->get($token) : null;
    }

    public function rememberForUser(int $userId, ConnectionProtocol $protocol, string $token): void
    {
        Cache::put($this->userKey($userId, $protocol), $token, (int) config('remote.session.ttl'));
    }

    private function userKey(int $userId, ConnectionProtocol $protocol): string
    {
        return config('remote.session.cache_prefix')."user:{$userId}:{$protocol->value}";
    }
}
