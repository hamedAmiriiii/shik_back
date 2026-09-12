<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Tools\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BlogPostController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = BlogPost::query()
            ->where('atelier_id', $atelierId)
            ->with(['category:id,name,slug']);

        $isStaff = $this->staffShopAtelierId($request) !== null;

        if (! $isStaff || $request->boolean('public_only')) {
            $query->published();
        } elseif ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        if ($request->filled('category_id')) {
            $query->where('blog_category_id', $request->input('category_id'));
        }

        if ($request->filled('category')) {
            $cat = BlogCategory::where('atelier_id', $atelierId)
                ->where(function ($q) use ($request) {
                    $q->where('slug', $request->input('category'))
                        ->orWhere('id', $request->input('category'));
                })
                ->first();
            if ($cat) {
                $query->where('blog_category_id', $cat->id);
            }
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%")
                    ->orWhere('excerpt', 'like', "%{$q}%")
                    ->orWhere('meta_keywords', 'like', "%{$q}%");
            });
        }

        $query->orderByDesc('published_at')->orderByDesc('id');

        $perPage = min(50, max(1, (int) $request->input('per_page', 12)));

        if ($request->boolean('paginate', true)) {
            $page = $query->paginate($perPage);
            $page->getCollection()->transform(function (BlogPost $post) {
                return $post->toSeoArray() + [
                    'is_published' => $post->is_published,
                    'is_featured' => $post->is_featured,
                    'allow_comments' => $post->allow_comments,
                    'blog_category_id' => $post->blog_category_id,
                ];
            });

            return response($page);
        }

        $posts = $query->limit($perPage)->get()->map(function (BlogPost $post) {
            return $post->toSeoArray() + [
                'is_published' => $post->is_published,
                'is_featured' => $post->is_featured,
                'allow_comments' => $post->allow_comments,
                'blog_category_id' => $post->blog_category_id,
            ];
        });

        return response($posts);
    }

    public function featured(Request $request)
    {
        $request->merge(['featured' => true, 'public_only' => true, 'paginate' => false, 'per_page' => $request->input('limit', 6)]);

        return $this->index($request);
    }

    public function show(Request $request, $blogPost)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $isStaff = $this->staffShopAtelierId($request) !== null;
        $post = $this->findPost($blogPost, $atelierId, ! $isStaff || $request->boolean('public_only'));

        if ($request->boolean('increment_views', true) && (! $isStaff || $request->boolean('public_only'))) {
            $post->increment('views_count');
            $post->refresh();
        }

        $post->load(['category', 'approvedComments']);

        $data = $post->toSeoArray() + [
            'content' => $post->content,
            'is_published' => $post->is_published,
            'is_featured' => $post->is_featured,
            'allow_comments' => $post->allow_comments,
            'blog_category_id' => $post->blog_category_id,
            'meta_title' => $post->meta_title,
            'meta_description' => $post->meta_description,
            'meta_keywords' => $post->meta_keywords,
            'og_title' => $post->og_title,
            'og_description' => $post->og_description,
            'canonical_url' => $post->canonical_url,
            'comments' => $post->approvedComments,
            'comments_count' => BlogComment::where('blog_post_id', $post->id)->where('status', 'approved')->count(),
        ];

        return response($data);
    }

    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $user = $this->requireStaffShopUser($request);
        if ($atelierId === null) {
            abort(response(['message' => 'حساب شما به فروشگاه متصل نیست.'], 422));
        }
        $fields = $this->validated($request, $atelierId);

        if (! empty($fields['blog_category_id'])) {
            $this->assertCategoryBelongs($fields['blog_category_id'], $atelierId);
        }

        $fields['atelier_id'] = $atelierId;
        $fields['author_id'] = $user->id;
        $fields['slug'] = $this->uniqueSlug($fields['slug'] ?? $fields['title'], $atelierId);
        $fields['featured_image'] = $this->storeImage($request->input('featured_image'), $atelierId, 'posts');
        $fields['og_image'] = $this->storeImage($request->input('og_image'), $atelierId, 'og');
        $fields['reading_time'] = $fields['reading_time'] ?? (new BlogPost(['content' => $fields['content'] ?? '']))->estimateReadingTime();
        if (! empty($fields['is_published']) && empty($fields['published_at'])) {
            $fields['published_at'] = now();
        }
        if (empty($fields['author_name'])) {
            $fields['author_name'] = $user->name ?? $user->fullName ?? 'مدیر فروشگاه';
        }

        $post = BlogPost::create($fields);

        return response($post->load('category'), 201);
    }

    public function update(Request $request, $blogPost)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);
        $post = $this->findPost($blogPost, $atelierId, false);
        $fields = $this->validated($request, $atelierId, $post->id);

        if (array_key_exists('blog_category_id', $fields) && $fields['blog_category_id']) {
            $this->assertCategoryBelongs($fields['blog_category_id'], $atelierId);
        }

        if (array_key_exists('slug', $fields) || array_key_exists('title', $fields)) {
            $slugSource = $fields['slug'] ?? ($fields['title'] ?? $post->title);
            $fields['slug'] = $this->uniqueSlug($slugSource, $atelierId, $post->id);
        }

        if ($request->exists('featured_image')) {
            $fields['featured_image'] = $this->storeImage($request->input('featured_image'), $atelierId, 'posts', $post->featured_image);
        }
        if ($request->exists('og_image')) {
            $fields['og_image'] = $this->storeImage($request->input('og_image'), $atelierId, 'og', $post->og_image);
        }

        $contentForTime = $fields['content'] ?? $post->content;
        if (! array_key_exists('reading_time', $fields) || empty($fields['reading_time'])) {
            $fields['reading_time'] = (new BlogPost(['content' => $contentForTime]))->estimateReadingTime();
        }

        if (! empty($fields['is_published']) && empty($fields['published_at']) && ! $post->published_at) {
            $fields['published_at'] = now();
        }

        $post->update($fields);

        return response($post->fresh()->load('category'));
    }

    public function destroy(Request $request, $blogPost)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);
        $post = $this->findPost($blogPost, $atelierId, false);
        $post->comments()->delete();
        $post->delete();

        return response(['message' => 'پست حذف شد.']);
    }

    private function validated(Request $request, ?int $atelierId, ?int $ignoreId = null): array
    {
        $slugUnique = Rule::unique('blog_posts', 'slug');
        if ($atelierId !== null) {
            $slugUnique = $slugUnique->where('atelier_id', $atelierId);
        } else {
            $slugUnique = $slugUnique->whereNull('atelier_id');
        }
        if ($ignoreId) {
            $slugUnique = $slugUnique->ignore($ignoreId);
        }

        return $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $slugUnique],
            'excerpt' => 'nullable|string|max:1000',
            'content' => 'nullable|string',
            'blog_category_id' => 'nullable|integer|exists:blog_categories,id',
            'author_name' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
            'og_title' => 'nullable|string|max:255',
            'og_description' => 'nullable|string|max:500',
            'canonical_url' => 'nullable|string|max:500',
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'allow_comments' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'reading_time' => 'nullable|integer|min:1|max:600',
            'featured_image' => 'nullable|string',
            'og_image' => 'nullable|string',
        ]);
    }

    private function findPost($blogPost, int $atelierId, bool $publishedOnly = false): BlogPost
    {
        $query = BlogPost::where('atelier_id', $atelierId);
        if ($publishedOnly) {
            $query->published();
        }
        if (is_numeric($blogPost)) {
            return $query->where('id', $blogPost)->firstOrFail();
        }

        return $query->where('slug', $blogPost)->firstOrFail();
    }

    private function assertCategoryBelongs(int $categoryId, ?int $atelierId): void
    {
        $exists = BlogCategory::where('id', $categoryId)
            ->where('atelier_id', $atelierId)
            ->exists();
        if (! $exists) {
            abort(response(['message' => 'دسته‌بندی معتبر نیست.', 'hasError' => true], 422));
        }
    }

    private function uniqueSlug(string $source, ?int $atelierId, ?int $ignoreId = null): string
    {
        $base = Str::slug($source);
        if ($base === '' || $base === '-') {
            $base = preg_replace('/\s+/u', '-', trim($source));
            $base = trim($base, '-') ?: 'post-'.Str::random(6);
        }

        $slug = $base;
        $i = 1;
        while ($this->slugExists($slug, $atelierId, $ignoreId)) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $atelierId, ?int $ignoreId = null): bool
    {
        $q = BlogPost::where('slug', $slug);
        if ($atelierId !== null) {
            $q->where('atelier_id', $atelierId);
        } else {
            $q->whereNull('atelier_id');
        }
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }

        return $q->exists();
    }

    private function storeImage(?string $imageData, ?int $atelierId, string $folder, ?string $fallback = null): ?string
    {
        if ($imageData === null || $imageData === '') {
            return $fallback;
        }
        if (! Str::contains($imageData, 'base64') && ! preg_match('/^[A-Za-z0-9+\/=]{100,}$/', $imageData)) {
            return $imageData;
        }

        $raw = $imageData;
        if (strpos($imageData, ',') !== false) {
            $parts = explode(',', $imageData);
            $raw = $parts[1];
        }
        $content = base64_decode($raw, true);
        if ($content === false) {
            return $fallback;
        }

        $shop = $atelierId ?: 'global';

        return ImageTools::saveFile("/blog/{$folder}/{$shop}/".time().'.jpeg', $content);
    }
}
