<?php

declare(strict_types=1);

namespace App\Services\Capabilities;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Services\Netconf\DeviceOperationRecorder;
use App\Services\Netconf\NetconfSession;
use Throwable;

/**
 * Cihaza "bu değeri kabul eder misin" diye SORAR.
 *
 * YÖNTEM: özel bir aday yapılandırmaya değer yüklenmeye çalışılır ve aday
 * atılır. Commit YOK, commit check bile YOK — cihazda hiçbir iz kalmaz.
 *
 * Ayırt edici LOAD adımıdır (test4'te ölçüldü): hem bilinmeyen etiket
 * ("syntax error") hem de geçersiz değer ("invalid value") yükleme sırasında
 * düşüyor. Commit check'e girmeye gerek yok — ELS'te check üç-altı kat yavaş
 * ve sonda o yükü taşımaz.
 *
 * SchemaProbe aileyi sorar, bu sınıf DEĞERLERİ. İkisi aynı oturumda, aynı
 * ucuzlukta çalışır.
 */
class CapabilityProbe
{
    /**
     * Ölçümün hangi Junos sürümünde yapıldığı, kaydın içinde saklanır.
     *
     * Sürüm yükseltmesi değer kümesini değiştirebilir; ölçüm eskidiğinde
     * kendiliğinden yenilenmezse panel, cihazda artık geçerli olan bir
     * seçeneği sonsuza kadar gizlerdi. Anahtar bir DİZİ tutmaz, bu yüzden
     * DeviceCapabilities onu yetenek olarak okumaz.
     */
    public const VERSION_KEY = '_junos_version';

    public function __construct(private DeviceOperationRecorder $operations) {}

    /**
     * Sonda çalışmalı mı?
     *
     * Hiç ölçülmemişse ya da ölçüm başka bir Junos sürümünde yapılmışsa evet.
     * Her turda yeniden ölçmek gereksiz: değer kümesi sürüm sabitken sabit.
     */
    public function shouldProbe(NetworkSwitch $switch): bool
    {
        $kayit = $switch->device_capabilities;

        if (! is_array($kayit) || $kayit === []) {
            return true;
        }

        return ($kayit[self::VERSION_KEY] ?? null) !== $switch->junos_version;
    }

    /**
     * Bildirilen bütün yetenekleri ölçer.
     *
     * @return array<string, list<string>>|null yetenek => kabul edilen değerler
     */
    public function detect(NetconfSession $session, NetworkSwitch $switch): ?array
    {
        $yetenekler = $this->capabilities();

        if ($yetenekler === []) {
            return null;
        }

        try {
            $session->rpc('<open-configuration><private/></open-configuration>');
        } catch (Throwable $exception) {
            /*
             * Aday açılamıyorsa soru sorulamaz. Sessiz kalmak, cihazın
             * yetenekleri hiç öğrenilmeden her turda yeniden denenmesi
             * demekti (bkz. SchemaProbe'daki aynı gerekçe).
             */
            $this->operations->failure($exception, 'probe', $switch->hostname, $switch, [
                'summary' => 'Yetenek sondası: aday yapılandırma açılamadı',
            ]);

            return null;
        }

        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();
        $sonuc = [self::VERSION_KEY => $switch->junos_version];

        try {
            foreach ($yetenekler as $yetenek) {
                $sonuc[$yetenek->key()] = $this->measure($session, $yetenek->candidates($lehce, $switch));
            }
        } finally {
            /*
             * Aday HER durumda atılır. Kapatılmazsa cihazda açık bir özel
             * yapılandırma oturumu kalır ve sonraki yazma "configuration
             * database is locked" ile düşerdi.
             */
            $this->discard($session);
        }

        return $sonuc;
    }

    /**
     * @param  array<string, string>  $candidates
     * @return list<string>
     */
    private function measure(NetconfSession $session, array $candidates): array
    {
        $kabul = [];

        foreach ($candidates as $deger => $belge) {
            try {
                $session->rpc(
                    '<load-configuration action="merge" format="xml"><configuration>'
                    .$belge
                    .'</configuration></load-configuration>'
                );

                $kabul[] = (string) $deger;
            } catch (Throwable) {
                /*
                 * Reddedilen değer sonucun kendisidir, hata değil. Kayda
                 * geçirilmez: her turda onlarca satır gürültü olurdu.
                 */
            }
        }

        return $kabul;
    }

    private function discard(NetconfSession $session): void
    {
        try {
            $session->rpc('<close-configuration/>');
        } catch (Throwable) {
            // Oturum zaten düştüyse kapatacak bir şey yok.
        }
    }

    /**
     * Bildirilen yetenekler. Yeni bir yetenek eklemek config'e BİR satırdır.
     *
     * @return list<DeviceCapability>
     */
    private function capabilities(): array
    {
        return array_values(array_map(
            static fn (string $sinif): DeviceCapability => app($sinif),
            (array) config('capabilities.probes', []),
        ));
    }
}
