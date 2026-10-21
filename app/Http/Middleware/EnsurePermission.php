<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Authorization\UserPermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kaynak geneli yetki kontrolü (kayıt bazlı kontroller Policy'dedir).
 *
 * Kullanımı: ->middleware(EnsurePermission::class.':view,switches')
 */
class EnsurePermission
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $action, string $resource): Response
    {
        abort_unless(
            $request->user() !== null && $this->resolver->allowsAny($request->user(), $resource, $action),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
