<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /**
     * Get all published events
     */
    public function index()
    {
        $events = Event::where('status', 'published')
            ->orderBy('event_date', 'asc')
            ->get();

        return response()->json($events);
    }

    /**
     * Get single event by ID or slug
     */
    public function show($id)
    {
        $event = Event::where('id', $id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        if ($event->status !== 'published') {
            return response()->json(['message' => 'Event not found'], 404);
        }

        return response()->json($event);
    }

    /**
     * Get upcoming events
     */
    public function upcoming()
    {
        $events = Event::where('status', 'published')
            ->where('event_date', '>', now())
            ->orderBy('event_date', 'asc')
            ->get();

        return response()->json($events);
    }

    /**
     * Get past/completed events
     */
    public function past()
    {
        $events = Event::where('status', 'published')
            ->where('event_date', '<', now())
            ->orderBy('event_date', 'desc')
            ->get();

        return response()->json($events);
    }
}
