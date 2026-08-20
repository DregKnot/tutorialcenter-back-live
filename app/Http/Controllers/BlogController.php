<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;


class BlogController extends Controller
{
    /**
     * Public & Staff Blog Listing with Filtering, Search, and Pagination
     * 
     * [HTTP]: GET /api/blogs (Public) OR GET /api/staffs/blogs (Staff Protected)
     * 
     * [Model Connection]:
     *  - Interacts with `Blog` model and eager-loads `category` and `author`.
     *  - `with(['category:...', 'author:...'])` avoids the N+1 database query problem.
     * 
     * [Migration Connection]:
     *  - Queries `blogs` table, filtering by `status`, `is_featured`, `published_at`,
     *    and joins `blog_categories` via `blog_category_id` foreign key.
     */
    public function index(Request $request)
    {
        // Step 1: Initialize Eloquent query with eager loading for relationships
        $query = Blog::with(['category:id,name,slug', 'author:id,firstname,surname,role']);

        // Step 2: Role-based filtering:
        // If the requester is not a staff member and not in preview mode, restrict to 'published'
        $isStaff = $request->user('staff') !== null;
        if (!$isStaff && !$request->has('staff_preview')) {
            $query->where('status', 'published');
        } elseif ($request->filled('status') && $request->status !== 'all') {
            // Staff can filter by specific status (draft, scheduled, published, archived)
            $query->where('status', $request->status);
        }

        // Step 3: Category Filter (Matches either category slug or name via relationship)
        if ($request->filled('category')) {
            $category = $request->category;
            $query->whereHas('category', function ($q) use ($category) {
                $q->where('slug', $category)->orWhere('name', $category);
            });
        }

        // Step 4: Full-text Search Filter on title, excerpt, or content
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Step 5: Featured Posts filter (maps to boolean `is_featured` column in `blogs` table)
        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        // Step 6: Paginate results sorted by published date & creation date
        $perPage = (int) $request->input('per_page', 12);
        $blogs = $query->latest('published_at')->latest('created_at')->paginate($perPage);

        return response()->json($blogs);
    }

    /**
     * Public / Student Single Blog Post by Slug or ID
     * 
     * [HTTP]: GET /api/blogs/{slug}
     * 
     * [Model Connection]:
     *  - Queries `Blog` model with eager loaded `category`, `author`, and approved `comments`.
     *  - Executes `$blog->increment('views')` to update the `views` column directly in database.
     * 
     * [Migration Connection]:
     *  - Reads from `blogs` table where `slug = $slugOrId` OR `id = $slugOrId`.
     *  - Subqueries `blog_comments` where `blog_id = blogs.id` AND `status = 'approved'`.
     */
    public function show(string $slugOrId)
    {
        // Step 1: Find post with its category, author, and up to 50 approved comments
        $blog = Blog::with([
            'category:id,name,slug',
            'author:id,firstname,surname,role',
            'comments' => function ($q) {
                $q->where('status', 'approved')->latest()->take(50);
            }
        ])
        ->where('slug', $slugOrId)
        ->orWhere('id', $slugOrId)
        ->firstOrFail();

        // Step 2: Increment view counter in `blogs` table
        $blog->increment('views');

        // Step 3: Fetch 3 related published articles from the same category
        $related = Blog::with(['category:id,name,slug', 'author:id,firstname,surname,role'])
            ->where('status', 'published')
            ->where('id', '!=', $blog->id)
            ->where('blog_category_id', $blog->blog_category_id)
            ->latest('published_at')
            ->take(3)
            ->get();

        return response()->json([
            'data' => $blog,
            'related' => $related,
        ]);
    }

    /**
     * Create Blog Post (Staff / Admin Protected)
     * 
     * [HTTP]: POST /api/staffs/blogs
     * 
     * [Model Connection]:
     *  - `BlogCategory::firstOrCreate(...)` ensures category exists or creates it on the fly.
     *  - `Blog::create($validated)` fills the model and persists a new record in `blogs` table.
     * 
     * [Migration Connection]:
     *  - Maps inputs to `blogs` table columns (`title`, `slug`, `content`, `author_id`, `status`, etc.).
     *  - Foreign key constraint: `author_id` references `staffs.id`.
     */
    public function store(Request $request)
    {
        // Step 1: Validate payload against database schema requirements
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'blog_category_id' => 'nullable|exists:blog_categories,id',
            'category_name' => 'nullable|string|max:100',
            'content' => 'required|string',
            'excerpt' => 'nullable|string|max:500',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'status' => 'required|in:draft,published,scheduled,archived',
            'is_featured' => 'nullable|boolean',
            'allow_comments' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Step 2: Category Resolution:
        // Use provided category ID or auto-create a category row in `blog_categories` table
        if (empty($validated['blog_category_id']) && !empty($validated['category_name'])) {
            $cat = BlogCategory::firstOrCreate(
                ['name' => $validated['category_name']],
                ['slug' => Str::slug($validated['category_name'])]
            );
            $validated['blog_category_id'] = $cat->id;
        } elseif (empty($validated['blog_category_id'])) {
            $defaultCat = BlogCategory::firstOrCreate(
                ['name' => 'General'],
                ['slug' => 'general']
            );
            $validated['blog_category_id'] = $defaultCat->id;
        }

        // Step 3: Handle Featured Image upload to storage disk
        if ($request->hasFile('featured_image')) {
            $path = $request->file('featured_image')->store('blogs', 'public');
            $validated['featured_image'] = Storage::url($path);
        }

        // Step 4: Calculate approximate reading time (200 words/min)
        $wordCount = str_word_count(strip_tags($validated['content']));
        $validated['reading_time'] = max(1, (int) ceil($wordCount / 200));

        // Step 5: Generate a collision-resistant unique slug
        $baseSlug = Str::slug($validated['title']);
        $validated['slug'] = $baseSlug . '-' . Str::lower(Str::random(5));

        // Step 6: Link Author (Foreign key `author_id` -> `staffs.id`)
        $staffUser = $request->user('staff') ?? auth('staff')->user() ?? auth()->user();
        $staffId = $staffUser ? $staffUser->id : ($request->input('author_id') ?? (\App\Models\Staff::first()->id ?? 1));
        $validated['author_id'] = $staffId;

        // Step 7: Set published_at timestamp if immediately published
        if ($validated['status'] === 'published') {
            $validated['published_at'] = now();
        }

        // Step 8: Persist to `blogs` table via Eloquent `Blog::create`
        $blog = Blog::create($validated);

        return response()->json([
            'message' => 'Blog post created successfully.',
            'data' => $blog->load('category', 'author'),
        ], 201);
    }

    /**
     * Update Blog Post (Staff / Admin Protected)
     * 
     * [HTTP]: POST /api/staffs/blogs/{id}
     * 
     * [Model Connection]:
     *  - `Blog::findOrFail($id)` retrieves the instance or returns a 404 response.
     *  - `$blog->update($validated)` updates only the dirty/changed fields in the model.
     * 
     * [Migration Connection]:
     *  - Executes SQL `UPDATE blogs SET ... WHERE id = ?`.
     */
    public function update(Request $request, $id)
    {
        $blog = Blog::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'blog_category_id' => 'nullable|exists:blog_categories,id',
            'category_name' => 'nullable|string|max:100',
            'content' => 'sometimes|required|string',
            'excerpt' => 'nullable|string|max:500',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'status' => 'sometimes|required|in:draft,published,scheduled,archived',
            'is_featured' => 'nullable|boolean',
            'allow_comments' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Handle category update if a new category name was provided
        if (!empty($validated['category_name']) && empty($validated['blog_category_id'])) {
            $cat = BlogCategory::firstOrCreate(
                ['name' => $validated['category_name']],
                ['slug' => Str::slug($validated['category_name'])]
            );
            $validated['blog_category_id'] = $cat->id;
        }

        // Upload new featured image if a new file is attached
        if ($request->hasFile('featured_image')) {
            $path = $request->file('featured_image')->store('blogs', 'public');
            $validated['featured_image'] = Storage::url($path);
        }

        // Recalculate reading time if content changed
        if (!empty($validated['content'])) {
            $wordCount = str_word_count(strip_tags($validated['content']));
            $validated['reading_time'] = max(1, (int) ceil($wordCount / 200));
        }

        // Manage published_at timestamp transition
        if (isset($validated['status']) && $validated['status'] === 'published' && !$blog->published_at) {
            $validated['published_at'] = now();
        }

        // Update model and persist changes
        $blog->update($validated);

        return response()->json([
            'message' => 'Blog post updated successfully.',
            'data' => $blog->fresh(['category', 'author']),
        ]);
    }

    /**
     * Delete Blog Post (Soft Delete)
     * 
     * [HTTP]: DELETE /api/staffs/blogs/{id}
     * 
     * [Model Connection]:
     *  - Because `Blog` model imports `use SoftDeletes;`, calling `$blog->delete()`
     *    triggers Eloquent's soft delete mechanism.
     * 
     * [Migration Connection]:
     *  - The `2026_08_06_161452_create_blogs_table.php` defines `$table->softDeletes();`
     *  - Executes SQL: `UPDATE blogs SET deleted_at = NOW() WHERE id = ?`.
     */
    public function destroy($id)
    {
        $blog = Blog::findOrFail($id);
        $blog->delete(); // Soft deletes the post

        return response()->json([
            'message' => 'Blog post deleted successfully.',
        ]);
    }

    /**
     * Fetch all active categories with published blog count
     * 
     * [HTTP]: GET /api/blogs/categories (Public) OR GET /api/staffs/blog-categories
     * 
     * [Model Connection]:
     *  - `BlogCategory::withCount(['blogs' => ...])` utilizes Eloquent's relationship counter.
     *  - Calls the `blogs()` hasMany relationship on `BlogCategory.php`.
     * 
     * [Migration Connection]:
     *  - Queries `blog_categories` table and performs `(SELECT COUNT(*) FROM blogs WHERE blog_categories.id = blogs.blog_category_id AND blogs.status = 'published') AS blogs_count`.
     */
    public function categories()
    {
        $categories = BlogCategory::withCount(['blogs' => function ($q) {
            $q->where('status', 'published');
        }])->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    /**
     * Store Comment on a Blog Post (Students, Staff, or Public Guests)
     * 
     * [HTTP]: POST /api/blogs/{id}/comments
     * 
     * [Model Connection]:
     *  - `BlogComment` model has a polymorphic relation: `public function commenter() { return $this->morphTo(); }`.
     *  - If student is logged in, `$student->id` and `get_class($student)` (`App\Models\Student`) are saved.
     *  - If guest, `guest_name` and `guest_email` are stored directly.
     * 
     * [Migration Connection]:
     *  - `2026_08_06_162147_create_blog_comments_table.php` defines:
     *      - `$table->foreignId('blog_id')->constrained()->cascadeOnDelete();`
     *      - `$table->nullableMorphs('commenter');` (creates `commenter_type` and `commenter_id`)
     *      - `$table->string('guest_name')->nullable();`
     *      - `$table->longText('comment');`
     */
    public function storeComment(Request $request, $blogId)
    {
        $blog = Blog::findOrFail($blogId);

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:2000',
            'guest_name' => 'nullable|string|max:100',
            'guest_email' => 'nullable|email|max:150',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if an authenticated student is submitting the comment
        $student = $request->user('student');

        $commentData = [
            'blog_id' => $blog->id,
            'comment' => $request->comment,
            'status' => 'approved', // Default to approved (or 'pending' for moderation)
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        // Polymorphic relation assignment vs Guest commenter
        if ($student) {
            $commentData['commenter_id'] = $student->id;
            $commentData['commenter_type'] = get_class($student);
        } else {
            $commentData['guest_name'] = $request->input('guest_name', 'Guest Reader');
            $commentData['guest_email'] = $request->input('guest_email');
        }

        // Persist comment in `blog_comments` table
        $comment = BlogComment::create($commentData);

        return response()->json([
            'message' => 'Comment posted successfully.',
            'data' => $comment,
        ], 201);
    }
}
