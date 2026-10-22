<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\ActivityChannel;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Collection;

/**
 * Sunucu ve switchlerin BAĞLANTI BİLGİLERİNİ Excel dosyası olarak verir.
 *
 * NE YAZILIR: panelin o cihaza ya da yazılıma nasıl bağlandığı — protokol,
 * adres, port, kullanıcı ve parola. Yani dosyayı eline alan biri aynı
 * bağlantıyı kurabilir. Eksik bir sütun, çıktının işe yaramaması demek.
 *
 * BU BİR İSTİSNADIR ve bedeli ödenir. Panelin kuralı parolayı ekranda
 * basmamaktır; toplu çıktı o kuralı deler, o yüzden:
 *
 *  - Kapı ayrı bir yetkidir ('export'); "görüntüle" onu kapsamaz.
 *  - Her satır için yetki AYRI sorulur: göremediği kayıt dosyaya girmez ve
 *    kaç tanesinin elendiği kullanıcıya söylenir.
 *  - Gerekçe zorunludur (RequireActionReason) ve kayda yazılır.
 *  - HER KAYIT için ayrı bir denetim satırı yazılır. Özet satır okunaklı
 *    olurdu ama "hangi parola dışarı çıktı" sorusunu cevaplamazdı; denetimin
 *    sorduğu soru tam olarak budur.
 *
 * İKİ SAYFA DEĞİL, İKİ DOSYA DA DEĞİL: sunucular ve switchler aynı sayfada,
 * "Tür" sütunuyla ayrılıyor. İkisinin bağlantı bilgisi aynı beş sorudan
 * ibaret ve ayrı tablolar, aynı soruyu iki yerde aramak olurdu.
 */
final class ConnectionOutput
{
    public function __construct(private ActivityRecorder $activity) {}

    /**
     * @param  Collection<int, Server>  $sunucular
     * @param  Collection<int, NetworkSwitch>  $switchler
     * @return array{icerik: string, ad: string, yazilan: int, yetkisiz: int, parolasiz: int}
     */
    public function generate(
        Collection $servers,
        Collection $switches,
        User $user,
        string $reason,
    ): array {
        $rows = [];
        $unauthorized = 0;
        $passwordless = 0;

        foreach ($servers as $server) {
            if (! $user->can('export', $server)) {
                $unauthorized++;

                continue;
            }

            if (blank($server->connection_password)) {
                $passwordless++;

                continue;
            }

            $rows[] = [
                'Sunucu',
                (string) $server->short_name,
                (string) $server->long_name,
                $server->os_type?->label() ?? '',
                $server->connection_protocol?->label() ?? '',
                (string) $server->ip_address,
                (string) ($server->connection_port ?? $this->defaultPort($server)),
                (string) $server->connection_username,
                (string) $server->connection_password,
                (string) $server->location,
                $server->updated_at?->format('d.m.Y H:i') ?? '',
            ];

            $this->write($server, "{$server->short_name} bağlantı bilgileri dosyaya çıkarıldı", $reason, $user);
        }

        foreach ($switches as $switch) {
            if (! $user->can('export', $switch)) {
                $unauthorized++;

                continue;
            }

            /*
             * Bir switchin BİRDEN ÇOK kimliği olabiliyor (NETCONF ayrı, SNMP
             * ayrı) ve her biri ayrı bir bağlantı yoludur. Satır başına bir
             * yol yazılır; birini seçip ötekini atmak, çıktıyı eline alan
             * kişinin kurabileceği bağlantıyı eksiltmek olurdu.
             */
            $written = 0;

            foreach ($switch->credentials as $identity) {
                $secret = (string) ($identity->password ?? $identity->community ?? '');

                if (blank($secret)) {
                    continue;
                }

                $rows[] = [
                    'Switch',
                    (string) $switch->hostname,
                    (string) $switch->model,
                    'Junos',
                    $identity->protocol?->label() ?? '',
                    (string) $switch->management_ip,
                    (string) ($identity->port ?? ''),
                    (string) $identity->username,
                    $secret,
                    /* Konum switchte bir SEÇENEK (enum), sunucuda düz metin. */
                    $switch->location?->label() ?? '',
                    $switch->updated_at?->format('d.m.Y H:i') ?? '',
                ];

                $written++;
            }

            if ($written === 0) {
                $passwordless++;

                continue;
            }

            $this->write($switch, "{$switch->hostname} bağlantı bilgileri dosyaya çıkarıldı", $reason, $user);
        }

        return [
            'icerik' => Workbook::generate('Baglanti bilgileri', [
                'Tür', 'Ad', 'Açıklama', 'Platform', 'Protokol',
                'Adres', 'Port', 'Kullanıcı', 'Parola', 'Konum', 'Son güncelleme',
            ], $rows),
            'ad' => 'nys-baglanti-'.now()->format('Y-m-d-Hi').'.xlsx',
            'yazilan' => count($rows),
            'yetkisiz' => $unauthorized,
            'parolasiz' => $passwordless,
        ];
    }

    /**
     * Portu yazılmamış sunucunun protokolden gelen varsayılanı.
     *
     * Boş bırakmak, çıktıyı eline alan kişiye eksik bilgi vermek olurdu:
     * panel de bağlanırken aynı varsayılanı kullanıyor.
     */
    private function defaultPort(Server $server): int
    {
        return $server->connection_protocol?->value === 'rdp' ? 3389 : 22;
    }

    private function write(object $subject, string $text, string $reason, User $user): void
    {
        $this->activity->recordAs(
            (int) $user->id,
            $reason,
            request()->ip(),
            $subject,
            'exported',
            $text,
            ActivityChannel::Crud,
        );
    }
}
