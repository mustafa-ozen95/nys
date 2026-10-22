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
final class QueueScopeTest extends TestCase
{
    public function test_her_is_kuyrugunu_acikca_secer(): void
    {
        $missing = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $code = $this->uncommented((string) file_get_contents($file));

            // Kuyruğa girmeyen sınıf (varsa) bu kuralın dışındadır.
            if (! str_contains($code, 'ShouldQueue')) {
                continue;
            }

            if (! str_contains($code, 'onQueue(') && ! str_contains($code, '$queue')) {
                $missing[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Bu işler kuyruğunu seçmiyor; \"default\" kuyruğuna düşerler ve onları dinleyen işçi yok:\n  "
            .implode("\n  ", $missing),
        );
    }

    /**
     * YORUMLAR AYIKLANIR.
     *
     * İlk yazımda kaynak metninde "onQueue(" aranıyordu ve çağrıyı yorum
     * satırına almak testi geçiriyordu — yani kural görünüşte duruyor, gerçekte
     * yok. Metin araması değil KOD araması gerekiyor.
     */
    private function uncommented(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $fragment) {
            if (is_array($fragment) && in_array($fragment[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($fragment) ? $fragment[1] : $fragment;
        }

        return $code;
    }

    /**
     * Seçilen kuyruğun bir DİNLEYİCİSİ olmalı. Adı doğru yazmak yetmez;
     * kimsenin dinlemediği bir kuyruk, kuyruk değil çöp kutusudur.
     */
    public function test_secilen_kuyruklarin_dinleyicisi_var(): void
    {
        // systemd birimi + zamanlayıcının açtığı işçiler.
        $listened = ['nms-switch-write', config('zabbix.queue')];

        $selected = [
            'announcements.queue' => config('announcements.queue'),
            'netconf.write_queue' => config('netconf.write_queue'),
            'netconf.queue' => config('netconf.queue'),
            'snmp.queue' => config('snmp.queue'),
            'credentials.rotation.queue' => config('credentials.rotation.queue'),
        ];

        foreach ($selected as $key => $queue) {
            $this->assertContains(
                $queue,
                $listened,
                "{$key} = \"{$queue}\" kuyruğunu dinleyen bir işçi yok.",
            );
        }
    }
}
