<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        ]);

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
