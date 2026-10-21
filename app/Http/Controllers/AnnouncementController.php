<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Http\Requests\Announcement\AnnouncementRequest;
use App\Jobs\SendAnnouncementJob;
use App\Models\AnnouncementMail;
use App\Models\AnnouncementRecipient;
use App\Services\ActivityRecorder;
use App\Services\Announcement\PrefixAuthorizationChecker;
use App\Services\Announcement\PrefixAuthorizationReport;
use App\Services\AnnouncementService;
use App\Services\Bgp\AsnDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Prefix anons talebi.
 *
 * İki adımlıdır: önizleme de gönderim de sunucuda üretilir, böylece
 * kullanıcının gördüğü metin ile gönderilen metin aynıdır.
 */
class AnnouncementController extends Controller implements HasMiddleware
{
    /**
     * Anons talebi dış dünyaya e-posta gönderir: yetkisiz bir hesap sizin
     * adınıza NOC ve peering adreslerine talep yollayabilirdi.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.AnnouncementRecipient::class, only: ['create', 'preview']),
            new Middleware('can:create,'.AnnouncementRecipient::class, only: ['send']),
        ];
    }

    public function __construct(
        private AnnouncementService $announcements,
        private ActivityRecorder $activity,
        private AsnDirectory $asns,
        private PrefixAuthorizationChecker $authorization,
    ) {}

    public function create(Request $request): View
    {
        return view('announcements.create', $this->page($request) + [
            'messages' => null,
            'authorization' => null,
        ]);
    }

    public function preview(AnnouncementRequest $request): View
    {
        // Gönder adımı aynı girdiyi tekrar kullanır; old() ile forma geri döner.
        $request->flash();

        return view('announcements.create', $this->page($request) + [
            'messages' => $this->compose($request),
            'authorization' => $this->audit($request),
        ]);
    }

    public function send(AnnouncementRequest $request): RedirectResponse
    {
        /*
         * Yetki denetimi ÖNİZLEMEDE DEĞİL BURADA bağlayıcıdır. Önizleme
         * ekranı atlanabilir, gizli alanlar değiştirilebilir; kararı yalnızca
         * gönderim anında sunucunun kendi sorduğu soru verir.
         */
        $authorization = $this->audit($request);

        if (! $authorization->passes()) {
            $request->flash();

            $this->activity->record(
                null,
                'announcement_blocked',
                $authorization->refusal(),
                ActivityChannel::Crud,
            );

            return redirect()
                ->route('announcements.create')
                ->withErrors(['prefixes' => $authorization->refusal()]);
        }

        $messages = $this->compose($request);

        foreach ($messages as $message) {
            $record = AnnouncementMail::create([
                'user_id' => $request->user()->id,
                'recipient_label' => $message['label'],
                'recipient_email' => $message['email'],
                'locale' => $message['locale'],
                'subject' => $message['subject'],
                'body' => $message['body'],
                'prefixes' => $request->validated('prefixes'),
                'asn' => $request->validated('asn'),
                'downstream_asn' => $request->validated('downstream'),
            ]);

            SendAnnouncementJob::dispatch($record->id);
        }

        $this->activity->record(
            null,
            'announcement_sent',
            count($messages).' alıcıya prefix anons talebi kuyruğa alındı.',
            ActivityChannel::Crud,
        );

        return redirect()
            ->route('announcements.create')
            ->with('status', count($messages).' alıcıya gönderim kuyruğa alındı.');
    }

    /**
     * Formun her açılışında ortak veriler: alıcılar, varsayılan ASN ve
     * gönderim geçmişi.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request): array
    {
        return [
            'recipients' => $this->announcements->recipients($request->user()),
            'asnOptions' => $this->asns->options(),
            'defaultAsn' => $this->asns->default(),
            'history' => AnnouncementMail::query()
                ->with('user')
                ->search($request->string('q')->toString() ?: null)
                ->latest()
                ->limit(config('announcements.history_limit'))
                ->get(),
            'selectedMail' => AnnouncementMail::query()->firstWhere('uuid', $request->string('mail')->toString()),
            'query' => $request->string('q')->toString(),
        ];
    }

    private function audit(AnnouncementRequest $request): PrefixAuthorizationReport
    {
        return $this->authorization->audit(
            $request->validated('prefixes'),
            $request->validated('asn'),
        );
    }

    /**
     * @return array<int, array{label: string, email: string, locale: string, subject: string, body: string}>
     */
    private function compose(AnnouncementRequest $request): array
    {
        return $this->announcements->compose(
            $request->validated('prefixes'),
            $request->validated('asn'),
            $request->validated('downstream'),
            $request->validated('recipients'),
            $request->user(),
        );
    }
}
