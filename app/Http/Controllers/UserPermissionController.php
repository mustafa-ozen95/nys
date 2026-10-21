<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\PermissionMatrixRequest;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Authorization\PermissionMatrixService;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserPermissionController extends Controller
{
    public function __construct(
        private PermissionMatrixService $matrix,
        private UserPermissionResolver $resolver,
        private ActivityRecorder $activity,
    ) {}

    public function show(Request $request, User $user): View
    {
        $this->authorize('managePermissions', $user);

        return view('users.permissions', $this->matrix->forUser($user, $request->string('resource')->toString()));
    }

    public function update(PermissionMatrixRequest $request, User $user): RedirectResponse
    {
        $this->authorize('managePermissions', $user);

        $resource = $request->resource();

        $this->resolver->syncResource(
            $user,
            $resource,
            $request->wildcardActions(),
            $request->allowedRecords(),
            $request->deniedRecords(),
            $request->wildcardDenied(),
        );

        $this->activity->updated($user, "Yetkiler güncellendi: {$user->email} · {$resource}");

        return redirect()
            ->route('users.permissions', ['user' => $user, 'resource' => $resource])
            ->with('status', 'Yetkiler kaydedildi.');
    }
}
