<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Tests\TestCase;

/**
 * Kuyruğa giren her iş, kuyruğunu AÇIKÇA seçer.
 *
 * Seçmeyen iş "default" kuyruğuna düşer — ve o kuyruğu dinleyen hiçbir işçi
 * yoktur: systemd birimi nms-switch-write'ı, zamanlayıcı nms-switch-sync'i
 * dinliyor. Sonuç sessizdir: ekran "kuyruğa alındı" der, iş sonsuza kadar
 * bekler ve panelde tek bir işaret çıkmaz.
 */
final class KuyrukKapsamiTest extends TestCase
{
    public function test_her_is_kuyrugunu_acikca_secer(): void
    {
        $eksik = [];

        foreach (glob(app_path('Jobs/*.php')) as $dosya) {
            $kod = $this->yorumsuz((string) file_get_contents($dosya));

            // Kuyruğa girmeyen sınıf (varsa) bu kuralın dışındadır.
            if (! str_contains($kod, 'ShouldQueue')) {
                continue;
            }

            if (! str_contains($kod, 'onQueue(') && ! str_contains($kod, '$queue')) {
                $eksik[] = basename($dosya);
            }
        }

        $this->assertSame(
            [],
            $eksik,
            "Bu işler kuyruğunu seçmiyor; \"default\" kuyruğuna düşerler ve onları dinleyen işçi yok:\n  "
            .implode("\n  ", $eksik),
        );
    }

    /**
     * YORUMLAR AYIKLANIR.
     *
     * İlk yazımda kaynak metninde "onQueue(" aranıyordu ve çağrıyı yorum
     * satırına almak testi geçiriyordu — yani kural görünüşte duruyor, gerçekte
     * yok. Metin araması değil KOD araması gerekiyor.
     */
    private function yorumsuz(string $kaynak): string
    {
        $kod = '';

        foreach (token_get_all($kaynak) as $parca) {
            if (is_array($parca) && in_array($parca[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kod .= is_array($parca) ? $parca[1] : $parca;
        }

        return $kod;
    }

    /**
     * Seçilen kuyruğun bir DİNLEYİCİSİ olmalı. Adı doğru yazmak yetmez;
     * kimsenin dinlemediği bir kuyruk, kuyruk değil çöp kutusudur.
     */
    public function test_secilen_kuyruklarin_dinleyicisi_var(): void
    {
        // systemd birimi + zamanlayıcının açtığı işçiler.
        $dinlenen = ['nms-switch-write', config('zabbix.queue')];

        $secilen = [
            'announcements.queue' => config('announcements.queue'),
            'netconf.write_queue' => config('netconf.write_queue'),
            'netconf.queue' => config('netconf.queue'),
            'snmp.queue' => config('snmp.queue'),
            'credentials.rotation.queue' => config('credentials.rotation.queue'),
        ];

        foreach ($secilen as $anahtar => $kuyruk) {
            $this->assertContains(
                $kuyruk,
                $dinlenen,
                "{$anahtar} = \"{$kuyruk}\" kuyruğunu dinleyen bir işçi yok.",
            );
        }
    }
}
