<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use App\Services\Authorization\DeviceObjectAbilities;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * API jetonunun yetkisini SAHİBİNİNKİYLE kesiştirir.
 *
 * Jeton sahibi adına konuşur ama onu AŞAMAZ. Karar iki aşamalıdır:
 *
 *   1. Jeton bu kaynakta bu işi yapabiliyor mu? Yapamıyorsa burada biter.
 *   2. Yapabiliyorsa karar normal yetki zincirine BIRAKILIR — sahibinin
 *      policy'leri, kayıt bazlı istisnaları, yönetici geçişi.
 *
 * Böylece sahibinin yetkisi daraltıldığında jeton da daralır; jeton
 * unutulmuş bir arka kapıya dönüşmez.
 *
 * VARSAYILAN KAPALIDIR: yeteneği kaynağa çeviremediğimiz her durumda jeton
 * reddedilir. "Ne olduğunu bilmiyorum" ile "izinli" aynı şey değildir.
 */
class TokenAbilityGate
{
    public function __construct(
        private ApiTokenContext $context,
        private PermissionRegistry $registry,
        private DeviceObjectAbilities $deviceObjects,
    ) {}

    /**
     * Gate::before kancası.
     *
     * @param  array<int, mixed>  $arguments
     * @return bool|null false: reddet. null: normal zincire bırak.
     */
    public function check(User $user, string $ability, array $arguments): ?bool
    {
        $token = $this->context->get();

        // Oturumla gelen istek: daraltma yok.
        if ($token === null) {
            return null;
        }

        $pair = $this->resolve($ability, $arguments);

        if ($pair === null) {
            return false;
        }

        [$resource, $action] = $pair;

        return $token->permits($resource, $action) ? null : false;
    }

    /**
     * Yeteneği (kaynak, aksiyon) çiftine çevirir.
     *
     * @param  array<int, mixed>  $arguments
     * @return array{0: string, 1: string}|null
     */
    private function resolve(string $ability, array $arguments): ?array
    {
        // Cihaz nesneleri: "viewSwitchPorts" gibi üretilmiş yetenekler.
        $map = $this->deviceObjects->map();

        if (isset($map[$ability])) {
            return $map[$ability];
        }

        $subject = $arguments[0] ?? null;

        // Kayıt üzerinden: policy metodu + model.
        if ($subject instanceof Model) {
            return $this->pair($subject::class, $ability);
        }

        // Sınıf adı üzerinden: "viewAny", "create" gibi kayıtsız kontroller.
        if (is_string($subject) && class_exists($subject)) {
            return $this->pair($subject, $ability);
        }

        return null;
    }

    /**
     * @param  class-string  $model
     * @return array{0: string, 1: string}|null
     */
    private function pair(string $model, string $ability): ?array
    {
        try {
            $resource = $this->registry->resourceFor(new $model);
        } catch (InvalidArgumentException) {
            return null;
        }

        // "viewAny" jetonda "view" olarak sorulur; ayrı bir yetki değildir.
        $action = $ability === 'viewAny' ? 'view' : $ability;

        return $this->registry->supports($resource, $action) ? [$resource, $action] : null;
    }
}
