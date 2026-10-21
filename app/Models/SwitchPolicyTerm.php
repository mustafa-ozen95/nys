<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use App\Support\Netconf\PolicyMatch;
use App\Support\Netconf\PolicyThen;
use Database\Factories\SwitchPolicyTermFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Politikanın tek bir terimi. Sıra anlamlıdır (bkz. position).
 *
 * prefix-list-filter kendi sütunlarında durur (match_prefix_lists +
 * match_type); "from" ve "then"in geri kalanı match_conditions ve
 * then_actions içinde, PolicyMatch/PolicyThen'in tanımladığı biçimdedir.
 */
class SwitchPolicyTerm extends Model
{
    /** @use HasFactory<SwitchPolicyTermFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    protected $fillable = [
        'switch_policy_statement_id',
        'name',
        'position',
        'match_prefix_lists',
        'match_type',
        'match_conditions',
        'action',
        'community_delete',
        'then_actions',
        'residual_xml',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'match_prefix_lists' => 'array',
            'match_type' => PrefixMatchType::class,
            'match_conditions' => 'array',
            'action' => PolicyAction::class,
            'then_actions' => 'array',
            'residual_xml' => 'array',
            'is_inactive' => 'boolean',
        ];
    }

    /** @return BelongsTo<SwitchPolicyStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(SwitchPolicyStatement::class, 'switch_policy_statement_id');
    }

    /**
     * Terimin atıfta bulunduğu community adları.
     *
     * Silme eski sütunda, ekleme/yerine yazma then_actions içinde durur;
     * bağımlılık denetimi ikisini de görmeli, yoksa "community add" ile
     * kullanılan bir tanım silinebilir hâle gelirdi.
     *
     * @return Attribute<list<string>, never>
     */
    protected function referencedCommunities(): Attribute
    {
        return Attribute::get(function (): array {
            $adlar = array_filter([$this->community_delete]);

            foreach ($this->then_actions['communities'] ?? [] as $community) {
                if (filled($community['name'] ?? null)) {
                    $adlar[] = (string) $community['name'];
                }
            }

            return array_values(array_unique([...$adlar, ...($this->match_conditions['communities'] ?? [])]));
        });
    }

    /**
     * Terimin atıfta bulunduğu as-path adları.
     *
     * @return Attribute<list<string>, never>
     */
    protected function referencedAsPaths(): Attribute
    {
        return Attribute::get(fn (): array => array_values(array_unique(
            (array) ($this->match_conditions['as_paths'] ?? []),
        )));
    }

    /**
     * Terimin ekrandaki tek satırlık özeti.
     *
     * Blade içinde birleştirilmez; model hazır verir (bkz. .ai/rules/interface.md).
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(function (): string {
            $kosullar = [];

            if ($this->match_prefix_lists) {
                $liste = implode(', ', $this->match_prefix_lists);
                $tur = $this->match_type?->label();
                $kosullar[] = $tur === null ? $liste : "{$liste} ({$tur})";
            }

            $kosullar = [...$kosullar, ...PolicyMatch::summary($this->match_conditions)];

            // Eşleşme koşulu olmayan terim HER ŞEYE uyar; genellikle sonuncu
            // terimdir ve zinciri kapatır.
            $parcalar = [$kosullar === [] ? 'her şey' : implode(', ', $kosullar)];

            /*
             * Sonlandırıcı eylem terimde KENDİ sütununda durur (action);
             * then_actions yalnızca değiştirici eylemleri taşır. İkisi iki
             * yerde tutulsaydı biri diğerinden habersiz güncellenirdi.
             */
            $eylemler = PolicyThen::summary($this->then_actions);

            /*
             * Eski kayıtlarda community silme yalnızca kendi sütunundadır;
             * yeni okuyucu onu eylem listesine de yazıyor. Zorlamalı
             * eşitlemeden önce özet bilgisiz kalmasın diye ikisi de bakılır,
             * ama satır bir kez yazılır.
             */
            $silinen = $this->community_delete;
            $listede = in_array($silinen, array_column($this->then_actions['communities'] ?? [], 'name'), true);

            if ($silinen !== null && ! $listede) {
                array_unshift($eylemler, "community sil: {$silinen}");
            }

            $etiket = $this->action?->label();

            if ($etiket !== null) {
                $eylemler[] = $etiket;
            }

            $parcalar[] = $eylemler === [] ? 'eylem yok' : implode(', ', $eylemler);

            return implode(' · ', $parcalar);
        });
    }

    /**
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [
            $this->residual_xml,
            $this->match_conditions['residual'] ?? null,
            $this->then_actions['residual'] ?? null,
        ];
    }
}
