<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AddressPurpose;
use App\Enums\ConnectionProtocol;
use App\Enums\ServerOs;
use App\Enums\ServerStatus;
use App\Http\Requests\Server\ServerRequest;
use App\Models\Server;
use App\Services\ActivityRecorder;
use App\Services\Addressing\AddressCandidateService;
use App\Services\Server\RoundStatuses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class ServerController extends Controller implements HasMiddleware
{
    public function __construct(
        private ActivityRecorder $activity,
        private AddressCandidateService $candidates,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Server::class, only: ['index']),
            new Middleware('can:create,'.Server::class, only: ['create', 'store']),
            new Middleware('can:update,server', only: ['edit', 'update']),
            new Middleware('can:delete,server', only: ['destroy']),
        ];
    }

    public function index(Request $request, RoundStatuses $turlar): View
    {
        $servers = Server::query()
            ->visibleTo($request->user())
            ->search($request->string('q')->toString() ?: null)
            ->with('latestPasswordRotation')
            ->orderBy('short_name')
            ->get();

        $selected = $servers->firstWhere('uuid', $request->string('server')->toString());

        return view('servers.index', [
            'servers' => $servers,
            'query' => $request->string('q')->toString(),
            // Seçim adreste tutulur: JS kapalıyken de detay açılır ve
            // bağlantı paylaşılabilir olur.
            'selected' => $selected,

            /*
             * SÜREN TURLAR. Ekran ile ilerleme ucu AYNI kaynaktan okur;
             * ikisi ayrı yerde hesaplansaydı biri değiştiğinde diğeri sessizce
             * eskir ve çubuk sayfa yenilenince zıplardı.
             */
            'suren' => $turlar->icin($servers),

            /*
             * Toplu parola penceresinde çizilecek sunucular. Süzme BURADA
             * yapılır, Blade'de değil: ekran hazır veriyi basar.
             *
             * Çizmemek koruma DEĞİLDİR; yetki sunucu tarafında tek tek
             * yeniden sorulur (bkz. ServerPasswordController::toplu).
             */
            'parolaSunuculari' => $servers->filter(
                fn (Server $sunucu): bool => $request->user()->can('rotate', $sunucu),
            ),

            /*
             * Ekleme ve düzenleme AYRI SAYFA değil, listedeki pencereler:
             * kullanıcı listeden ayrılmadan iş görür. Alan seçenekleri her
             * iki pencere için de gerekir; düzenleme verisi yalnızca seçili
             * sunucu varken hazırlanır.
             */
        ] + $this->fieldOptions() + ($selected === null ? [] : $this->formData($selected)));
    }

    /**
     * Ekleme AYRI BİR SAYFA değil.
     *
     * Eski adres ölü bırakılmaz: kullanıcı listeye gönderilir ve pencere
     * orada açılır. Yer imleri ve eski bağlantılar çalışmaya devam eder.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('servers.index', ['ekle' => 1]);
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        $server = Server::create($request->serverAttributes());

        $this->activity->created($server, "Sunucu eklendi: {$server->short_name}");

        return redirect()->route('servers.index')->with('status', "{$server->short_name} eklendi.");
    }

    /**
     * Düzenleme AYRI BİR SAYFA değil.
     *
     * Eski adres ölü bırakılmaz: kullanıcı listeye, seçili sunucuyla
     * gönderilir ve pencere orada açılır. Yer imleri ve eski bağlantılar
     * çalışmaya devam eder.
     */
    public function edit(Server $server): RedirectResponse
    {
        return redirect()->route('servers.index', ['server' => $server->uuid, 'duzenle' => 1]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $server->update($request->serverAttributes());

        $this->activity->updated($server, "Sunucu güncellendi: {$server->short_name}");

        return redirect()->route('servers.index')->with('status', "{$server->short_name} güncellendi.");
    }

    public function destroy(Server $server): RedirectResponse
    {
        $name = $server->short_name;

        $this->activity->deleted($server, "Sunucu silindi: {$name}");
        $server->delete();

        return redirect()->route('servers.index')->with('status', "{$name} silindi.");
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Alan seçenekleri: ekleme ve düzenleme pencerelerinin ORTAK verisi.
     *
     * @return array<string, mixed>
     */
    private function fieldOptions(): array
    {
        return [
            'osOptions' => $this->labels(ServerOs::cases()),
            'protocolOptions' => $this->labels(ConnectionProtocol::cases()),
            'statusOptions' => $this->labels(ServerStatus::cases()),

            /*
             * Adres Adresleme'den seçilir. Elle giriş açıktır ve gereklidir:
             * alan IP yerine ALAN ADI da kabul eder.
             */
            'addressOptions' => $this->candidates->hostOptions([
                AddressPurpose::Management,
                AddressPurpose::Customer,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?Server $server): array
    {
        return $this->fieldOptions() + [
            'server' => $server,
            'action' => $server === null ? route('servers.store') : route('servers.update', $server),
            'method' => $server === null ? 'POST' : 'PUT',
            'submitLabel' => $server === null ? 'Sunucuyu ekle' : 'Değişiklikleri kaydet',
        ];
    }

    /**
     * @param  array<int, ServerOs|ConnectionProtocol|ServerStatus>  $cases
     * @return array<string, string>
     */
    private function labels(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (ServerOs|ConnectionProtocol|ServerStatus $case): array => [
                $case->value => $case->label(),
            ])
            ->all();
    }
}
