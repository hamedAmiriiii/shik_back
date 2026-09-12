<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'atelier_id',
        'blog_category_id',
        'author_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'author_name',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'og_title',
        'og_description',
        'og_image',
        'canonical_url',
        'is_published',
        'is_featured',
        'allow_comments',
        'published_at',
        'views_count',
        'reading_time',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'published_at' => 'datetime',
        'views_count' => 'integer',
        'reading_time' => 'integer',
    ];

    protected $appends = [
        'featured_image_url',
        'og_image_url',
        'seo_title',
        'seo_description',
    ];

    public function getFeaturedImageUrlAttribute(): ?string
    {
        if (empty($this->attributes['featured_image'])) {
            return null;
        }

        return Storage::url($this->attributes['featured_image']);
    }

    public function getOgImageUrlAttribute(): ?string
    {
        $path = $this->attributes['og_image'] ?? null;
        if (empty($path)) {
            return $this->featured_image_url;
        }

        return Storage::url($path);
    }

    public function getSeoTitleAttribute(): string
    {
        return $this->meta_title ?: $this->title;
    }

    public function getSeoDescriptionAttribute(): string
    {
        if (! empty($this->meta_description)) {
            return $this->meta_description;
        }
        if (! empty($this->excerpt)) {
            return $this->excerpt;
        }

        return Str::limit(strip_tags((string) $this->content), 160);
    }

    public function category()
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments()
    {
        return $this->hasMany(BlogComment::class, 'blog_post_id');
    }

    public function approvedComments()
    {
        return $this->comments()->where('status', 'approved')->whereNull('parent_id')->with('replies');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function estimateReadingTime(): int
    {
        $words = str_word_count(strip_tags((string) $this->content));
        return max(1, (int) ceil($words / 200));
    }

    public function toSeoArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'featured_image_url' => $this->featured_image_url,
            'author_name' => $this->author_name,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'reading_time' => $this->reading_time,
            'views_count' => $this->views_count,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,
            'seo' => [
                'title' => $this->seo_title,
                'description' => $this->seo_description,
                'keywords' => $this->meta_keywords,
                'canonical_url' => $this->canonical_url,
                'og_title' => $this->og_title ?: $this->seo_title,
                'og_description' => $this->og_description ?: $this->seo_description,
                'og_image' => $this->og_image_url,
            ],
        ];
    }
}
