#!/usr/bin/env php
<?php
// API Test Script
// Tests all event API endpoints

$baseUrl = 'http://127.0.0.1:8000/api';
$eventId = file_get_contents(__DIR__ . '/test_event_id.txt');

function curlRequest($method, $url, $data = null, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

echo "=== API ENDPOINT TESTS ===" . PHP_EOL . PHP_EOL;

// Test 1: GET /api/events
echo "TEST 1: GET /api/events" . PHP_EOL;
$result = curlRequest('GET', $baseUrl . '/events');
echo "Status: " . $result['status'] . PHP_EOL;
echo "Response is array: " . (is_array($result['body']) ? 'YES' : 'NO') . PHP_EOL;
echo "Event count: " . (is_array($result['body']) ? count($result['body']) : 0) . PHP_EOL;
echo ($result['status'] === 200 ? "✓ PASS" : "✗ FAIL") . PHP_EOL . PHP_EOL;

// Test 2: GET /api/events/{id}
echo "TEST 2: GET /api/events/{id}" . PHP_EOL;
$result = curlRequest('GET', $baseUrl . '/events/' . $eventId);
echo "Status: " . $result['status'] . PHP_EOL;
echo "Event title: " . ($result['body']['title'] ?? 'N/A') . PHP_EOL;
echo ($result['status'] === 200 ? "✓ PASS" : "✗ FAIL") . PHP_EOL . PHP_EOL;

// Test 3: GET /api/events/upcoming
echo "TEST 3: GET /api/events/upcoming" . PHP_EOL;
$result = curlRequest('GET', $baseUrl . '/events/upcoming');
echo "Status: " . $result['status'] . PHP_EOL;
echo "Upcoming events: " . (is_array($result['body']) ? count($result['body']) : 0) . PHP_EOL;
echo ($result['status'] === 200 ? "✓ PASS" : "✗ FAIL") . PHP_EOL . PHP_EOL;

// Test 4: GET /api/events/past
echo "TEST 4: GET /api/events/past" . PHP_EOL;
$result = curlRequest('GET', $baseUrl . '/events/past');
echo "Status: " . $result['status'] . PHP_EOL;
echo "Past events: " . (is_array($result['body']) ? count($result['body']) : 0) . PHP_EOL;
echo ($result['status'] === 200 ? "✓ PASS" : "✗ FAIL") . PHP_EOL . PHP_EOL;

// Test 5: Without authentication - should get 401 on protected endpoints
echo "TEST 5: PROTECTED ENDPOINTS (should return 401 unauthenticated)" . PHP_EOL;
$result = curlRequest('POST', $baseUrl . '/events/' . $eventId . '/register');
echo "Status: " . $result['status'] . PHP_EOL;
echo ($result['status'] === 401 ? "✓ PASS" : "✗ FAIL") . PHP_EOL . PHP_EOL;

echo "=== API TESTS COMPLETE ===" . PHP_EOL;
