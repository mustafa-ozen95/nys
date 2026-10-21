<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * Yönlendirme tablolarını (routing-instances) cihazdan okur.
 *
 * Filtre tabanlı yönlendirmenin (FBF) merkezi burasıdır: firewall
 * terimi "then routing-instance <ad>" ile trafiği bir tabloya atar ve tablo
 * kendi yolunu taşır. Panel tabloyu görmediği sürece kullanıcı olmayan bir ada
 * atıf yapabiliyor ve hatayı ancak cihaz reddedince görüyordu.
 *
 * Tablonun İÇİNDEKİ route'lar burada okunmaz; onlar RouteReader'ın işidir
 * (aynı route modeli, routing_instance sütunu ile). Aynı bilgiyi iki yerde
 * tutmak, ikisinin ayrışması demektir.
 */
class RoutingInstanceReader
{
    /**
     * Panelin tanıdığı çocuklar; gerisi ham hâliyle korunur.
     *
     * "routing-options" bilerek KORUNANLAR arasındadır: tablonun içindeki
     * route'ları RouteReader ayrıca okur, ama yazarken kaybolmamaları için
     * ham hâlleri de saklanır.
     *
     * @var list<string>
     */
    private const KNOWN_CHILDREN = ['name', 'instance-type', 'description'];

    /**
     * @return list<array{name: string, instance_type: ?string, description: ?string, residual_xml: list<string>}>
     *
     * @throws NetconfException
     */
    public function instances(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><routing-instances/></configuration>'
            .'</get-configuration>'
        );

        $instances = [];

        foreach ($reply->xpath('//*[local-name()="routing-instances"]/*[local-name()="instance"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name === null) {
                continue;
            }

            $instances[] = [
                'name' => $name,
                'instance_type' => $this->text($node, 'instance-type'),
                'description' => $this->text($node, 'description'),
                'residual_xml' => ResidualXml::collect($node, self::KNOWN_CHILDREN),
            ];
        }

        return $instances;
    }

    private function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath('./*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
