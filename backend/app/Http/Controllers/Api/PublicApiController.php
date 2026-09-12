<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Event;
use App\Models\Faq;
use App\Models\HomeSection;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\NavigationItem;
use App\Models\Resource;
use App\Models\Testimonial;
use App\Models\WebsiteSetting;
use Illuminate\Http\Request;

class PublicApiController extends Controller
{
    /**
     * Consolidated Public Home Page Payload.
     */
    public function home()
    {
        $sections = HomeSection::where('is_enabled', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        $categories = CourseCategory::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        $featuredCourses = Course::where('is_published', true)
            ->where('status', '!=', 'archived')
            ->orderBy('priority', 'asc')
            ->take(14)
            ->get();

        $learningPaths = LearningPath::where('is_published', true)
            ->with(['courses' => function ($q) {
                $q->where('is_published', true)->where('status', '!=', 'archived');
            }])
            ->orderBy('display_order', 'asc')
            ->take(6)
            ->get();

        $instructors = Instructor::where('is_active', true)
            ->orderBy('display_order', 'asc')
            ->take(6)
            ->get();

        $testimonials = Testimonial::where('is_published', true)
            ->orderBy('display_order', 'asc')
            ->take(6)
            ->get();

        $events = Event::where('is_published', true)
            ->orderBy('event_date', 'asc')
            ->take(3)
            ->get();

        $faqs = Faq::where('is_published', true)
            ->orderBy('display_order', 'asc')
            ->take(6)
            ->get();

        $settings = WebsiteSetting::pluck('value', 'key');

        return response()->json([
            'sections' => $sections,
            'categories' => $categories,
            'featured_courses' => $featuredCourses,
            'learning_paths' => $learningPaths,
            'instructors' => $instructors,
            'testimonials' => $testimonials,
            'events' => $events,
            'faqs' => $faqs,
            'settings' => $settings,
        ]);
    }

    /**
     * Public Course Categories.
     */
    public function categories()
    {
        $categories = CourseCategory::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json($categories);
    }

    /**
     * Public Courses with Category/Search/Priority Filtering.
     *
     * @deprecated API-006: superseded by GET /api/courses (CourseController::index),
     *             which is a superset (supports the same search/category/difficulty
     *             filters plus richer catalog metadata and SEC-001 visibility rules).
     *             Kept operational for backward compatibility until consumers migrate.
     */
    public function courses(Request $request)
    {
        $query = Course::where('is_published', true)->where('status', '!=', 'archived');

        if ($request->filled('category') && $request->category !== 'All') {
            $query->where('category', $request->category);
        }

        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $limit = $request->input('limit', 50);
        $courses = $query->orderBy('priority', 'asc')->take($limit)->get();

        return response()->json($courses);
    }

    /**
     * Public Single Course.
     *
     * @deprecated API-006: superseded by GET /api/courses/{id} (CourseController::show),
     *             which additionally handles SEC-001 visibility for unpublished
     *             courses. Kept operational for backward compatibility.
     */
    public function course(string $idOrSlug)
    {
        $course = Course::where('is_published', true)
            ->where('status', '!=', 'archived')
            ->where(function ($q) use ($idOrSlug) {
                if (is_numeric($idOrSlug)) {
                    $q->where('id', (int) $idOrSlug);
                } else {
                    $q->where('slug', $idOrSlug);
                }
            })
            ->with(['sections.lessons' => function ($q) {
                $q->where('is_published', true)->orderBy('sort_order', 'asc');
            }])
            ->firstOrFail();

        return response()->json($course);
    }

    /**
     * Public Instructors.
     */
    public function instructors()
    {
        $instructors = Instructor::where('is_active', true)
            ->orderBy('display_order', 'asc')
            ->get();

        return response()->json($instructors);
    }

    /**
     * Public Learning Paths.
     */
    public function learningPaths()
    {
        $paths = LearningPath::where('is_published', true)
            ->with(['courses' => function ($q) {
                $q->where('is_published', true);
            }])
            ->orderBy('display_order', 'asc')
            ->get();

        return response()->json($paths);
    }

    /**
     * Public Testimonials.
     */
    public function testimonials()
    {
        $testimonials = Testimonial::where('is_published', true)
            ->orderBy('display_order', 'asc')
            ->get();

        return response()->json($testimonials);
    }

    /**
     * Public FAQs.
     */
    public function faqs()
    {
        $faqs = Faq::where('is_published', true)
            ->orderBy('display_order', 'asc')
            ->get();

        return response()->json($faqs);
    }

    /**
     * Public Resources.
     */
    public function resources(Request $request)
    {
        $query = Resource::where('is_published', true);

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $resources = $query->orderBy('display_order', 'asc')->get();

        return response()->json($resources);
    }

    /**
     * Public Website Settings (Safe whitelist only).
     */
    public function settings()
    {
        $safeKeys = [
            'site_name',
            'site_tagline',
            'contact_email',
            'contact_phone',
            'whatsapp_number',
            'address',
            'footer_text',
            'copyright_text',
            'seo_title',
            'seo_description',
            'social_twitter',
            'social_linkedin',
            'social_youtube',
            'social_github',
            'graduates_badge_text',
            'maintenance_mode',
        ];

        $settings = WebsiteSetting::whereIn('key', $safeKeys)->pluck('value', 'key');

        return response()->json($settings);
    }

    /**
     * Public Navigation Menus.
     */
    public function navigation()
    {
        $header = NavigationItem::where('location', 'header')
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order', 'asc')
            ->get();

        $footerLearning = NavigationItem::where('location', 'footer_learning')
            ->where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        $footerSupport = NavigationItem::where('location', 'footer_support')
            ->where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json([
            'header' => $header,
            'footer_learning' => $footerLearning,
            'footer_support' => $footerSupport,
        ]);
    }
}
