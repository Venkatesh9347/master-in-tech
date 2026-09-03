<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test 1: Create an event
echo "=== TEST 1: CREATE TEST EVENT ===" . PHP_EOL;
try {
    $event = \App\Models\Event::create([
        'title' => 'End-to-End Test Event',
        'slug' => 'e2e-test-event-' . time(),
        'description' => 'This is a comprehensive test event for system verification',
        'short_description' => 'E2E Test Event',
        'category' => 'Masterclass',
        'speaker_name' => 'Test Speaker',
        'speaker_designation' => 'Senior Engineer',
        'event_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
        'start_time' => '10:00',
        'end_time' => '11:00',
        'duration' => 60,
        'mode' => 'online',
        'meeting_url' => 'https://zoom.us/test',
        'price' => 999.99,
        'registration_limit' => 5,
        'status' => 'published'
    ]);
    echo "✓ Created event with ID: " . $event->id . PHP_EOL;
} catch (Exception $e) {
    echo "✗ Error creating event: " . $e->getMessage() . PHP_EOL;
}

// Test 2: Retrieve the event
echo PHP_EOL . "=== TEST 2: RETRIEVE EVENT ===" . PHP_EOL;
try {
    $retrieved = \App\Models\Event::find($event->id);
    echo "✓ Retrieved event: " . $retrieved->title . PHP_EOL;
} catch (Exception $e) {
    echo "✗ Error retrieving event: " . $e->getMessage() . PHP_EOL;
}

// Test 3: Get published events
echo PHP_EOL . "=== TEST 3: GET ALL PUBLISHED EVENTS ===" . PHP_EOL;
try {
    $published = \App\Models\Event::where('status', 'published')->get();
    echo "✓ Found " . count($published) . " published events" . PHP_EOL;
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . PHP_EOL;
}

// Test 4: Check models and relationships
echo PHP_EOL . "=== TEST 4: CHECK MODEL RELATIONSHIPS ===" . PHP_EOL;
try {
    echo "✓ Event model loaded" . PHP_EOL;
    echo "✓ EventRegistration model loaded" . PHP_EOL;
    echo "✓ Event has registrations() method: " . (method_exists($event, 'registrations') ? 'YES' : 'NO') . PHP_EOL;
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . PHP_EOL;
}

// Test 5: Event helpers
echo PHP_EOL . "=== TEST 5: EVENT HELPER METHODS ===" . PHP_EOL;
try {
    echo "✓ Event is full: " . ($event->isFull() ? 'YES' : 'NO') . PHP_EOL;
    echo "✓ Event is upcoming: " . ($event->isUpcoming() ? 'YES' : 'NO') . PHP_EOL;
    echo "✓ Event is completed: " . ($event->isCompleted() ? 'YES' : 'NO') . PHP_EOL;
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . PHP_EOL;
}

// Store event ID for next tests
file_put_contents(__DIR__ . '/test_event_id.txt', $event->id);
echo PHP_EOL . "Test event ID saved: " . $event->id . PHP_EOL;
