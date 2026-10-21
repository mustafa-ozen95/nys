<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Carbon\CarbonImmutable;

/**
 * Cihazın saatini NTP sunucusundan HEMEN düzeltir.
 *
 * "ntp server" tek başına yetmez: ntpd, fark 1000 saniyeyi aşınca saati
 * adımlamayı REDDEDER (panic eşiği). Saati yıllarca geride kalmış bir cihaz
 * (ör. pil bitmiş, uzun süre NTP'siz çalışmış) sunucu tanımlı olsa bile
 * kendiliğinden toparlamaz.
 *
 * "set date ntp" ntpdate'i tek seferlik çalıştırır ve saati bir hamlede
 * yerine oturtur; "ntp boot-server" aynısını her açılışta yapar. Panel
 * ikisini de sunar.
 *
 * BU BİR YAPILANDIRMA DEĞİŞİKLİĞİ DEĞİLDİR: commit yoktur, geri alınacak bir
 * şey de. O yüzden ConfigurationWriter'dan geçmez — ondan geçseydi boş bir
 * fark üretip "cihaz zaten bu durumda" derdi.
 */
class DeviceClockSynchronizer
{
    public function __construct(
        private NetconfClient $client,
        private DeviceOperationRecorder $operations,
    ) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function synchronize(NetworkSwitch $switch): array
    {
        $sunucu = $switch->ntp_boot_server ?? ($switch->ntp_servers[0] ?? null);

        if ($sunucu === null) {
            throw NetconfException::transport(
                'Cihazda NTP sunucusu tanımlı değil; saat neyle eşitlenecek belli değil.',
                'missing-ntp-server',
            );
        }

        $cikti = '';

        $this->client->run($switch, function (NetconfSession $session) use ($sunucu, &$cikti): void {
            $yanit = $session->rpc(
                '<command format="text">set date ntp '.$this->escape($sunucu).'</command>'
            );

            $cikti = trim((string) ($yanit->xpath('//*[local-name()="output"]')[0] ?? ''));
        });

        /*
         * ntpdate hatayı ÇIKTIDA bildirir, RPC hatası olarak değil:
         * "No route to host" gibi satırlar başarı sanılırdı.
         */
        if ($this->failed($cikti)) {
            throw $this->operations->failure(
                NetconfException::transport($this->message($cikti), 'ntpdate'),
                'clock',
                $switch->hostname,
                $switch,
                ['summary' => 'saat eşitlenemedi'],
            );
        }

        $this->storeOffset($switch);

        return ['diff' => $cikti, 'committed' => true, 'warnings' => []];
    }

    /**
     * Eşitlemeden sonra sapma YENİDEN ölçülür: ekran eski değeri
     * göstermesin.
     */
    private function storeOffset(NetworkSwitch $switch): void
    {
        $this->client->run($switch, function (NetconfSession $session) use ($switch): void {
            $yanit = $session->rpc('<get-system-uptime-information/>');
            $metin = trim((string) (
                $yanit->xpath('//*[local-name()="current-time"]/*[local-name()="date-time"]')[0] ?? ''
            ));

            if ($metin === '') {
                return;
            }

            $switch->forceFill([
                'clock_offset_seconds' => (int) round(
                    CarbonImmutable::parse($metin)->diffInSeconds(CarbonImmutable::now())
                ),
                'clock_checked_at' => now(),
            ])->save();
        });
    }

    private function failed(string $output): bool
    {
        foreach (['no route to host', 'no server suitable', 'timed out', 'name resolution'] as $isaret) {
            if (str_contains(mb_strtolower($output), $isaret)) {
                return true;
            }
        }

        return false;
    }

    private function message(string $output): string
    {
        $satirlar = array_values(array_filter(array_map(trim(...), explode("\n", $output))));

        return 'Cihaz saati eşitleyemedi: '.($satirlar[0] ?? 'NTP sunucusuna ulaşılamadı.');
    }

    /**
     * Adres doğrulamadan geçiyor ama komut satırına giriyor; yine de
     * yalnızca IP karakterlerine izin verilir.
     */
    private function escape(string $server): string
    {
        return (string) preg_replace('/[^0-9a-fA-F.:]/', '', $server);
    }
}
