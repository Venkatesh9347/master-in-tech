<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventRegistrationController extends Controller
{
    /**
     * Register user for an event
     */
    public function register(Request $request, $eventId)
    {
        $user = Auth::user();
        $event = Event::findOrFail($eventId);

        // Check if user is already registered
        $existingRegistration = EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingRegistration) {
            return response()->json(['message' => 'Already registered for this event'], 409);
        }

        // Check if event is full
        if ($event->isFull()) {
            return response()->json(['message' => 'This event is full'], 400);
        }

        // Check if event is completed or cancelled
        if ($event->status === 'completed' || $event->status === 'cancelled') {
            return response()->json(['message' => 'Cannot register for this event'], 400);
        }

        // Draft/unpublished events are not open for registration.
        if ($event->status !== 'published') {
            return response()->json(['message' => 'Registrations are not open for this event'], 400);
        }

        // Create registration
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'registered',
            'registered_at' => now(),
        ]);

        // Increment registered count
        $event->increment('registered_count');

        return response()->json([
            'message' => 'Registered successfully',
            'registration' => $registration
        ], 201);
    }

    /**
     * Cancel registration
     */
    public function cancel(Request $request, $eventId)
    {
        $user = Auth::user();
        $event = Event::findOrFail($eventId);

        $registration = EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Idempotent: repeat cancels must not drive the counter negative.
        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'Registration already cancelled']);
        }

        $registration->update(['status' => 'cancelled']);
        if ((int) $event->registered_count > 0) {
            $event->decrement('registered_count');
        }

        return response()->json(['message' => 'Registration cancelled']);
    }

    /**
     * Get user's registrations
     */
    public function myRegistrations()
    {
        $user = Auth::user();
        $registrations = EventRegistration::where('user_id', $user->id)
            ->with('event')
            ->get();

        return response()->json($registrations);
    }

    /**
     * Get my events
     */
    public function myEvents()
    {
        $user = Auth::user();
        $events = Event::whereHas('registrations', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('status', 'registered');
        })
            ->orderBy('event_date', 'asc')
            ->get();

        return response()->json($events);
    }

    /**
     * Check registration status for an event
     */
    public function checkRegistration($eventId)
    {
        $user = Auth::user();
        $registration = EventRegistration::where('event_id', $eventId)
            ->where('user_id', $user->id)
            ->first();

        if (!$registration) {
            return response()->json(['registered' => false]);
        }

        return response()->json([
            'registered' => true,
            'status' => $registration->status,
            'registration' => $registration
        ]);
    }
}
