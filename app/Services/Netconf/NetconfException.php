<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

/**
 * Cihazla NETCONF konuşulamadığında ya da cihaz bir hata döndürdüğünde
 * fırlatılır.
 *
 * Eşitleme işleri bunu yakalar ve envanteri olduğu gibi bırakır. Panelden
 * gelen istekte ise kullanıcıya toast olarak döner; 500 sayfası gösterilmez,
 * çünkü bu beklenen bir sonuçtur (cihaz kapalı, erişim yok, config hatalı).
 *
 * İstisna cihazın CEVABINI YAPISIYLA taşır. Metni birleştirip atmak, sorunu
 * bulan bilgiyi atmaktı: hangi satır (error-path), hangi kural (error-tag),
 * hangi daemon (source-daemon) ve kaç ayrı hata olduğu. Cihaz işlem kaydı
 * (device_operations) bu alanlardan doluyor.
 */
class NetconfException extends RuntimeException
{
    /** @var list<RpcError> */
    private array $errors = [];

    private ?string $stage = null;

    /** Hangi RPC'de düştü (kök eleman adı) — aşama verilmediğinde yedek. */
    private ?string $rpc = null;

    private ?string $kind = null;

    /**
     * @param  list<RpcError>  $errors
     */
    public static function fromRpcErrors(array $errors, ?string $rpc = null): self
    {
        $exception = new self(
            'Cihaz RPC hatası döndürdü: '.implode(' | ', array_map(
                static fn (RpcError $error): string => $error->line(),
                $errors,
            )),
        );

        $exception->errors = $errors;
        $exception->rpc = $rpc;
        $exception->kind = 'rpc';

        return $exception;
    }

    /**
     * Taşıma katmanı hataları: zaman aşımı, bağlantı kopması, kimlik.
     */
    public static function transport(string $message, string $kind, ?Throwable $previous = null): self
    {
        $exception = new self($message, previous: $previous);
        $exception->kind = $kind;

        return $exception;
    }

    /**
     * @return list<RpcError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Hangi ADIMDA düştü: load, check, commit, confirm, read, connect...
     *
     * Çağıran taraf söyler; RPC adı değildir. "load-configuration" hangi
     * çağrının yapıldığını söyler, "check" ise akışın neresinde olduğumuzu —
     * kullanıcının aradığı ikincisidir.
     */
    public function stage(): ?string
    {
        return $this->stage;
    }

    /**
     * Düşen RPC'nin kök elemanı. Aşama verilmemiş okuma yollarında yedek.
     */
    public function rpc(): ?string
    {
        return $this->rpc;
    }

    public function withStage(string $stage): self
    {
        if ($this->stage === null) {
            $this->stage = $stage;
        }

        return $this;
    }

    /**
     * Hatanın cinsi: rpc | timeout | closed | auth | lock | framing | null.
     * Zaman aşımı ile bağlantı kopması aynı tipe indiğinde kayıt "neden"
     * sorusuna cevap veremiyordu.
     */
    public function kind(): ?string
    {
        return $this->kind ?? 'unknown';
    }

    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
