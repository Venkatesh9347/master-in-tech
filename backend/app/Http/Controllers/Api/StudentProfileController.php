<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    /**
     * Get the authenticated student's profile details.
     */
    public function show(Request $request): JsonResponse
    {
        $this->ensureStudent($request);

        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'location' => $user->location,
                'bio' => $user->bio,
                'headline' => $user->headline,
                'expertise' => $user->expertise,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * Update the authenticated student's profile.
     */
    public function update(Request $request): JsonResponse
    {
        $this->ensureStudent($request);

        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'location' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:2000',
            'password' => 'nullable|string|min:6',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'location' => $user->location,
                'bio' => $user->bio,
                'headline' => $user->headline,
                'expertise' => $user->expertise,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * Only student accounts may use the student profile endpoints.
     */
    private function ensureStudent(Request $request): void
    {
        if (! $request->user()?->isStudent()) {
            abort(403, 'Only student accounts can access the student profile.');
        }
    }
}