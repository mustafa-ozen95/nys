<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MailLocale;
use App\Http\Requests\Announcement\AnnouncementRecipientRequest;
use App\Models\AnnouncementRecipient;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Anons taleplerinin kime, hangi dilde gideceğinin yönetimi.
 */
class AnnouncementRecipientController extends Controller implements HasMiddleware
{
    public function __construct(private ActivityRecorder $activity) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.AnnouncementRecipient::class, only: ['index']),
            new Middleware('can:create,'.AnnouncementRecipient::class, only: ['store']),
            new Middleware('can:update,recipient', only: ['update']),
            new Middleware('can:delete,recipient', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        return view('announcements.recipients', [
            'recipients' => AnnouncementRecipient::query()->visibleTo($request->user())->orderBy('label')->get(),
            'localeOptions' => $this->localeOptions(),
        ]);
    }

    public function store(AnnouncementRecipientRequest $request): RedirectResponse
    {
        $recipient = AnnouncementRecipient::create($request->recipientAttributes());

        $this->activity->created($recipient, "Anons alıcısı eklendi: {$recipient->email}");

        return $this->back("{$recipient->email} eklendi.");
    }

    public function update(
        AnnouncementRecipientRequest $request,
        AnnouncementRecipient $recipient,
    ): RedirectResponse {
        $recipient->update($request->recipientAttributes());

        $this->activity->updated($recipient, "Anons alıcısı güncellendi: {$recipient->email}");

        return $this->back("{$recipient->email} güncellendi.");
    }

    public function destroy(AnnouncementRecipient $recipient): RedirectResponse
    {
        $email = $recipient->email;

        $this->activity->deleted($recipient, "Anons alıcısı silindi: {$email}");
        $recipient->delete();

        return $this->back("{$email} silindi.");
    }

    private function back(string $status): RedirectResponse
    {
        return redirect()->route('announcements.recipients.index')->with('status', $status);
    }

    /**
     * @return array<string, string>
     */
    private function localeOptions(): array
    {
        return collect(MailLocale::cases())
            ->mapWithKeys(fn (MailLocale $locale): array => [$locale->value => $locale->label()])
            ->all();
    }
}
