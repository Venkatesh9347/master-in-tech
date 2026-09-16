<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminEventController extends Controller
{
    /**
     * Get all events (admin only)
     */
    public function index()
    {
        $events = Event::orderBy('event_date', 'asc')->get();
        return response()->json($events);
    }

    /**
     * Create a new event
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'short_description' => 'nullable|string|max:500',
            'banner' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'speaker_name' => 'required|string|max:255',
            'speaker_designation' => 'required|string|max:255',
            'speaker_image' => 'nullable|string',
            'event_date' => 'required|date_format:Y-m-d H:i',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'duration' => 'required|integer|min:1',
            'mode' => 'required|in:online,offline',
            'meeting_url' => 'nullable|url',
            'location' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'registration_limit' => 'nullable|integer|min:1',
            'status' => 'required|in:draft,published,completed,cancelled',
        ]);

        $validated['slug'] = Str::slug($validated['title']);

        $event = Event::create($validated);

        AuditLog::log('created_event', $event, null, $event->toArray());

        return response()->json($event, 201);
    }

    /**
     * Get single event
     */
    public function show($id)
    {
        $event = Event::findOrFail($id);
        return response()->json($event);
    }

    /**
     * Update event
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'title' => 'string|max:255',
            'description' => 'string',
            'short_description' => 'nullable|string|max:500',
            'banner' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'speaker_name' => 'string|max:255',
            'speaker_designation' => 'string|max:255',
            'speaker_image' => 'nullable|string',
            'event_date' => 'date_format:Y-m-d H:i',
            'start_time' => 'date_format:H:i',
            'end_time' => 'date_format:H:i',
            'duration' => 'integer|min:1',
            'mode' => 'in:online,offline',
            'meeting_url' => 'nullable|url',
            'location' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'registration_limit' => 'nullable|integer|min:1',
            'status' => 'in:draft,published,completed,cancelled',
        ]);

        if (isset($validated['title'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }

        $old = $event->toArray();

        $event->update($validated);

        AuditLog::log('updated_event', $event, $old, $event->fresh()->toArray());

        return response()->json($event);
    }

    /**
     * Delete event
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);
        $old = $event->toArray();
        $event->delete();

        AuditLog::log('deleted_event', null, $old, null);

        return response()->noContent();
    }

    /**
     * Get registrations for an event
     */
    public function registrations($eventId)
    {
        $event = Event::findOrFail($eventId);
        $registrations = EventRegistration::where('event_id', $eventId)
            ->with('user')
            ->get();

        return response()->json([
            'event' => $event,
            'registrations' => $registrations
        ]);
    }

    /**
     * Get event statistics
     */
    public function stats()
    {
        $totalEvents = Event::count();
        $upcomingEvents = Event::where('event_date', '>', now())->count();
        $completedEvents = Event::where('event_date', '<', now())->count();
        $totalRegistrations = EventRegistration::count();

        return response()->json([
            'total_events' => $totalEvents,
            'upcoming_events' => $upcomingEvents,
            'completed_events' => $completedEvents,
            'total_registrations' => $totalRegistrations,
        ]);
    }
}
