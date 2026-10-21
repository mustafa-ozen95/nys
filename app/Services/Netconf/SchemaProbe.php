<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use Throwable;

/**
 * Cihazın yapılandırma şemasının hangi aileden olduğunu CİHAZA SORAR.
 *
 * Sürüm tablosuyla tahmin edilmez: sürüm dizesi aynı olan cihazlarda bile
 * platforma göre şema değişebiliyor ve tablo bakım isteyen bir yalan
 * hâline gelir. Cevabı cihazın kendisi verir.
 *
 * YÖNTEM: özel bir aday yapılandırmaya "port-mode" yüklenmeye çalışılır ve
 * aday atılır. Commit YOK, commit check bile YOK — cihazda hiçbir iz kalmaz.
 *
 * Ayırt edici LOAD adımıdır, check değil (jn32-test üzerinde ölçüldü):
 *
 *   bilinmeyen etiket        -> LOAD "syntax error" ile düşer
 *   bilinen ama anlamsız ayar -> LOAD geçer, ancak commit check'te düşer
 *
 * Yani "port-mode yüklenebiliyor mu" sorusu yalnızca ŞEMAYI ölçer, o portun
 * yapılandırmasını değil.
 *
 * TERSİ AYIRT EDİCİ DEĞİLDİR: 12.3R12.4 "interface-mode"u da kabul ediyor
 * (ölçüldü). Bu yüzden sonda pre-ELS adını dener, ELS adını değil.
 */
class SchemaProbe
{
    /**
     * Sondanın kullandığı arayüz adı.
     *
     * Var olmayan bir arayüz seçilir: şema sorusu gerçek bir portun
     * yapılandırmasına dokunmasın. Junos yapılandırmada olmayan arayüzü
     * kabul eder (uplink modülü takılı değilken de yapılandırma yazılabilir).
     */
    private const INTERFACE = 'ge-0/0/0';

    public function __construct(private DeviceOperationRecorder $operations) {}

    public function detect(NetconfSession $session, ?NetworkSwitch $switch = null): ?JunosSchemaProfile
    {
        try {
            $session->rpc('<open-configuration><private/></open-configuration>');
        } catch (Throwable $exception) {
            /*
             * Aday açılamıyorsa soru sorulamaz. Bu sessiz geçmez: yoksa
             * cihazın şeması bilinmiyor kalır, her turda yeniden denenir ve
             * kimse görmezdi.
             */
            $this->operations->failure($exception, 'probe', (string) ($switch?->hostname ?? 'cihaz'), $switch, [
                'summary' => 'Şema sondası: aday yapılandırma açılamadı',
            ]);

            return null;
        }

        try {
            $session->rpc(
                '<load-configuration action="merge" format="xml"><configuration><interfaces>'
                .'<interface><name>'.self::INTERFACE.'</name><unit><name>0</name>'
                .'<family><ethernet-switching><port-mode>access</port-mode></ethernet-switching></family>'
                .'</unit></interface></interfaces></configuration></load-configuration>'
            );

            return JunosSchemaProfile::PreEls;
        } catch (Throwable $exception) {
            /*
             * Yalnızca SÖZDİZİMİ hatası "bu etiket burada yok" demektir.
             * Başka bir hata (erişim, kilit, geçici) şema hakkında bir şey
             * söylemez; bilinmiyor olarak bırakılır ve sonra yeniden sorulur.
             *
             * Karar önce cihazın YAPISAL cevabından okunur (her rpc-error'un
             * kendi mesajı), serbest metne düşmek yalnızca yedektir.
             */
            if ($this->isSyntaxError($exception)) {
                return JunosSchemaProfile::Els;
            }

            $this->operations->failure($exception, 'probe', (string) ($switch?->hostname ?? 'cihaz'), $switch, [
                'summary' => 'Şema sondası: cihazın ailesi belirlenemedi',
            ]);

            return null;
        } finally {
            try {
                $session->rpc('<close-configuration/>');
            } catch (Throwable) {
                // Aday zaten kapanmış olabilir; sorun değil.
            }
        }
    }

    /**
     * "Bu etiket burada yok" cevabı mı?
     */
    private function isSyntaxError(Throwable $exception): bool
    {
        if ($exception instanceof NetconfException) {
            foreach ($exception->errors() as $error) {
                if (str_contains(mb_strtolower($error->message), 'syntax error')) {
                    return true;
                }
            }
        }

        return str_contains(mb_strtolower($exception->getMessage()), 'syntax error');
    }
}
