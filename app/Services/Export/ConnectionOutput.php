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
    public function uret(
        Collection $sunucular,
        Collection $switchler,
        User $kullanici,
        string $gerekce,
    ): array {
        $satirlar = [];
        $yetkisiz = 0;
        $parolasiz = 0;

        foreach ($sunucular as $sunucu) {
            if (! $kullanici->can('export', $sunucu)) {
                $yetkisiz++;

                continue;
            }

            if (blank($sunucu->connection_password)) {
                $parolasiz++;

                continue;
            }

            $satirlar[] = [
                'Sunucu',
                (string) $sunucu->short_name,
                (string) $sunucu->long_name,
                $sunucu->os_type?->label() ?? '',
                $sunucu->connection_protocol?->label() ?? '',
                (string) $sunucu->ip_address,
                (string) ($sunucu->connection_port ?? $this->varsayilanPort($sunucu)),
                (string) $sunucu->connection_username,
                (string) $sunucu->connection_password,
                (string) $sunucu->location,
                $sunucu->updated_at?->format('d.m.Y H:i') ?? '',
            ];

            $this->yaz($sunucu, "{$sunucu->short_name} bağlantı bilgileri dosyaya çıkarıldı", $gerekce, $kullanici);
        }

        foreach ($switchler as $switch) {
            if (! $kullanici->can('export', $switch)) {
                $yetkisiz++;

                continue;
            }

            /*
             * Bir switchin BİRDEN ÇOK kimliği olabiliyor (NETCONF ayrı, SNMP
             * ayrı) ve her biri ayrı bir bağlantı yoludur. Satır başına bir
             * yol yazılır; birini seçip ötekini atmak, çıktıyı eline alan
             * kişinin kurabileceği bağlantıyı eksiltmek olurdu.
             */
            $yazilan = 0;

            foreach ($switch->credentials as $kimlik) {
                $sir = (string) ($kimlik->password ?? $kimlik->community ?? '');

                if (blank($sir)) {
                    continue;
                }

                $satirlar[] = [
                    'Switch',
                    (string) $switch->hostname,
                    (string) $switch->model,
                    'Junos',
                    $kimlik->protocol?->label() ?? '',
                    (string) $switch->management_ip,
                    (string) ($kimlik->port ?? ''),
                    (string) $kimlik->username,
                    $sir,
                    /* Konum switchte bir SEÇENEK (enum), sunucuda düz metin. */
                    $switch->location?->label() ?? '',
                    $switch->updated_at?->format('d.m.Y H:i') ?? '',
                ];

                $yazilan++;
            }

            if ($yazilan === 0) {
                $parolasiz++;

                continue;
            }

            $this->yaz($switch, "{$switch->hostname} bağlantı bilgileri dosyaya çıkarıldı", $gerekce, $kullanici);
        }

        return [
            'icerik' => Workbook::uret('Baglanti bilgileri', [
                'Tür', 'Ad', 'Açıklama', 'Platform', 'Protokol',
                'Adres', 'Port', 'Kullanıcı', 'Parola', 'Konum', 'Son güncelleme',
            ], $satirlar),
            'ad' => 'nys-baglanti-'.now()->format('Y-m-d-Hi').'.xlsx',
            'yazilan' => count($satirlar),
            'yetkisiz' => $yetkisiz,
            'parolasiz' => $parolasiz,
        ];
    }

    /**
     * Portu yazılmamış sunucunun protokolden gelen varsayılanı.
     *
     * Boş bırakmak, çıktıyı eline alan kişiye eksik bilgi vermek olurdu:
     * panel de bağlanırken aynı varsayılanı kullanıyor.
     */
    private function varsayilanPort(Server $sunucu): int
    {
        return $sunucu->connection_protocol?->value === 'rdp' ? 3389 : 22;
    }

    private function yaz(object $konu, string $metin, string $gerekce, User $kullanici): void
    {
        $this->activity->recordAs(
            (int) $kullanici->id,
            $gerekce,
            request()->ip(),
            $konu,
            'exported',
            $metin,
            ActivityChannel::Crud,
        );
    }
}
