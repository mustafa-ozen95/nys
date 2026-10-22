<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;

/**
 * Kimlik doğrulayan sahte Junos cihazı.
 *
 * FakeJunosDevice her kimliği kabul eder; parola değiştirme yolunu sınamak
 * için bu yetmez. Buradaki cihaz yerel hesapları TUTAR: yanlış parolayla
 * oturum açılmaz, commit edilen bir parola değişimi cihazda gerçekten
 * geçerli olur ve silinen hesap bir daha giriş yapamaz.
 *
 * Sıralamayı bu yüzden gerçekten kanıtlar: parolası değişen hesapla onay
 * oturumu açmaya çalışan bir kod burada da kırılır — cihazda kırıldığı gibi.
 *
 * Yapılandırma yalnızca COMMIT ile geçerli olur; yüklenip commit edilmeyen
 * değişiklik oturum kapanınca düşer.
 */
final class FakeJunosAccounts implements NetconfTransport
{
    /** @var list<array{host: string, username: string, password: string, accepted: bool}> */
    public array $attempts = [];

    /** @var list<string> */
    private array $pending = [];

    /** @var list<string> Cihazın oturum açtırmadığı hesaplar */
    private array $rejected = [];

    /** @var list<string> Parolası değişince oturum açtırmayacak hesaplar */
    private array $rejectOnChange = [];

    private ?string $activeUser = null;

    /**
     * @param  array<string, string>  $accounts  kullanıcı adı => parola
     */
    public function __construct(
        private array $accounts = ['admin' => 'eskiparola'],
        private FakeJunosDevice $inner = new FakeJunosDevice,
    ) {}

    /**
     * @return array<string, string>
     */
    public function accounts(): array
    {
        return $this->accounts;
    }

    public function payload(): string
    {
        return $this->inner->payload();
    }

    /**
     * Cihaz bu hesaba oturum açtırmaz. Parolası doğru olsa bile: hesap
     * kilitli, sınıfı yetersiz ya da cihaz bir sebeple reddediyor.
     */
    public function rejectUser(string $name): void
    {
        if (! in_array($name, $this->rejected, true)) {
            $this->rejected[] = $name;
        }
    }

    public function acceptUser(string $name): void
    {
        $this->rejected = array_values(array_filter(
            $this->rejected,
            static fn (string $registered): bool => $registered !== $name,
        ));
    }

    /**
     * Parola değişimi commit edilir ama hesap SONRASINDA giriş yapamaz.
     *
     * Gerçek hayattaki en tehlikeli durum budur: cihaz yazmayı kabul eder,
     * panel bir daha bağlanamaz. Kurtarma hesabı tam bunun için var.
     */
    public function rejectOnNextPasswordChange(string $name): void
    {
        $this->rejectOnChange[] = $name;
    }

    /**
     * Cihazdaki parolayı panelin bilgisi dışında değiştirir.
     */
    public function setPassword(string $name, string $password): void
    {
        $this->accounts[$name] = $password;
    }

    public function open(string $host, int $port, string $username, string $password, int $timeout): void
    {
        $accept = isset($this->accounts[$username])
            && hash_equals($this->accounts[$username], $password)
            && ! in_array($username, $this->rejected, true);

        $this->attempts[] = compact('host', 'username', 'password') + ['accepted' => $accept];

        if (! $accept) {
            throw new NetconfException("{$host} NETCONF kimlik doğrulaması başarısız.");
        }

        $this->activeUser = $username;
        $this->pending = [];

        $this->inner->open($host, $port, $username, $password, $timeout);
    }

    public function write(string $data): void
    {
        $this->inner->write($data);
        $this->track($data);
    }

    public function readUntil(string $terminator): string
    {
        return $this->inner->readUntil($terminator);
    }

    /**
     * base:1.0 konuşur; parçalı okuma bu sahtede hiç çağrılmaz.
     */
    public function readExactly(int $bytes): string
    {
        return $this->inner->readExactly($bytes);
    }

    public function close(): void
    {
        $this->activeUser = null;
        $this->pending = [];
        $this->inner->close();
    }

    /**
     * Yüklenen hesap değişikliklerini biriktirir, commit'te uygular.
     */
    private function track(string $data): void
    {
        if (str_contains($data, '<load-configuration')) {
            $this->pending[] = $data;

            return;
        }

        // Yalnızca gerçek commit uygular; "check" bir şey değiştirmez.
        if (str_contains($data, '<commit-configuration') && ! str_contains($data, '<check/>')) {
            foreach ($this->pending as $document) {
                $this->apply($document);
            }

            $this->pending = [];
        }

        if (str_contains($data, '<close-configuration/>')) {
            $this->pending = [];
        }
    }

    private function apply(string $document): void
    {
        if (preg_match_all('#<user delete="delete"><name>([^<]+)</name></user>#', $document, $deleted)) {
            foreach ($deleted[1] as $name) {
                unset($this->accounts[$name]);
            }
        }

        if (preg_match_all('#<user><name>([^<]+)</name>.*?</user>#s', $document, $written, PREG_SET_ORDER)) {
            foreach ($written as [$block, $name]) {
                if (preg_match('#<plain-text-password-value>([^<]*)</plain-text-password-value>#', $block, $password)) {
                    $this->accounts[$name] = $password[1];

                    if (in_array($name, $this->rejectOnChange, true)) {
                        $this->rejectUser($name);
                        $this->rejectOnChange = array_values(array_filter(
                            $this->rejectOnChange,
                            static fn (string $registered): bool => $registered !== $name,
                        ));
                    }
                } elseif (! isset($this->accounts[$name])) {
                    // Parolasız hesap: cihazda vardır ama NETCONF ile girilemez.
                    $this->accounts[$name] = "\0yok";
                }
            }
        }
    }
}
