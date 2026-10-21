<?php

declare(strict_types=1);

namespace App\Services\Bgp;

use App\Enums\AddressPurpose;
use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Enums\BgpNlri;
use App\Enums\IpFamily;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Services\Addressing\AddressCandidateService;
use Illuminate\Support\Collection;

/**
 * BGP ekranının hazır verisi.
 *
 * Blade yalnızca basar: seçenek listeleri, sayımlar ve "bu alan açılabilir mi"
 * kararları burada hesaplanır.
 */
class BgpViewService
{
    public function __construct(
        private PeerConfigurationBuilder $peerConfigs,
        private AddressCandidateService $candidates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function screen(NetworkSwitch $switch): array
    {
        $groups = $switch->bgpGroups()
            ->with(['neighbors' => fn ($query) => $query->orderBy('address'), 'families'])
            ->orderBy('name')
            ->get();

        $policies = $switch->policyStatements()->orderBy('name')->pluck('name', 'name')->all();

        return [
            'groups' => $groups,
            'summary' => $this->summary($groups),

            /*
             * Karşı tarafın kendi cihazına yapıştıracağı komutlar. Her komşu
             * için ayrı üretilir; ekranda kopyalanabilir bir kutuda durur.
             */
            'peerConfigurations' => $groups
                ->flatMap
                ->neighbors
                ->mapWithKeys(fn (BgpNeighbor $neighbor): array => [
                    $neighbor->uuid => $this->peerConfigs->forNeighbor($neighbor),
                ])
                ->all(),

            /*
             * Aynı oturumun BİZDEKİ hâli. Yan yana durur ki iki taraf
             * karşılaştırılabilsin; cihaza yazmak için değil, gözden
             * geçirmek ve gerekirse başka cihaza taşımak için.
             */
            'ourConfigurations' => $groups
                ->flatMap
                ->neighbors
                ->mapWithKeys(fn (BgpNeighbor $neighbor): array => [
                    $neighbor->uuid => $this->peerConfigs->ourSide($neighbor),
                ])
                ->all(),

            /*
             * Cihaz AS numarası tanımlı değilken BGP'yi commit etmiyor.
             * Ekran bu yüzden adım adımdır: AS yoksa grup formu açılmaz.
             */
            'hasAutonomousSystem' => $switch->autonomous_system !== null,

            /*
             * Politika listesi cihazdan okunanlardır. Serbest metin
             * olsaydı, tanımsız bir ada atıf commit'i düşürürdü:
             * "Policy error: Policy X referenced but not defined".
             */
            'policyOptions' => $policies,
            'hasPolicies' => $policies !== [],
            'unmanageablePolicies' => $this->unmanageablePolicies($switch),

            'peeringAddressOptions' => $this->peeringAddressOptions(),

            'typeOptions' => $this->options(BgpGroupType::cases()),
            'gracefulRestartOptions' => $this->options(BgpGracefulRestart::cases()),
            'nlriOptions' => $this->options(BgpNlri::cases()),
            'familyOptions' => $this->options(IpFamily::cases()),
        ];
    }

    /**
     * Peering ve uplink için ayrılmış blokların KULLANILABİLİR ADRESLERİ.
     *
     * Adres elle yazılmaz, listeden seçilir; liste blok başlıklarına göre
     * gruplanır, böylece bir oturumun iki ucu aynı grubun içinden seçilir.
     *
     * Müşteri blokları listede yoktur: bunlar altyapının kendi private
     * adresleridir. Bölünmüş blok da yoktur — o bir havuzdur, adres taşımaz.
     *
     * @return array<string, array<string, string>>
     */
    private function peeringAddressOptions(): array
    {
        return $this->candidates->hostOptions([AddressPurpose::Peering, AddressPurpose::Uplink]);
    }

    /**
     * @param  Collection<int, BgpGroup>  $groups
     * @return array<string, int>
     */
    private function summary(Collection $groups): array
    {
        $neighbors = $groups->flatMap->neighbors;

        return [
            'groups' => $groups->count(),
            'neighbors' => $neighbors->count(),
            'established' => $neighbors->filter(fn ($neighbor): bool => $neighbor->state->isUp())->count(),
        ];
    }

    /**
     * Panelin temsil edemediği politikalar. Ekranda uyarı olarak gösterilir:
     * seçilebilirler ama içerikleri buradan düzenlenemez.
     *
     * @return list<string>
     */
    private function unmanageablePolicies(NetworkSwitch $switch): array
    {
        return $switch->policyStatements()
            ->where('is_manageable', false)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<int, BgpGroupType|BgpNlri|IpFamily|BgpGracefulRestart>  $cases
     * @return array<string, string>
     */
    private function options(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (BgpGroupType|BgpNlri|IpFamily|BgpGracefulRestart $case): array => [
                $case->value => $case->label(),
            ])
            ->all();
    }
}
