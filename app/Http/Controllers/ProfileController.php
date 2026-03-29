<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UploadsStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('profile', [
            'user' => $user,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'current_password' => ['nullable', 'string', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'profile_photo' => ['nullable', 'image', 'max:3072', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $tenantId = max(0, (int) $user->tenant_id);
        $profilePhotoPath = UploadsStorage::normalizePath($user->profile_photo_path);

        if ((bool) ($payload['remove_profile_photo'] ?? false)) {
            $this->deleteManagedUserProfilePhoto($profilePhotoPath, $tenantId, (int) $user->id);
            $profilePhotoPath = null;
        }

        if ($request->hasFile('profile_photo')) {
            $storedProfilePhotoPath = $this->storeUserProfilePhoto($request->file('profile_photo'), $tenantId, (int) $user->id);

            if ($storedProfilePhotoPath !== null) {
                $this->deleteManagedUserProfilePhoto(
                    $profilePhotoPath,
                    $tenantId,
                    (int) $user->id,
                    $storedProfilePhotoPath
                );

                $profilePhotoPath = $storedProfilePhotoPath;
            }
        }

        $updates = [
            'name' => trim((string) $payload['name']),
            'email' => mb_strtolower(trim((string) $payload['email'])),
            'phone' => filled($payload['phone'] ?? null)
                ? trim((string) $payload['phone'])
                : null,
            'profile_photo_path' => $profilePhotoPath !== null ? $profilePhotoPath : null,
        ];

        if (filled($payload['password'] ?? null)) {
            $updates['password'] = (string) $payload['password'];
        }

        $user->update($updates);

        return redirect()
            ->route('profile.index')
            ->with('status', 'Din profil er opdateret.');
    }

    private function storeUserProfilePhoto(?UploadedFile $file, int $tenantId, int $userId): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        if ($extension === '') {
            return null;
        }

        $directoryRelative = 'tenant-assets/' . $tenantId . '/users/' . $userId . '/profile';

        foreach (UploadsStorage::files($directoryRelative) as $existingFile) {
            if (preg_match('/\/profile\.[A-Za-z0-9]+$/', $existingFile) === 1) {
                UploadsStorage::delete($existingFile);
            }
        }

        $filename = 'profile.' . $extension;
        $storedPath = UploadsStorage::putFileAs($directoryRelative, $file, $filename);

        if (! is_string($storedPath) || $storedPath === '') {
            return null;
        }

        return $storedPath;
    }

    private function deleteManagedUserProfilePhoto(
        ?string $path,
        int $tenantId,
        int $userId,
        ?string $excludePath = null
    ): void {
        $trimmed = UploadsStorage::normalizePath($path);

        if ($trimmed === null) {
            return;
        }

        $normalizedExcludePath = UploadsStorage::normalizePath($excludePath);

        if ($normalizedExcludePath !== null && $trimmed === $normalizedExcludePath) {
            return;
        }

        $expectedPrefix = 'tenant-assets/' . $tenantId . '/users/' . $userId . '/profile/';

        if (! str_starts_with($trimmed, $expectedPrefix)) {
            return;
        }

        UploadsStorage::delete($trimmed);
    }
}
