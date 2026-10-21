<?php

declare(strict_types=1);

namespace App\Services\Netconf\Framing;

use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;

/**
 * NETCONF mesaj çerçeveleme sürümü.
 *
 * RFC 6242 iki biçim tanımlar: base:1.0 mesajları "]]>]]>" ile ayırır,
 * base:1.1 uzunluk önekli parçalara böler. Hangisinin konuşulacağı oturum
 * açılırken hello yeteneklerinden belirlenir; bu arayüz sayesinde oturumun
 * geri kalanı hangi sürümde olduğunu bilmek zorunda kalmaz.
 *
 * MESAJI OKUMAK da çerçevelemenin işidir. Önceden oturum "şu diziye kadar oku"
 * diyordu ve 1.1 için verilen dize "\n##\n" idi — oysa 1.1'de sınırı ayırıcı
 * değil UZUNLUK belirler. Parça verisinin içinde "\n##\n" geçtiğinde (Junos
 * yapılandırma metni "##" ile başlayan yorum satırları taşır) mesaj tam
 * ortasından kesiliyordu. Okumayı çerçevelemeye vermek bu ayrımı mümkün kılar.
 *
 * Yeni bir sürüm çıkarsa bu arayüzü uygulayan bir sınıf yazılır ve
 * FramingNegotiator'a tanıtılır; başka hiçbir yer değişmez.
 */
interface MessageFraming
{
    /**
     * Bu çerçevelemenin karşılık geldiği yetenek kimliği.
     */
    public function capability(): string;

    /**
     * Gönderilecek XML'i çerçeveler.
     */
    public function encode(string $xml): string;

    /**
     * Taşımadan TAM BİR mesaj okur ve çerçevesini soyar.
     *
     * @throws NetconfException Çerçeve bozuksa ya da bağlantı koparsa
     */
    public function read(NetconfTransport $transport): string;

    /**
     * Çerçeveyi soyar, saf XML döndürür.
     *
     * @throws NetconfException Çerçeve bozuksa
     */
    public function decode(string $raw): string;
}
