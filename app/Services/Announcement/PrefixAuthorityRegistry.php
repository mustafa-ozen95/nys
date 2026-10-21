<?php

declare(strict_types=1);

namespace App\Services\Announcement;

use Illuminate\Contracts\Container\Container;

/**
 * config/announcements.php içindeki kaynak listesini nesneye çevirir.
 *
 * Kayıt defterinin ayrı durmasının sebebi, denetleyicinin de komutun da aynı
 * listeyi görmesi; liste iki yerde çözülseydi biri er ya da geç kayardı.
 */
class PrefixAuthorityRegistry
{
    /** @var array<int, PrefixAuthority>|null */
    private ?array $authorities = null;

    public function __construct(private Container $container) {}

    /**
     * @return array<int, PrefixAuthority>
     */
    public function all(): array
    {
        return $this->authorities ??= collect(config('announcements.authorization.authorities'))
            ->map(fn (string $class): PrefixAuthority => $this->container->make($class))
            ->all();
    }
}
