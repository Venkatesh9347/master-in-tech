<?php

namespace Tests\Feature;

use App\Models\CallRecording;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Provider-independent call recordings: authorized CRM access, scoped
 * visibility, private storage with checksum, audited playback, admin-only
 * retention deletion.
 */
class CallRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $telecaller;
    private User $otherStaff;
    private Enquiry $ownLead;
    private Enquiry $otherLead;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('recordings');

        $this->telecaller = User::factory()->create(['role' => 'telecaller']);
        $this->otherStaff = User::factory()->create(['role' => 'telecaller']);

        $this->ownLead = Enquiry::create([
            'name' => 'Own Lead', 'email' => 'own-call@example.com', 'phone' => '9000000001',
            'status' => Enquiry::STATUS_NEW, 'assigned_counsellor_id' => $this->telecaller->id,
        ]);
        $this->otherLead = Enquiry::create([
            'name' => 'Other Lead', 'email' => 'other-call@example.com', 'phone' => '9000000002',
            'status' => Enquiry::STATUS_NEW, 'assigned_counsellor_id' => $this->otherStaff->id,
        ]);
    }

    public function test_unauthenticated_requests_rejected(): void
    {
        $this->getJson('/api/admin/crm/call-recordings')->assertUnauthorized();
        $this->postJson('/api/admin/crm/call-recordings', [])->assertUnauthorized();
    }

    public function test_non_crm_roles_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->getJson('/api/admin/crm/call-recordings')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'tutor']));
        $this->postJson('/api/admin/crm/call-recordings', [])->assertForbidden();
    }

    public function test_telecaller_logs_call_with_provider_reference(): void
    {
        Sanctum::actingAs($this->telecaller);

        $res = $this->postJson('/api/admin/crm/call-recordings', [
            'enquiry_id' => $this->ownLead->id,
            'direction' => 'outbound',
            'outcome' => 'interested',
            'notes' => 'Wants weekend batch.',
            'call_started_at' => now()->subMinutes(12)->toISOString(),
            'call_ended_at' => now()->toISOString(),
            'recording_provider' => 'exotel',
            'recording_reference' => 'exo_rec_12345',
        ])->assertCreated();

        $this->assertSame('ready', $res->json('recording.recording_status'));
        $this->assertSame(720, $res->json('recording.duration_seconds'));
        $this->assertSame($this->telecaller->id, $res->json('recording.handled_by'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'created_call_recording']);
    }

    public function test_scoped_visibility_and_idor(): void
    {
        $hidden = CallRecording::create([
            'enquiry_id' => $this->otherLead->id,
            'handled_by' => $this->otherStaff->id,
            'direction' => 'outbound',
        ]);

        Sanctum::actingAs($this->telecaller);

        $list = $this->getJson('/api/admin/crm/call-recordings')->assertOk()->json('data');
        $this->assertNotContains($hidden->id, collect($list)->pluck('id')->all());

        $this->getJson("/api/admin/crm/call-recordings/{$hidden->id}")->assertForbidden();
        $this->putJson("/api/admin/crm/call-recordings/{$hidden->id}", ['notes' => 'x'])->assertForbidden();
        $this->getJson("/api/admin/crm/call-recordings/{$hidden->id}/download")->assertForbidden();
    }

    public function test_cannot_attribute_handling_to_others(): void
    {
        Sanctum::actingAs($this->telecaller);

        $this->postJson('/api/admin/crm/call-recordings', [
            'enquiry_id' => $this->ownLead->id,
            'assigned_to' => $this->otherStaff->id,
        ])->assertForbidden();
    }

    public function test_upload_computes_checksum_and_stays_private(): void
    {
        Sanctum::actingAs($this->telecaller);

        $recording = CallRecording::create([
            'enquiry_id' => $this->ownLead->id,
            'handled_by' => $this->telecaller->id,
            'direction' => 'outbound',
        ]);

        $file = UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg');
        $res = $this->post("/api/admin/crm/call-recordings/{$recording->id}/upload", [
            'file' => $file,
        ])->assertCreated();

        $fresh = $recording->fresh();
        $this->assertSame('ready', $fresh->recording_status);
        $this->assertSame(64, strlen((string) $fresh->checksum));
        $this->assertSame('recordings', $fresh->storage_disk);
        $this->assertStringStartsWith('call-recordings/', (string) $fresh->storage_path);
        Storage::disk('recordings')->assertExists($fresh->storage_path);

        // Non-audio rejected.
        $this->post("/api/admin/crm/call-recordings/{$recording->id}/upload", [
            'file' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'uploaded_call_recording']);
    }

    public function test_download_requires_visibility_and_is_audited(): void
    {
        Sanctum::actingAs($this->telecaller);

        $recording = CallRecording::create([
            'enquiry_id' => $this->ownLead->id,
            'handled_by' => $this->telecaller->id,
            'direction' => 'outbound',
        ]);
        $this->post("/api/admin/crm/call-recordings/{$recording->id}/upload", [
            'file' => UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg'),
        ])->assertCreated();

        $dl = $this->get("/api/admin/crm/call-recordings/{$recording->id}/download");
        $dl->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'accessed_call_recording']);

        // Missing file → 404, not 500.
        $bare = CallRecording::create([
            'enquiry_id' => $this->ownLead->id,
            'handled_by' => $this->telecaller->id,
            'direction' => 'outbound',
            'recording_provider' => 'exotel',
            'recording_reference' => 'exo_none',
            'recording_status' => 'ready',
        ]);
        $this->get("/api/admin/crm/call-recordings/{$bare->id}/download")->assertNotFound();
    }

    public function test_delete_is_admin_only_and_removes_file(): void
    {
        Sanctum::actingAs($this->telecaller);

        $recording = CallRecording::create([
            'enquiry_id' => $this->ownLead->id,
            'handled_by' => $this->telecaller->id,
            'direction' => 'outbound',
        ]);
        $this->post("/api/admin/crm/call-recordings/{$recording->id}/upload", [
            'file' => UploadedFile::fake()->create('call.mp3', 500, 'audio/mpeg'),
        ])->assertCreated();
        $path = $recording->fresh()->storage_path;

        $this->deleteJson("/api/admin/crm/call-recordings/{$recording->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson("/api/admin/crm/call-recordings/{$recording->id}")->assertOk();
        $this->assertDatabaseMissing('call_recordings', ['id' => $recording->id]);
        Storage::disk('recordings')->assertMissing($path);
    }

    public function test_update_cannot_touch_file_fields(): void
    {
        Sanctum::actingAs($this->telecaller);

        $recording = CallRecording::create([
            'enquiry_id' => $this->ownLead->id,
            'handled_by' => $this->telecaller->id,
            'direction' => 'outbound',
        ]);

        $this->putJson("/api/admin/crm/call-recordings/{$recording->id}", [
            'outcome' => 'callback',
            'notes' => 'Call back Friday.',
            'storage_path' => 'https://evil.example/x.mp3',
            'checksum' => 'forged',
        ])->assertOk();

        $fresh = $recording->fresh();
        $this->assertSame('callback', $fresh->outcome);
        $this->assertNull($fresh->storage_path);
        $this->assertNull($fresh->checksum);
    }
}
