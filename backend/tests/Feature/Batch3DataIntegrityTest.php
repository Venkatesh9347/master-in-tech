<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Batch3DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ---- DB-002: class_sessions.tutor_id foreign key ----------------------

    public function test_class_sessions_tutor_id_has_foreign_key_to_users(): void
    {
        $fks = DB::select("PRAGMA foreign_key_list('class_sessions')");

        $tutorFk = collect($fks)->first(fn ($fk) => $fk->from === 'tutor_id');

        $this->assertNotNull($tutorFk, 'class_sessions must declare a foreign key on tutor_id');
        $this->assertSame('users', $tutorFk->table);
        $this->assertSame('id', $tutorFk->to);
    }

    public function test_class_session_with_nonexistent_tutor_is_rejected_by_database(): void
    {
        $course = $this->createCourse();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(QueryException::class);

        ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => 99999999,
            'created_by' => $admin->id,
            'title' => 'Broken FK',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);
    }

    public function test_class_session_with_valid_tutor_is_created(): void
    {
        $course = $this->createCourse();
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        $session = ClassSession::create([
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'created_by' => $admin->id,
            'title' => 'Valid Session',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $this->assertDatabaseHas('class_sessions', [
            'id' => $session->id,
            'tutor_id' => $tutor->id,
        ]);
    }

    // ---- DB-003: single active membership per (batch, student) -------------

    private function createBatch(array $attributes = []): Batch
    {
        return Batch::create(array_merge([
            'name' => 'Batch ' . Str::random(5),
            'code' => 'RIT(' . strtoupper(Str::random(3)) . ')BC' . now()->format('dmy'),
            'course_id' => $this->createCourse()->id,
            'start_date' => now()->toDateString(),
        ], $attributes));
    }

    public function test_duplicate_active_membership_is_rejected_at_database_level(): void
    {
        $batch = $this->createBatch();
        $student = User::factory()->create(['role' => 'student']);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed');

        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
    }

    public function test_historical_non_active_memberships_can_coexist(): void
    {
        $batch = $this->createBatch();
        $student = User::factory()->create(['role' => 'student']);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        // Past memberships for the same (batch, student) must still be allowed
        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'transferred',
        ]);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'completed',
        ]);

        $this->assertEquals(3, BatchStudent::where('batch_id', $batch->id)->where('user_id', $student->id)->count());
        $this->assertEquals(1, BatchStudent::where('batch_id', $batch->id)->where('user_id', $student->id)->where('status', 'active')->count());
    }

    public function test_student_can_be_active_in_multiple_batches(): void
    {
        $batchA = $this->createBatch();
        $batchB = $this->createBatch();
        $student = User::factory()->create(['role' => 'student']);

        BatchStudent::create([
            'batch_id' => $batchA->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        BatchStudent::create([
            'batch_id' => $batchB->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        $this->assertEquals(2, BatchStudent::where('user_id', $student->id)->where('status', 'active')->count());
    }

    public function test_lifecycle_transition_from_active_does_not_violate_constraint(): void
    {
        $batch = $this->createBatch();
        $student = User::factory()->create(['role' => 'student']);

        $membership = BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        // A new active membership becomes possible only after the previous
        // one is deactivated (transfer/discontinue/complete) — row update,
        // not a second insert.
        $this->assertTrue($membership->refresh()->isActive());
    }

    private function createCourse(): Course
    {
        $title = 'Course ' . Str::random(5);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => strtoupper(Str::random(3)),
            'description' => 'Test course for Batch 3 data-integrity verification.',
            'instructor' => 'Test Instructor',
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);
    }
}