<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionEffect;
use App\Models\Server;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Ağ ekibinin salt okuma yetkileri.
 *
 * Dağılım: kullanıcı yönetimi hariç her kaynağa
 * "görüntüle", sunucularda ise tekil kayıt izni. "Bağlan" yetkisi bilinçli
 * olarak verilmez; uzak oturum açma yetkisi elle açılır.
 */
class NetworkTeamPermissionSeeder extends Seeder
{
    /**
     * Ağ ekibine açılmayan sunucular — liste yerel dosyadadır.
     *
     * @return list<string>
     */
    private function haricTutulanlar(): array
    {
        /** @var list<string> */
        return YerelEnvanter::al('ekibe_kapali_sunucular');
    }

    public function __construct(private PermissionRegistry $registry) {}

    public function run(): void
    {
        $emails = array_column(NetworkTeamSeeder::members(), 'email');
        $users = User::query()->whereIn('email', $emails)->get();
        $serverIds = Server::query()
            ->whereNotIn('ip_address', $this->haricTutulanlar())
            ->pluck('id');

        foreach ($users as $user) {
            foreach ($this->registry->resourceKeys() as $resource) {
                if ($resource === 'users') {
                    continue;
                }

                $records = $resource === 'servers' ? $serverIds->all() : [UserPermission::ALL_RECORDS];

                foreach ($records as $recordId) {
                    $this->allow($user, $resource, $recordId);
                }
            }
        }
    }

    private function allow(User $user, string $resource, int $recordId): void
    {
        UserPermission::updateOrCreate(
            [
                'user_id' => $user->id,
                'resource' => $resource,
                'resource_id' => $recordId,
                'action' => 'view',
            ],
            ['effect' => PermissionEffect::Allow],
        );
    }
}
