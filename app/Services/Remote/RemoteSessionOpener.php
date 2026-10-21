<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\ActivityChannel;
use App\Enums\ConnectionProtocol;
use App\Models\KonsolOturumu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Uzak oturumu protokolüne göre açar.
 *
 * SSH tarayıcı içi terminale (ttyd), RDP Guacamole'a gider. Controller bu
 * ayrımı bilmez; hangi protokolün nereye gittiği tek yerde durur.
 */
class RemoteSessionOpener
{
    public function __construct(
        private TargetResolver $resolver,
        private TtydManager $ttyd,
        private GuacamoleService $guacamole,
        private RemoteSessionStore $sessions,
    ) {}

    /**
     * @return array{token: string, label: string, protocol: string, url: string, channel: ActivityChannel}
     */
    public function open(Model $subject, int $userId): array
    {
        $target = $this->resolver->resolve($subject);
        $token = Str::uuid()->toString();

        $session = $target->isSsh()
            ? $this->ttyd->start($target, $token)
            : $this->guacamole->open($target);

        // Oturumun sahibi kaydedilir: proxy bekçisi her istekte bunu sorar.
        $this->sessions->put($token, $session + [
            'label' => $target->label,
            'protocol' => $target->protocol->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'user_id' => $userId,
        ]);

        $this->sessions->rememberForUser($userId, $target->protocol, $token);

        /*
         * DENETİM İZİ önbellekten ayrı durur: önbellekteki kayıt canlı tutma
         * kesilince sessizce düşer ve geriye "kapandı" satırı olmayan bir
         * oturum kalırdı (bkz. konsol_oturumlari göçü).
         */
        KonsolOturumu::create([
            'token' => $token,
            'user_id' => $userId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'label' => $target->label,
            'protocol' => $target->protocol,
            'port' => $session['port'] ?? null,
            'opened_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [
            'token' => $token,
            'label' => $target->label,
            'protocol' => $target->protocol->value,
            'url' => $session['path'],
            'channel' => $target->isSsh() ? ActivityChannel::Ssh : ActivityChannel::Rdp,
        ];
    }

    /**
     * Kapatılan oturumun künyesi döner; controller bunu kayda yazar.
     * Zaten kapalıysa null.
     *
     * @return array{label: string, protocol: string, channel: ActivityChannel, subject: ?Model}|null
     */
    public function close(string $token): ?array
    {
        $session = $this->sessions->get($token);

        if ($session === null) {
            return null;
        }

        // Yalnızca ttyd'nin kapatılacak bir süreci var; Guacamole oturumu
        // kendi anahtarının süresi dolunca düşer.
        if (isset($session['port'])) {
            $this->ttyd->stop((int) $session['port']);
        }

        $this->sessions->forget($token);
        $this->iziKapat($token, 'kullanici');

        return [
            'label' => $session['label'],
            'protocol' => $session['protocol'],
            'channel' => $session['protocol'] === ConnectionProtocol::Ssh->value
                ? ActivityChannel::Ssh
                : ActivityChannel::Rdp,
            'subject' => $this->subject($session),
        ];
    }

    /** Denetim izini kapatır. İz yoksa (eski oturum) sessizce geçilir. */
    public function iziKapat(string $token, string $sebep): void
    {
        KonsolOturumu::query()
            ->where('token', $token)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_reason' => $sebep]);
    }

    /** Canlı tutma: oturum hâlâ ayakta. */
    public function izeDokun(string $token): void
    {
        KonsolOturumu::query()
            ->where('token', $token)
            ->whereNull('closed_at')
            ->update(['last_seen_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function subject(array $session): ?Model
    {
        $class = Relation::getMorphedModel($session['subject_type']) ?? $session['subject_type'];

        return class_exists($class) ? $class::find($session['subject_id']) : null;
    }
}
