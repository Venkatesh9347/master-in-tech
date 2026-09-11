<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Faq;
use App\Models\HomeSection;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\MediaAsset;
use App\Models\NavigationItem;
use App\Models\Resource;
use App\Models\Testimonial;
use App\Models\WebsiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminCmsController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | 1. COURSE CATEGORIES
    |--------------------------------------------------------------------------
    */
    public function categories()
    {
        $categories = CourseCategory::withCount('courses')
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json($categories);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:course_categories,slug',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category = CourseCategory::create($validated);
        AuditLog::log('created', $category, null, $category->toArray());

        return response()->json([
            'message' => "Category '{$category->name}' created successfully.",
            'category' => $category,
        ], 201);
    }

    public function updateCategory(Request $request, CourseCategory $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => "nullable|string|max:255|unique:course_categories,slug,{$category->id}",
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $old = $category->toArray();
        $category->update($validated);
        AuditLog::log('updated', $category, $old, $category->toArray());

        return response()->json([
            'message' => "Category '{$category->name}' updated successfully.",
            'category' => $category,
        ]);
    }

    public function destroyCategory(CourseCategory $category)
    {
        $old = $category->toArray();
        $name = $category->name;
        $category->delete();
        AuditLog::log('deleted', null, $old, null);

        return response()->json([
            'message' => "Category '{$name}' deleted successfully.",
        ]);
    }

    public function reorderCategories(Request $request)
    {
        $validated = $request->validate([
            'orders' => 'required|array',
            'orders.*.id' => 'required|exists:course_categories,id',
            'orders.*.sort_order' => 'required|integer',
        ]);

        foreach ($validated['orders'] as $item) {
            CourseCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        AuditLog::log('reordered_categories', null, null, $validated['orders']);

        return response()->json(['message' => 'Categories reordered successfully.']);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. HOME PAGE CMS
    |--------------------------------------------------------------------------
    */
    public function homeSections()
    {
        $sections = HomeSection::orderBy('sort_order', 'asc')->get();
        return response()->json($sections);
    }

    public function updateHomeSection(Request $request, HomeSection $section)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string',
            'badge' => 'nullable|string|max:100',
            'content' => 'nullable|array',
            'is_enabled' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $old = $section->toArray();
        $section->update($validated);
        AuditLog::log('updated_home_section', $section, $old, $section->toArray());

        return response()->json([
            'message' => "Section '{$section->section_key}' updated successfully.",
            'section' => $section,
        ]);
    }

    public function toggleHomeSection(HomeSection $section)
    {
        $section->is_enabled = ! $section->is_enabled;
        $section->save();

        AuditLog::log('toggled_home_section', $section, null, ['is_enabled' => $section->is_enabled]);

        return response()->json([
            'message' => "Section '{$section->section_key}' is now " . ($section->is_enabled ? 'enabled' : 'disabled') . '.',
            'section' => $section,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. INSTRUCTORS
    |--------------------------------------------------------------------------
    */
    public function instructors()
    {
        $instructors = Instructor::orderBy('display_order', 'asc')->get();
        return response()->json($instructors);
    }

    public function storeInstructor(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'designation' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string|max:500',
            'rating' => 'nullable|string|max:50',
            'graduates_count' => 'nullable|string|max:50',
            'experience_years' => 'nullable|string|max:50',
            'skills' => 'nullable|array',
            'social_links' => 'nullable|array',
            'display_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $instructor = Instructor::create($validated);
        AuditLog::log('created_instructor', $instructor, null, $instructor->toArray());

        return response()->json([
            'message' => "Faculty member '{$instructor->name}' created successfully.",
            'instructor' => $instructor,
        ], 201);
    }

    public function updateInstructor(Request $request, Instructor $instructor)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'designation' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string|max:500',
            'rating' => 'nullable|string|max:50',
            'graduates_count' => 'nullable|string|max:50',
            'experience_years' => 'nullable|string|max:50',
            'skills' => 'nullable|array',
            'social_links' => 'nullable|array',
            'display_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $old = $instructor->toArray();
        $instructor->update($validated);
        AuditLog::log('updated_instructor', $instructor, $old, $instructor->toArray());

        return response()->json([
            'message' => "Faculty member '{$instructor->name}' updated successfully.",
            'instructor' => $instructor,
        ]);
    }

    public function destroyInstructor(Instructor $instructor)
    {
        $old = $instructor->toArray();
        $name = $instructor->name;
        $instructor->delete();
        AuditLog::log('deleted_instructor', null, $old, null);

        return response()->json(['message' => "Faculty member '{$name}' deleted successfully."]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. LEARNING PATHS
    |--------------------------------------------------------------------------
    */
    public function learningPaths()
    {
        $paths = LearningPath::with('courses')->orderBy('display_order', 'asc')->get();
        return response()->json($paths);
    }

    public function storeLearningPath(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:learning_paths,slug',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'difficulty' => 'nullable|string|max:100',
            'estimated_duration' => 'nullable|string|max:100',
            'display_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
        ]);

        $path = LearningPath::create($validated);

        if (! empty($validated['course_ids'])) {
            $syncData = [];
            foreach ($validated['course_ids'] as $idx => $cId) {
                $syncData[$cId] = ['sort_order' => $idx + 1];
            }
            $path->courses()->sync($syncData);
        }

        AuditLog::log('created_learning_path', $path, null, $path->load('courses')->toArray());

        return response()->json([
            'message' => "Learning Path '{$path->title}' created successfully.",
            'learning_path' => $path->load('courses'),
        ], 201);
    }

    public function updateLearningPath(Request $request, LearningPath $learningPath)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => "nullable|string|max:255|unique:learning_paths,slug,{$learningPath->id}",
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'difficulty' => 'nullable|string|max:100',
            'estimated_duration' => 'nullable|string|max:100',
            'display_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
        ]);

        $old = $learningPath->load('courses')->toArray();
        $learningPath->update($validated);

        if (isset($validated['course_ids'])) {
            $syncData = [];
            foreach ($validated['course_ids'] as $idx => $cId) {
                $syncData[$cId] = ['sort_order' => $idx + 1];
            }
            $learningPath->courses()->sync($syncData);
        }

        AuditLog::log('updated_learning_path', $learningPath, $old, $learningPath->load('courses')->toArray());

        return response()->json([
            'message' => "Learning Path '{$learningPath->title}' updated successfully.",
            'learning_path' => $learningPath->load('courses'),
        ]);
    }

    public function destroyLearningPath(LearningPath $learningPath)
    {
        $old = $learningPath->toArray();
        $title = $learningPath->title;
        $learningPath->delete();
        AuditLog::log('deleted_learning_path', null, $old, null);

        return response()->json(['message' => "Learning Path '{$title}' deleted successfully."]);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. TESTIMONIALS
    |--------------------------------------------------------------------------
    */
    public function testimonials()
    {
        $testimonials = Testimonial::orderBy('display_order', 'asc')->get();
        return response()->json($testimonials);
    }

    public function storeTestimonial(Request $request)
    {
        $validated = $request->validate([
            'student_name' => 'required|string|max:255',
            'student_photo' => 'nullable|string|max:500',
            'student_role_or_company' => 'nullable|string|max:255',
            'course_id' => 'nullable|exists:courses,id',
            'course_title' => 'nullable|string|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'content' => 'required|string',
            'display_order' => 'nullable|integer',
            'is_featured' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
        ]);

        $testimonial = Testimonial::create($validated);
        AuditLog::log('created_testimonial', $testimonial, null, $testimonial->toArray());

        return response()->json([
            'message' => "Testimonial by '{$testimonial->student_name}' created successfully.",
            'testimonial' => $testimonial,
        ], 201);
    }

    public function updateTestimonial(Request $request, Testimonial $testimonial)
    {
        $validated = $request->validate([
            'student_name' => 'required|string|max:255',
            'student_photo' => 'nullable|string|max:500',
            'student_role_or_company' => 'nullable|string|max:255',
            'course_id' => 'nullable|exists:courses,id',
            'course_title' => 'nullable|string|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'content' => 'required|string',
            'display_order' => 'nullable|integer',
            'is_featured' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
        ]);

        $old = $testimonial->toArray();
        $testimonial->update($validated);
        AuditLog::log('updated_testimonial', $testimonial, $old, $testimonial->toArray());

        return response()->json([
            'message' => "Testimonial by '{$testimonial->student_name}' updated successfully.",
            'testimonial' => $testimonial,
        ]);
    }

    public function destroyTestimonial(Testimonial $testimonial)
    {
        $old = $testimonial->toArray();
        $name = $testimonial->student_name;
        $testimonial->delete();
        AuditLog::log('deleted_testimonial', null, $old, null);

        return response()->json(['message' => "Testimonial by '{$name}' deleted successfully."]);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. FAQS
    |--------------------------------------------------------------------------
    */
    public function faqs()
    {
        $faqs = Faq::orderBy('display_order', 'asc')->get();
        return response()->json($faqs);
    }

    public function storeFaq(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:100',
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'display_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ]);

        $faq = Faq::create($validated);
        AuditLog::log('created_faq', $faq, null, $faq->toArray());

        return response()->json([
            'message' => 'FAQ created successfully.',
            'faq' => $faq,
        ], 201);
    }

    public function updateFaq(Request $request, Faq $faq)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:100',
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'display_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ]);

        $old = $faq->toArray();
        $faq->update($validated);
        AuditLog::log('updated_faq', $faq, $old, $faq->toArray());

        return response()->json([
            'message' => 'FAQ updated successfully.',
            'faq' => $faq,
        ]);
    }

    public function destroyFaq(Faq $faq)
    {
        $old = $faq->toArray();
        $faq->delete();
        AuditLog::log('deleted_faq', null, $old, null);

        return response()->json(['message' => 'FAQ deleted successfully.']);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. RESOURCES
    |--------------------------------------------------------------------------
    */
    public function resources()
    {
        $resources = Resource::orderBy('display_order', 'asc')->get();
        return response()->json($resources);
    }

    public function storeResource(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:resources,slug',
            'description' => 'nullable|string',
            'type' => 'required|string|max:50',
            'tag' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'url_or_file' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'display_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ]);

        $res = Resource::create($validated);
        AuditLog::log('created_resource', $res, null, $res->toArray());

        return response()->json([
            'message' => "Resource '{$res->title}' created successfully.",
            'resource' => $res,
        ], 201);
    }

    public function updateResource(Request $request, Resource $resource)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => "nullable|string|max:255|unique:resources,slug,{$resource->id}",
            'description' => 'nullable|string',
            'type' => 'required|string|max:50',
            'tag' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'url_or_file' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'display_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ]);

        $old = $resource->toArray();
        $resource->update($validated);
        AuditLog::log('updated_resource', $resource, $old, $resource->toArray());

        return response()->json([
            'message' => "Resource '{$resource->title}' updated successfully.",
            'resource' => $resource,
        ]);
    }

    public function destroyResource(Resource $resource)
    {
        $old = $resource->toArray();
        $title = $resource->title;
        $resource->delete();
        AuditLog::log('deleted_resource', null, $old, null);

        return response()->json(['message' => "Resource '{$title}' deleted successfully."]);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. WEBSITE SETTINGS
    |--------------------------------------------------------------------------
    */
    public function settings()
    {
        $settings = WebsiteSetting::all();
        return response()->json($settings);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
            'settings.*.group' => 'nullable|string',
        ]);

        $oldValues = WebsiteSetting::pluck('value', 'key')->toArray();

        foreach ($validated['settings'] as $item) {
            WebsiteSetting::set($item['key'], $item['value'] ?? null, $item['group'] ?? 'general');
        }

        $newValues = WebsiteSetting::pluck('value', 'key')->toArray();
        AuditLog::log('updated_website_settings', null, $oldValues, $newValues);

        return response()->json([
            'message' => 'Website settings updated successfully.',
            'settings' => WebsiteSetting::all(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. NAVIGATION ITEMS
    |--------------------------------------------------------------------------
    */
    public function navigation()
    {
        $items = NavigationItem::with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json($items);
    }

    public function storeNavigation(Request $request)
    {
        $validated = $request->validate([
            'location' => 'required|string|max:50',
            'label' => 'required|string|max:100',
            'url' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'target' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'parent_id' => 'nullable|exists:navigation_items,id',
        ]);

        $item = NavigationItem::create($validated);
        AuditLog::log('created_navigation', $item, null, $item->toArray());

        return response()->json([
            'message' => "Navigation item '{$item->label}' created successfully.",
            'item' => $item,
        ], 201);
    }

    public function updateNavigation(Request $request, NavigationItem $navigation)
    {
        $validated = $request->validate([
            'location' => 'required|string|max:50',
            'label' => 'required|string|max:100',
            'url' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'target' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'parent_id' => 'nullable|exists:navigation_items,id',
        ]);

        $old = $navigation->toArray();
        $navigation->update($validated);
        AuditLog::log('updated_navigation', $navigation, $old, $navigation->toArray());

        return response()->json([
            'message' => "Navigation item '{$navigation->label}' updated successfully.",
            'item' => $navigation,
        ]);
    }

    public function destroyNavigation(NavigationItem $navigation)
    {
        $old = $navigation->toArray();
        $label = $navigation->label;
        $navigation->delete();
        AuditLog::log('deleted_navigation', null, $old, null);

        return response()->json(['message' => "Navigation item '{$label}' deleted successfully."]);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. MEDIA ASSETS
    |--------------------------------------------------------------------------
    */
    public function media(Request $request)
    {
        $query = MediaAsset::with('uploader:id,name');

        if ($request->filled('folder') && $request->folder !== 'all') {
            $query->where('folder', $request->folder);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('file_name', 'like', "%{$term}%")
                    ->orWhere('original_name', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhere('alt_text', 'like', "%{$term}%");
            });
        }

        $perPage = $request->input('per_page', 36);
        $assets = $query->orderBy('created_at', 'desc')->paginate($perPage);
        return response()->json($assets);
    }

    public function uploadMedia(Request $request)
    {
        // NOTE: svg is deliberately excluded — uploaded SVGs are served from
        // public storage and would execute embedded scripts (stored XSS).
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,pdf,mp4|max:51200',
            'title' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:255',
            'folder' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $folder = $request->input('folder', 'general');
        $safeName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("uploads/media/{$folder}", $safeName, 'public');
        $url = Storage::disk('public')->url($path);

        $media = MediaAsset::create([
            'file_name' => $safeName,
            'original_name' => $file->getClientOriginalName(),
            'title' => $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'file_path' => $path,
            'disk' => 'public',
            'folder' => $folder,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'url' => $url,
            'alt_text' => $request->input('alt_text'),
            'uploaded_by_user_id' => auth()->id(),
        ]);

        AuditLog::log('uploaded_media', $media, null, $media->toArray());

        return response()->json([
            'message' => 'Media file uploaded successfully.',
            'media' => $media,
        ], 201);
    }

    public function updateMedia(Request $request, MediaAsset $media)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:255',
            'folder' => 'nullable|string|max:50',
        ]);

        $old = $media->toArray();
        $media->update($validated);
        AuditLog::log('updated_media', $media, $old, $media->toArray());

        return response()->json([
            'message' => 'Media metadata updated successfully.',
            'media' => $media,
        ]);
    }

    public function replaceMedia(Request $request, MediaAsset $media)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,pdf,mp4|max:51200',
        ]);

        $file = $request->file('file');
        $folder = $media->folder ?: 'general';

        if (Storage::disk($media->disk)->exists($media->file_path)) {
            Storage::disk($media->disk)->delete($media->file_path);
        }

        $safeName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("uploads/media/{$folder}", $safeName, 'public');
        $url = Storage::disk('public')->url($path);

        $old = $media->toArray();
        $media->update([
            'file_name' => $safeName,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'url' => $url,
        ]);

        AuditLog::log('replaced_media', $media, $old, $media->toArray());

        return response()->json([
            'message' => 'Media file replaced successfully.',
            'media' => $media,
        ]);
    }

    public function destroyMedia(MediaAsset $media)
    {
        if (Storage::disk($media->disk)->exists($media->file_path)) {
            Storage::disk($media->disk)->delete($media->file_path);
        }

        $old = $media->toArray();
        $media->delete();
        AuditLog::log('deleted_media', null, $old, null);

        return response()->json(['message' => 'Media asset deleted successfully.']);
    }

    /*
    |--------------------------------------------------------------------------
    | 11. AUDIT LOGS
    |--------------------------------------------------------------------------
    */
    public function auditLogs(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = AuditLog::with('user:id,name,email');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('user')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->user . '%')
                    ->orWhere('email', 'like', '%' . $request->user . '%');
            });
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->auditable_type);
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($request->from)->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($request->to)->endOfDay());
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(50);
        return response()->json($logs);
    }
}
