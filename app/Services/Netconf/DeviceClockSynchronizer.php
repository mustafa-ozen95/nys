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
        $server = $switch->ntp_boot_server ?? ($switch->ntp_servers[0] ?? null);

        if ($server === null) {
            throw NetconfException::transport(
                'Cihazda NTP sunucusu tanımlı değil; saat neyle eşitlenecek belli değil.',
                'missing-ntp-server',
            );
        }

        $output = '';

        $this->client->run($switch, function (NetconfSession $session) use ($server, &$output): void {
            $response = $session->rpc(
                '<command format="text">set date ntp '.$this->escape($server).'</command>'
            );

            $output = trim((string) ($response->xpath('//*[local-name()="output"]')[0] ?? ''));
        });

        /*
         * ntpdate hatayı ÇIKTIDA bildirir, RPC hatası olarak değil:
         * "No route to host" gibi satırlar başarı sanılırdı.
         */
        if ($this->failed($output)) {
            throw $this->operations->failure(
                NetconfException::transport($this->message($output), 'ntpdate'),
                'clock',
                $switch->hostname,
                $switch,
                ['summary' => 'saat eşitlenemedi'],
            );
        }

        $this->storeOffset($switch);

        return ['diff' => $output, 'committed' => true, 'warnings' => []];
    }

    /**
     * Eşitlemeden sonra sapma YENİDEN ölçülür: ekran eski değeri
     * göstermesin.
     */
    private function storeOffset(NetworkSwitch $switch): void
    {
        $this->client->run($switch, function (NetconfSession $session) use ($switch): void {
            $response = $session->rpc('<get-system-uptime-information/>');
            $text = trim((string) (
                $response->xpath('//*[local-name()="current-time"]/*[local-name()="date-time"]')[0] ?? ''
            ));

            if ($text === '') {
                return;
            }

            $switch->forceFill([
                'clock_offset_seconds' => (int) round(
                    CarbonImmutable::parse($text)->diffInSeconds(CarbonImmutable::now())
                ),
                'clock_checked_at' => now(),
            ])->save();
        });
    }

    private function failed(string $output): bool
    {
        foreach (['no route to host', 'no server suitable', 'timed out', 'name resolution'] as $mark) {
            if (str_contains(mb_strtolower($output), $mark)) {
                return true;
            }
        }

        return false;
    }

    private function message(string $output): string
    {
        $rows = array_values(array_filter(array_map(trim(...), explode("\n", $output))));

        return 'Cihaz saati eşitleyemedi: '.($rows[0] ?? 'NTP sunucusuna ulaşılamadı.');
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
