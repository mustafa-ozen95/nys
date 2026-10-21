<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use Illuminate\Http\Request;

/**
 * Cihaza atılan commit'e iliştirilen açıklama.
 *
 * Cihaz yalnızca paylaşılan NETCONF hesabını (admin) görür; panelde işlemi
 * kimin yaptığı orada kaybolurdu. Junos commit'e bir yorum iliştirmeye izin
 * verir ve bu yorum hem cihazın kendi commit geçmişinde hem de panelde
 * görünür. Böylece "admin yaptı" değil, "hangi panel kullanıcısı yaptı"
 * bilgisi cihazın üstünde kalır.
 *
 * Metin ASCII'ye indirgenir. JunOS 12.3 commit yorumunda UTF-8'i güvenilir
 * taşımaz; "Ayşe Yılmaz" ya bozularak yazılır ya da commit reddedilir.
 * Türkçe harfler karşılıklarına çevrilir, kalan ASCII dışı her karakter
 * atılır.
 */
class CommitLog
{
    private const PREFIX = 'NMS';

    /** JunOS yorum alanı için üst sınır; fazlası kırpılır. */
    private const MAX_LENGTH = 250;

    /** @var array<string, string> */
    private const TRANSLITERATION = [
        'ç' => 'c', 'Ç' => 'C',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'İ' => 'I',
        'ö' => 'o', 'Ö' => 'O',
        'ş' => 's', 'Ş' => 'S',
        'ü' => 'u', 'Ü' => 'U',
        'â' => 'a', 'Â' => 'A',
        'î' => 'i', 'Î' => 'I',
        'û' => 'u', 'Û' => 'U',
    ];

    public function __construct(private Request $request) {}

    public function message(string $summary): string
    {
        $user = $this->request->user();

        // Kuyruktan ya da konsoldan gelen işlemlerde oturum yoktur.
        $who = $user === null ? 'otomatik' : "{$user->name} <{$user->email}>";

        return mb_substr($this->ascii(self::PREFIX." · {$who} · {$summary}"), 0, self::MAX_LENGTH);
    }

    /**
     * Türkçe harfleri çevirir, kalan ASCII dışı karakterleri atar.
     */
    private function ascii(string $text): string
    {
        $converted = strtr($text, self::TRANSLITERATION);

        // Orta nokta gibi ASCII dışı ayraçlar da düşer; yerine tire konur.
        $converted = str_replace('·', '-', $converted);

        return trim((string) preg_replace('/[^\x20-\x7E]/', '', $converted));
    }
}
