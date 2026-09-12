<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Tools\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BlogCategoryController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = BlogCategory::query()->where('atelier_id', $atelierId);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%");
            });
        }

        $categories = $query->withCount(['posts', 'publishedPosts'])
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response($categories);
    }

    public function show(Request $request, $blogCategory)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $category = $this->findCategory($blogCategory, $atelierId);

        return response($category->loadCount(['posts', 'publishedPosts']));
    }

    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);
        if ($atelierId === null) {
            abort(response(['message' => 'حساب شما به فروشگاه متصل نیست.'], 422));
        }

        $fields = $this->validated($request, $atelierId);
        $fields['atelier_id'] = $atelierId;
        $fields['slug'] = $this->uniqueSlug($fields['slug'] ?? $fields['name'], $atelierId);
        $fields['image'] = $this->storeImage($request->input('image'), $atelierId);

        $category = BlogCategory::create($fields);

        return response($category, 201);
    }

    public function update(Request $request, $blogCategory)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);
        $category = $this->findCategory($blogCategory, $atelierId);

        $fields = $this->validated($request, $atelierId, $category->id);
        if (array_key_exists('slug', $fields) || array_key_exists('name', $fields)) {
            $slugSource = $fields['slug'] ?? ($fields['name'] ?? $category->name);
            $fields['slug'] = $this->uniqueSlug($slugSource, $atelierId, $category->id);
        }

        if ($request->exists('image')) {
            $fields['image'] = $this->storeImage($request->input('image'), $atelierId, $category->image);
        }

        $category->update($fields);

        return response($category->fresh());
    }

    public function destroy(Request $request, $blogCategory)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);
        $category = $this->findCategory($blogCategory, $atelierId);

        if ($category->posts()->exists()) {
            return response([
                'message' => 'این دسته‌بندی دارای پست است و قابل حذف نیست.',
                'hasError' => true,
            ], 422);
        }

        $category->delete();

        return response(['message' => 'دسته‌بندی حذف شد.']);
    }

    private function validated(Request $request, ?int $atelierId, ?int $ignoreId = null): array
    {
        $slugUnique = Rule::unique('blog_categories', 'slug');
        if ($atelierId !== null) {
            $slugUnique = $slugUnique->where('atelier_id', $atelierId);
        } else {
            $slugUnique = $slugUnique->whereNull('atelier_id');
        }
        if ($ignoreId) {
            $slugUnique = $slugUnique->ignore($ignoreId);
        }

        return $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $slugUnique],
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|string',
        ]);
    }

    private function findCategory($blogCategory, int $atelierId): BlogCategory
    {
        $query = BlogCategory::where('atelier_id', $atelierId);
        if (is_numeric($blogCategory)) {
            return $query->where('id', $blogCategory)->firstOrFail();
        }

        return $query->where('slug', $blogCategory)->firstOrFail();
    }

    private function uniqueSlug(string $source, ?int $atelierId, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: '';
        // پشتیبانی از فارسی: اگر slug خالی شد از transliteration ساده استفاده کن
        if ($base === '' || $base === '-') {
            $base = preg_replace('/\s+/u', '-', trim($source));
            $base = trim($base, '-') ?: 'category-'.Str::random(6);
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
        $q = BlogCategory::where('slug', $slug);
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

    private function storeImage(?string $imageData, ?int $atelierId, ?string $fallback = null): ?string
    {
        if ($imageData === null || $imageData === '') {
            return $fallback;
        }
        if (! Str::contains($imageData, 'base64') && ! $this->looksLikeBase64($imageData)) {
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

        return ImageTools::saveFile("/blog/categories/{$shop}/".time().'.jpeg', $content);
    }

    private function looksLikeBase64(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', $value) && strlen($value) > 100;
    }
}
