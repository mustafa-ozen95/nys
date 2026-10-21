<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;

/**
 * Cihazın yapılandırma yedeğini ayarlar.
 *
 * Junos her commit'ten sonra yapılandırmayı bir dosya sunucusuna gönderebilir:
 *
 *   set system archival configuration transfer-on-commit
 *   set system archival configuration archive-sites "ftp://.../"
 *
 * Hedef adres CİHAZ KAYDINDA durur ve KİMLİK İÇERİR; bu yüzden commit
 * açıklamasına konmaz — cihazın kendi günlüğüne parola düşmemeli. Cihaza
 * özel adres girilmemişse .env'deki varsayılan sunucu kullanılır.
 *
 * Yedek kapatıldığında ayar cihazdan SİLİNİR; kapalı bırakmak yerine yarım
 * bir yapılandırma bırakmak, sonradan "neden yedek almıyor" sorusuna cevap
 * bulmayı zorlaştırır.
 */
class ArchivalWriter
{
    public function __construct(private ConfigurationWriter $writer) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(NetworkSwitch $switch): array
    {
        return $this->writer->apply($switch, $this->configuration($switch), 'Yapilandirma yedegi ayarlandi');
    }

    private function configuration(NetworkSwitch $switch): string
    {
        $site = (string) $switch->archival_target;

        if (! $switch->archival_enabled || $site === '') {
            return '<configuration><system><archival delete="delete"/></system></configuration>';
        }

        $escaped = htmlspecialchars($site, ENT_XML1 | ENT_QUOTES);

        /*
         * archive-sites bir LİSTEDİR ve eski adres kalırsa cihaz iki yere
         * birden göndermeye çalışır; eskisine erişemediği için her commit'te
         * hata basar. Bu yüzden düğüm KOMPLE değiştirilir.
         *
         * "<archive-sites delete=\"delete\"/>" ile temizlemek ÇALIŞMIYOR:
         * cihaz (12.3R12.4) adsız silmeye "syntax error" veriyor ve bütün
         * yükleme düşüyor. jn32-test üzerinde commit check ile doğrulandı;
         * replace="replace" geçen tek temiz biçim.
         */
        return '<configuration><system><archival>'
            .'<configuration replace="replace">'
            .'<transfer-on-commit/>'
            ."<archive-sites><name>{$escaped}</name></archive-sites>"
            .'</configuration></archival></system></configuration>';
    }
}
