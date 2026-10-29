<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BilliardNews extends Model
{
    protected $table = 'billiard_news';

    protected $fillable = [
        'title',
        'slug',
        'body',
        'cover_path',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function coverUrl(): ?string
    {
        return $this->cover_path ? url(Storage::url($this->cover_path)) : null;
    }

    public static function makeUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'news-'.Str::random(6);
        $slug = $base;
        $i = 2;
        while (static::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $detail = false): array
    {
        $data = [
            'id' => (int) $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'cover_url' => $this->coverUrl(),
            'is_published' => (bool) $this->is_published,
            'published_at' => optional($this->published_at)->toIso8601String(),
        ];
        if ($detail) {
            $data['body'] = $this->body;
        } else {
            $data['excerpt'] = mb_substr(strip_tags((string) $this->body), 0, 160);
        }

        return $data;
    }
}
