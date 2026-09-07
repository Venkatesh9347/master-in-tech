<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->getAuthenticatedUser($request);
        $isAdmin = $user && $user->role === 'admin';

        $fallbackCourses = [
            [
                'id' => 1,
                'title' => 'Full Stack Web Development',
                'description' => 'Learn frontend, backend, databases, and APIs to build production-ready web applications.',
                'instructor' => 'Sarah Johnson',
                'category' => 'Full Stack Development',
                'duration' => '12 weeks',
                'difficulty' => 'Beginner',
                'average_rating' => 4.9,
                'reviews_count' => 128,
                'students_count' => 1420,
                ...($isAdmin ? ['price' => 24999, 'internal_price' => 24999] : []),
            ],
            [
                'id' => 2,
                'title' => 'Python with AI & Machine Learning',
                'description' => 'Master Python programming and the foundations of artificial intelligence and ML workflows.',
                'instructor' => 'Aman Verma',
                'category' => 'AI & Machine Learning',
                'duration' => '10 weeks',
                'difficulty' => 'Intermediate',
                'average_rating' => 4.8,
                'reviews_count' => 96,
                'students_count' => 1105,
                ...($isAdmin ? ['price' => 19999, 'internal_price' => 19999] : []),
            ],
            [
                'id' => 3,
                'title' => 'SAP FICO Financial Accounting',
                'description' => 'Understand financial accounting, controlling, and enterprise reporting within SAP systems.',
                'instructor' => 'Neha Patel',
                'category' => 'Enterprise & SAP',
                'duration' => '8 weeks',
                'difficulty' => 'Advanced',
                'average_rating' => 4.9,
                'reviews_count' => 84,
                'students_count' => 870,
                ...($isAdmin ? ['price' => 17999, 'internal_price' => 17999] : []),
            ],
            [
                'id' => 4,
                'title' => 'Cloud & DevOps Engineering Mastery',
                'description' => 'Master AWS, Azure, Docker, Kubernetes, CI/CD pipelines, and Infrastructure as Code.',
                'instructor' => 'Rajesh Kumar',
                'category' => 'Cloud Computing',
                'duration' => '6 weeks',
                'difficulty' => 'Beginner',
                'average_rating' => 4.7,
                'reviews_count' => 210,
                'students_count' => 3200,
                ...($isAdmin ? ['price' => 0, 'internal_price' => 0] : []),
            ],
        ];

        if (! Schema::hasTable('courses')) {
            return response()->json($fallbackCourses);
        }

        // 1. If unauthenticated public visitor, serve from 60-second public catalog cache
        if (! $user) {
            $cacheVersion = (int) Cache::get('public_catalog_version', 1);
            $cacheKey = "public_courses_catalog_v{$cacheVersion}_" . md5(json_encode([
                'search' => $request->query('search'),
                'category' => $request->query('category'),
                'level' => $request->query('level') ?? $request->query('difficulty'),
                'page' => $request->query('page', 1),
            ]));

            $cachedData = Cache::remember($cacheKey, 60, function () use ($request, $fallbackCourses) {
                return $this->fetchCatalogData($request, false, [], $fallbackCourses);
            });

            return response()->json($cachedData);
        }

        // 2. Authenticated user (student or admin/tutor) — compute dynamically without caching
        $enrolledCourseIds = CourseEnrollment::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('course_id')
            ->all();

        return response()->json($this->fetchCatalogData($request, $isAdmin, $enrolledCourseIds, $fallbackCourses));
    }

    private function fetchCatalogData(Request $request, bool $isAdmin, array $enrolledCourseIds, array $fallbackCourses): array
    {
        $query = Course::withCount(['sections', 'lessons', 'enrollments', 'reviews'])
            ->withAvg('reviews', 'rating');

        // Non-admin queries only fetch published courses
        if (! $isAdmin) {
            $query->where('is_published', true);
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('instructor', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category') && $request->category !== 'All') {
            $cat = $request->category;
            $catNorm = str_replace('-', ' ', $cat);
            $query->where(function ($q) use ($cat, $catNorm) {
                $q->where('category', $cat)
                    ->orWhere('category', $catNorm)
                    ->orWhereRaw('LOWER(category) = ?', [strtolower($catNorm)]);

                $lower = strtolower($catNorm);
                if ($lower === 'ai & ml' || $lower === 'ai and ml' || $lower === 'ai & machine learning') {
                    $q->orWhere('category', 'Artificial Intelligence')
                        ->orWhere('category', 'Machine Learning')
                        ->orWhere('category', 'Generative AI')
                        ->orWhere('category', 'Deep Learning')
                        ->orWhere('category', 'Python with AI');
                } elseif ($lower === 'python') {
                    $q->orWhere('category', 'like', '%Python%')
                        ->orWhere('title', 'like', '%Python%');
                } elseif ($lower === 'healthcare') {
                    $q->orWhere('category', 'Medical Coding')
                        ->orWhere('category', 'like', '%Healthcare%')
                        ->orWhere('title', 'like', '%Medical%');
                } elseif ($lower === 'cloud & devops' || $lower === 'cloud and devops') {
                    $q->orWhere('category', 'Cloud Computing')
                        ->orWhere('category', 'DevOps')
                        ->orWhere('category', 'like', '%Cloud%')
                        ->orWhere('category', 'like', '%DevOps%');
                } elseif ($lower === 'data science') {
                    $q->orWhere('category', 'Data Analytics')
                        ->orWhere('category', 'Data Engineering');
                }
            });
        }

        $level = $request->query('level') ?? $request->query('difficulty');
        if ($level && $level !== 'All') {
            $query->where(function ($q) use ($level) {
                $q->where('difficulty', $level)
                    ->orWhereRaw('LOWER(difficulty) = ?', [strtolower($level)]);
            });
        }

        $courses = $query->orderByRaw('COALESCE(priority, 100) ASC')->orderBy('id', 'asc')->get();

        return $courses->isNotEmpty()
            ? $courses->map(fn (Course $course) => $this->courseData($course, $isAdmin, $enrolledCourseIds))->values()->all()
            : $fallbackCourses;
    }

    public function show(Request $request, $id)
    {
        $user = $this->getAuthenticatedUser($request);
        $isAdmin = $user && in_array($user->role, ['admin', 'super_admin'], true);

        $course = Course::where('id', $id)
            ->orWhere('slug', $id)
            ->withCount(['sections', 'lessons', 'enrollments', 'reviews'])
            ->withAvg('reviews', 'rating')
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        // SEC-001: Unpublished courses are only visible to admin, instructor, or enrolled students
        if (! $course->is_published) {
            $isEnrolled = false;
            $isInstructor = $user && (int) $course->instructor_id === (int) $user->id;

            if ($user) {
                $enrollment = CourseEnrollment::where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->where('status', '!=', 'dropped')
                    ->first();
                $isEnrolled = (bool) $enrollment;
            }

            if (! $isAdmin && ! $isInstructor && ! $isEnrolled) {
                return response()->json(['message' => 'Course not found'], 404);
            }
        }

        $isEnrolled = false;
        $enrollment = null;

        if ($user) {
            $enrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', '!=', 'dropped')
                ->first();
            $isEnrolled = (bool) $enrollment;
        }

        // Load curriculum hierarchy
        $isManager = $user && ($user->role === 'admin' || $user->role === 'super_admin' || $course->instructor_id === $user->id);
        $sectionsQuery = $course->sections()->orderBy('sort_order');
        if (! $isManager) {
            $sectionsQuery->where('is_published', true);
        }

        $sections = $sectionsQuery->with(['lessons' => function ($query) use ($isManager) {
            if (! $isManager) {
                $query->where('is_published', true);
            }
            $query->with(['quiz', 'assignment', 'resources'])
                ->orderBy('sort_order');
        }])->get();

        $data = $this->courseData($course, $isAdmin);
        $data['sections'] = $sections;
        $data['sections_count'] = $sections->count();
        $data['lessons_count'] = $sections->sum(fn ($s) => $s->lessons->count());
        $data['is_enrolled'] = $isEnrolled;
        $data['enrollment'] = $enrollment;

        return response()->json($data);
    }

    /**
     * Return list of distinct categories with course count (cached for 60s for public requests).
     */
    public function categories(Request $request)
    {
        $cacheVersion = (int) Cache::get('public_catalog_version', 1);
        $cacheKey = "public_course_categories_v{$cacheVersion}";

        $categories = Cache::remember($cacheKey, 60, function () {
            return Course::where('is_published', true)
                ->select('category', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
                ->whereNotNull('category')
                ->groupBy('category')
                ->orderBy('category')
                ->get()
                ->toArray();
        });

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['slug'] = Str::slug($data['title']) . '-' . Str::random(4);
        if ($request->user()) {
            $data['instructor_id'] = $request->user()->id;
        }

        $course = Course::create($data);
        $this->invalidatePublicCatalogCache();
        \App\Models\AuditLog::log('created_course', $course, null, $course->toArray());

        return response()->json($this->courseData($course, true), 201);
    }

    public function update(Request $request, Course $course)
    {
        $data = $this->validatedData($request);
        if (isset($data['title']) && $data['title'] !== $course->title) {
            $data['slug'] = Str::slug($data['title']) . '-' . Str::random(4);
        }
        if (isset($data['is_published']) && ! isset($data['status'])) {
            $data['status'] = $data['is_published'] ? 'published' : 'draft';
        } elseif (isset($data['status']) && ! isset($data['is_published'])) {
            $data['is_published'] = $data['status'] === 'published';
        }
        $old = $course->toArray();
        $course->update($data);
        $this->invalidatePublicCatalogCache();
        \App\Models\AuditLog::log('updated_course', $course, $old, $course->toArray());

        return response()->json($this->courseData($course->fresh(), true));
    }

    public function destroy(Course $course)
    {
        $old = $course->toArray();
        $course->delete();
        $this->invalidatePublicCatalogCache();
        \App\Models\AuditLog::log('deleted_course', null, $old, null);

        return response()->noContent();
    }

    public function downloadBrochure(Course $course)
    {
        $brochureUrl = $course->brochure ?? ($course->brochureMediaAsset?->url ?? null);

        if (! $brochureUrl) {
            return response()->json([
                'message' => 'Brochure currently unavailable.',
            ], 404);
        }

        if (request()->wantsJson() || request()->header('Accept') === 'application/json') {
            return response()->json([
                'brochure_url' => $brochureUrl,
                'course_title' => $course->title,
            ]);
        }

        return redirect()->away($brochureUrl);
    }

    /**
     * Invalidate public catalog caches on course mutations.
     */
    private function invalidatePublicCatalogCache(): void
    {
        try {
            Cache::increment('public_catalog_version');
        } catch (\Throwable $e) {
            Cache::put('public_catalog_version', time(), 86400);
        }
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string'],
            'full_description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'string', 'max:2000'],
            'banner' => ['nullable', 'string', 'max:2000'],
            'brochure' => ['nullable', 'string', 'max:2000'],
            'media_id' => ['nullable', 'exists:media_assets,id'],
            'brochure_media_id' => ['nullable', 'exists:media_assets,id'],
            'instructor' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'instructor_id' => ['nullable', 'exists:users,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'difficulty' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'prerequisites' => ['nullable', 'array'],
            'learning_objectives' => ['nullable', 'array'],
            'skills_gained' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:draft,published'],
            'is_published' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
        ]);
    }

    private function courseData(Course $course, bool $isAdmin = false, array $enrolledCourseIds = []): array
    {
        $avgRating = isset($course->reviews_avg_rating) && $course->reviews_avg_rating !== null
            ? round((float) $course->reviews_avg_rating, 1)
            : 4.9;

        $reviewsCount = $course->reviews_count ?? ($course->relationLoaded('reviews') ? $course->reviews->count() : 18);
        $enrollmentsCount = $course->enrollments_count ?? ($course->relationLoaded('enrollments') ? $course->enrollments->count() : 75);

        $payload = [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'full_description' => $course->full_description ?? $course->description,
            'category' => $course->category ?? 'Software Engineering',
            'thumbnail' => $course->thumbnail,
            'banner' => $course->banner ?? $course->thumbnail,
            'brochure' => $course->brochure ?? ($course->brochureMediaAsset?->url ?? null),
            'media_id' => $course->media_id,
            'brochure_media_id' => $course->brochure_media_id,
            'instructor' => $course->instructor,
            'instructor_id' => $course->instructor_id,
            'duration' => $course->duration,
            'difficulty' => $course->difficulty ?? 'Intermediate',
            'prerequisites' => $course->prerequisites ?? [],
            'learning_objectives' => $course->learning_objectives ?? [],
            'skills_gained' => $course->skills_gained ?? [],
            'status' => $course->status ?? ($course->is_published ? 'published' : 'draft'),
            'is_published' => (bool) $course->is_published,
            'priority' => (int) ($course->priority ?? 100),
            'average_rating' => $avgRating,
            'reviews_count' => $reviewsCount,
            'students_count' => $enrollmentsCount,
            'sections_count' => $course->sections_count ?? ($course->relationLoaded('sections') ? $course->sections->count() : 0),
            'lessons_count' => $course->lessons_count ?? ($course->relationLoaded('lessons') ? $course->lessons->count() : 0),
        ];

        if (! empty($enrolledCourseIds)) {
            $payload['is_enrolled'] = in_array($course->id, $enrolledCourseIds);
        }

        // INTERNAL PRICING: Only exposed to authenticated administrators
        if ($isAdmin) {
            $payload['price'] = (float) $course->price;
            $payload['internal_price'] = (float) $course->price;
        }

        return $payload;
    }

    private function getAuthenticatedUser(Request $request)
    {
        return $request->user() ?? $request->user('sanctum') ?? auth('sanctum')->user() ?? auth()->user();
    }
}
