<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NetworkSwitch;
use App\Services\Netconf\DeviceOperationRecorder;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\SwitchSyncPipeline;
use App\Services\Netconf\SwitchWriteLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Cihazın yapılandırmasını NETCONF ile okur: donanım bilgisi, portlar,
 * VLAN'lar, IP adresleri, routing tablosu, prefix list'ler, firewall
 * filtreleri, politikalar,
 * BGP grupları ile komşuları ve commit geçmişi.
 *
 * YALNIZCA OKUMA yapılır; cihaza hiçbir şey yazılmaz.
 *
 * Bütün RPC'ler TEK oturumda çalışır: oturum kurmak cihazda mgd fork'lar ve
 * ~2 saniye sürer, oturum açıkken her ek RPC neredeyse bedavadır
 * (bkz. .ai/rules/netconf.md).
 *
 * Cihaz yanıt vermezse mevcut kayıtlar silinmez, iş sessizce biter: geçici bir
 * erişim sorunu yüzünden envanter boşalmamalı.
 */
class SyncSwitchNetconfJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Kilidin en fazla ne kadar tutulacağı. Bu OLMADAN iş bir şekilde
     * kaybolursa kilit sonsuza kadar kalır ve sonraki her dispatch sessizce
     * atılır.
     */
    public int $uniqueFor = 1500;

    /**
     * @param  bool  $force  Değişim algısını atlar ve her şeyi yeniden okur.
     *                       Panele yeni bir okuyucu eklendiğinde gerekir:
     *                       cihazda yeni commit olmadığı için normal tur
     *                       erken çıkar ve yeni alanlar boş kalırdı.
     */
    public function __construct(public int $switchId, public bool $force = false)
    {
        $this->onQueue(config('netconf.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->switchId;
    }

    public function handle(NetconfClient $client, SwitchSyncPipeline $sira, SwitchWriteLock $lock): void
    {
        $switch = NetworkSwitch::query()->find($this->switchId);

        if ($switch === null) {
            return;
        }

        if ($switch->netconfCredential === null && $switch->sshCredential === null) {
            return;
        }

        /*
         * Panelden bir yazma sürüyorsa bu tur atlanır.
         *
         * Yazma yükleme, commit ve onay olarak sürer; ortasında okunan
         * yapılandırma yarım bir durumu gösterir ve envantere o yazılırdı.
         * Beklemek yerine atlamak doğrudur: bir sonraki tur zaten gelecek ve
         * yazma yolu commit sonrası kendi geri okumasını yapıyor.
         */
        $okundu = $lock->attempt($switch, fn (): bool => $this->oku($client, $sira, $switch));

        if ($okundu === true) {
            // Gözlem: sürüm damgasına dokunmaz (bkz. RecordsObservations).
            $switch->saveObservation(['last_sync_at' => now()]);
        }
    }

    /**
     * Tek oturumda okur; cihaz yanıt vermezse mevcut kayıtlara dokunmaz.
     *
     * Okuma hatası yalnızca dosya günlüğüne düşseydi panelde hiçbir iz
     * olmaz, yalnızca last_sync_at eskir ve "neden" yazılı olmazdı. Bu
     * yüzden cihaz işlem kaydına da geçer.
     */
    private function oku(NetconfClient $client, SwitchSyncPipeline $sira, NetworkSwitch $switch): bool
    {
        try {
            $client->run(
                $switch,
                fn (NetconfSession $session) => $sira->run($switch, $session, $this->force),
            );
        } catch (NetconfException $exception) {
            Log::warning("NETCONF okunamadı: {$switch->hostname}", ['hata' => $exception->getMessage()]);

            app(DeviceOperationRecorder::class)->failure(
                $exception,
                'sync',
                (string) $switch->hostname,
                $switch,
                ['summary' => 'NETCONF eşitlemesi'],
            );

            return false;
        }

        return true;
    }
}
