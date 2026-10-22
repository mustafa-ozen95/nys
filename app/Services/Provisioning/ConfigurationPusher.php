<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\ConfigurationPush;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Netconf\ConfigurationWriter;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\NetconfTarget;

/**
 * Cihaza tam config atma.
 *
 * İki kaynak vardır ve ikisi de aynı yoldan gider: elle yapıştırılan ham
 * metin, ya da panelin girdilerden ürettiği şablon çıktısı. Hedef envanterde
 * kayıtlı bir switch olabilir; yalnızca yönetim erişim bilgisine sahip
 * olduğumuz dış bir cihaz da olabilir.
 *
 * Güvenlik zinciri değişmez: özel oturum, fark, commit check, geri alınabilir
 * commit ve ayrı oturumda onay. Override'da bu zincir yalnızca bir kolaylık
 * değil, tek kurtarıcıdır — yeni metin yönetim erişimini içermiyorsa cihaz
 * kendini eski hâline döndürür.
 */
class ConfigurationPusher
{
    public function __construct(
        private NetconfClient $client,
        private ConfigurationWriter $writer,
        private DeviceProfiler $profiler,
        private BaseConfigurationTemplate $template,
    ) {}

    /**
     * Metnin biçimini tanır.
     *
     * "set ..." satırları Junos'a farklı bir eylemle yüklenir (action="set");
     * hiyerarşik metin ise merge ya da override ile. Kullanıcının bunu
     * bilmesi gerekmez, metne bakmak yeter.
     */
    public function styleOf(string $configuration): string
    {
        $rows = array_filter(
            array_map(trim(...), explode("\n", $configuration)),
            static fn (string $row): bool => $row !== '' && ! str_starts_with($row, '#'),
        );

        if ($rows === []) {
            return 'merge';
        }

        foreach ($rows as $row) {
            if (preg_match('/^(set|delete|deactivate|activate)\s/', $row) !== 1) {
                return 'merge';
            }
        }

        return 'set';
    }

    /**
     * Uygulanacak eylem: set satırları her zaman "set" ile yüklenir,
     * hiyerarşik metinde kullanıcının seçtiği biçim geçerlidir.
     *
     * @param  'merge'|'override'  $requested
     */
    public function modeFor(string $configuration, string $requested): string
    {
        return $this->styleOf($configuration) === 'set' ? 'set' : $requested;
    }

    /**
     * Cihaza dokunmadan farkı gösterir.
     *
     * @param  'merge'|'override'  $requested
     * @return array{diff: string, warnings: list<string>, mode: string}
     *
     * @throws NetconfException
     */
    public function preview(NetconfTarget $target, string $configuration, string $requested): array
    {
        $mode = $this->modeFor($configuration, $requested);

        return [...$this->writer->previewText($target, $configuration, $mode), 'mode' => $mode];
    }

    /**
     * Config'i uygular ve geçmişe yazar.
     *
     * Kayıt commit atılmasa bile tutulur: "denendi ama fark yoktu" da bir
     * bilgidir ve denetimde sorulan sorunun cevabıdır.
     *
     * @param  'merge'|'override'  $requested
     * @param  'manual'|'template'  $source
     *
     * @throws NetconfException
     */
    public function push(
        NetconfTarget $target,
        string $configuration,
        string $requested,
        string $source,
        ?User $user,
    ): ConfigurationPush {
        $mode = $this->modeFor($configuration, $requested);
        $result = $this->writer->applyText($target, $configuration, $mode, $this->summary($mode, $source));

        return ConfigurationPush::create([
            'user_id' => $user?->id,
            'switch_id' => $target->switchId,
            'target_host' => $target->host,
            'target_label' => $target->label,
            'mode' => $mode,
            'source' => $source,
            'body' => $configuration,
            'diff' => $result['diff'],
            'committed' => $result['committed'],
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * Şablon çıktısını üretir.
     *
     * Cihaz ÖNCE okunur: yönetim arayüzünün adı modele ve kurulum biçimine
     * göre değişir (sanal şaside "vme", tek cihazda "me0") ve bu şablonda
     * varsayılmaz.
     *
     * @param  array<string, mixed>  $input
     * @return array{configuration: string, profile: DeviceProfile}
     *
     * @throws NetconfException
     */
    public function fromTemplate(NetconfTarget $target, array $input): array
    {
        $profile = $this->client->runOn(
            $target,
            fn (NetconfSession $session): DeviceProfile => $this->profiler->profile($session),
        );

        /*
         * Yedek adresi cihaza özgüdür: hedef envanterdeki bir switch ise
         * onun kendi adresi kullanılır, dışarıdan bir cihazsa varsayılan.
         */
        $input['archival_site'] ??= $target->switchId === null
            ? null
            : NetworkSwitch::find($target->switchId)?->archival_target;

        return [
            'configuration' => $this->template->build($input, $profile),
            'profile' => $profile,
        ];
    }

    /**
     * Cihazın commit geçmişine yazılacak özet. Gövde ASLA buraya konmaz:
     * parola içerebilir ve cihazın günlüğüne düşerdi.
     */
    private function summary(string $mode, string $source): string
    {
        $source = $source === 'template' ? 'şablon' : 'elle';

        return match ($mode) {
            'override' => "Config yüklendi ({$source}, tamamen değiştir)",
            'set' => "Config yüklendi ({$source}, set satırları)",
            default => "Config yüklendi ({$source}, ekle)",
        };
    }
}
