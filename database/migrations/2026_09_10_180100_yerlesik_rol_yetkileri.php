<?php

declare(strict_types=1);

use App\Enums\BadgeVariant;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Yerleşik rollerin varsayılan yetkileri ve eksik rol.
 *
 * MUHASEBE ROLÜ BURADA DA OLMALI: merkezdeki yerleşik rollerin burada
 * birebir karşılığı yoksa, merkezden gelen kişi "karşılığı yok" diye
 * varsayılan role (teknik) düşer ve kendisine ait olmayan yetkileri alırdı.
 *
 * Network ve teknik rollerinin şablonlarına DOKUNULMAZ: bu panelde ikisi de
 * zaten işin doğasına göre ayrılmış (network cihaza yazar, teknik görür ve
 * porta müdahale eder). Muhasebe ise ağ panelinde hiçbir şey yapmaz; yetki
 * gerekiyorsa kişiye özel istisnayla verilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Role::query()->where('slug', 'muhasebe')->exists()) {
            return;
        }

        $rol = new Role([
            'label' => 'Muhasebe',
            'description' => 'Ağ panelinde varsayılan yetkisi yoktur; gereken erişim kişiye özel verilir.',
            'badge' => BadgeVariant::Cyan,
            'is_admin' => false,
            'is_default' => false,
            'position' => 4,
            'template' => [],
        ]);

        $rol->slug = 'muhasebe';
        $rol->is_system = true;
        $rol->save();
    }

    public function down(): void
    {
        Role::query()->where('slug', 'muhasebe')->where('is_system', true)
            ->whereDoesntHave('users')->delete();
    }
};
