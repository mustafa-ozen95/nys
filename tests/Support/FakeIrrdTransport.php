<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Announcement\Transport\IrrdException;
use App\Services\Announcement\Transport\IrrdTransport;
use RuntimeException;

/**
 * Kaydedilmiş RADB yanıtlarını döndüren sahte IRR taşıması.
 *
 * Üretim sınıfı testte asla gerçek soket açmaz (bkz. .ai/rules/services.md);
 * bu sınıf TestCase'te varsayılan olarak bağlanır ve HAZIRLANMAMIŞ bir prefix
 * sorulduğunda susmaz, patlar — Http::preventStrayRequests() ile aynı mantık.
 * Sessizce "kayıt yok" dönseydi hazırlamayı unutan test, engellemeyi doğru
 * çalışıyor sanırdı.
 *
 * Yanıtlar RADB yanıt biçimini taklit eden anonim örneklerdir
 * (tests/Fixtures/irr): RPKI türevi nesneler de içinde durur, çünkü
 * elemenin sınandığı yer tam orası.
 */
final class FakeIrrdTransport implements IrrdTransport
{
    /** @var array<string, ?string> */
    private array $objects = [];

    /** @var list<string> Bu taşımaya sorulan prefixler */
    public array $queried = [];

    private ?string $failure = null;

    /**
     * Kaydedilmiş örnek yanıtı yükler.
     */
    public function fixture(string $prefix): self
    {
        $path = __DIR__.'/../Fixtures/irr/'.self::slug($prefix).'.txt';

        if (! is_file($path)) {
            throw new RuntimeException("IRR örneği yok: {$path}");
        }

        return $this->with($prefix, file_get_contents($path));
    }

    public function with(string $prefix, ?string $rpsl): self
    {
        $this->objects[$prefix] = $rpsl;

        return $this;
    }

    /**
     * Kaydı olmayan prefix: sunucu "D" (bulunamadı) döner.
     */
    public function without(string $prefix): self
    {
        return $this->with($prefix, null);
    }

    public function fail(string $message = 'IRR sunucusuna ulaşılamadı.'): self
    {
        $this->failure = $message;

        return $this;
    }

    /**
     * @param  array<int, string>  $prefixes
     * @return array<string, ?string>
     */
    public function routeObjects(array $prefixes): array
    {
        $this->queried = [...$this->queried, ...$prefixes];

        if ($this->failure !== null) {
            throw new IrrdException($this->failure);
        }

        $objects = [];

        foreach ($prefixes as $prefix) {
            if (! array_key_exists($prefix, $this->objects)) {
                throw new RuntimeException(
                    "Sahte IRR taşıması {$prefix} için hazırlanmadı: testte fixture() ya da without() çağırın."
                );
            }

            $objects[$prefix] = $this->objects[$prefix];
        }

        return $objects;
    }

    private static function slug(string $prefix): string
    {
        return trim((string) preg_replace('/[^0-9a-z]+/', '-', strtolower($prefix)), '-');
    }
}
