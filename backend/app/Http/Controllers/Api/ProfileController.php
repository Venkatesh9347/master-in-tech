<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Get the authenticated user's profile with relevant counts.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Update the authenticated user's own profile.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'location' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:2000',
            'headline' => 'nullable|string|max:255',
            'expertise' => 'nullable|string|max:255',
            'current_password' => 'nullable|string|required_with:password',
            'password' => ['nullable', 'string', 'confirmed', Password::min(6)],
            'avatar' => 'sometimes|nullable',
        ]);

        // Avatar: accept either a plain image URL (Google auth / media asset) or an uploaded image file.
        if ($request->hasFile('avatar')) {
            $file = $request->validate([
                'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048'],
            ])['avatar'];
            $path = $file->store('avatars', 'public');
            $validated['avatar'] = Storage::disk('public')->url($path);
        } elseif (array_key_exists('avatar', $validated)) {
            $validated['avatar'] = $validated['avatar'] === null
                ? null
                : $request->validate(['avatar' => ['nullable', 'string', 'max:2048']])['avatar'];
        }

        if (isset($validated['password']) && ! empty($validated['password'])) {
            if (! Hash::check($validated['current_password'] ?? '', $user->password)) {
                return response()->json([
                    'message' => 'The current password is incorrect.',
                    'errors' => ['current_password' => ['The current password is incorrect.']],
                ], 422);
            }
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }
        unset($validated['current_password'], $validated['password_confirmation']);

        // Never allow a user to change their own role.
        unset($validated['role']);

        $user->update($validated);

        \App\Models\AuditLog::log('updated_own_profile', $user, null, [
            'fields' => array_keys($validated),
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh(),
        ]);
    }
}
