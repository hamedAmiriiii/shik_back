<?php

namespace App\Models;

use App\Tools\ImageTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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

    public function storeCover(string $dataUrl): void
    {
        $raw = $dataUrl;
        if (strpos($raw, ',') !== false) {
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $content = base64_decode($raw, true);
        if ($content === false || $content === '') {
            throw new RuntimeException('عکس خبر را ارسال کنید.');
        }
        if (strlen($content) > 5 * 1024 * 1024) {
            throw new RuntimeException('حجم عکس نباید بیشتر از ۵ مگابایت باشد.');
        }
        $info = @getimagesizefromstring($content);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            throw new RuntimeException('فایل ارسالی تصویر معتبر نیست.');
        }

        $oldPath = $this->cover_path;
        $path = ImageTools::saveFile("/billiard-news/{$this->id}/cover_".time().'.'.$ext, $content);
        $this->forceFill(['cover_path' => $path])->save();

        if ($oldPath && $oldPath !== $path && Storage::exists('public/'.$oldPath)) {
            Storage::delete('public/'.$oldPath);
        }
    }

    public function clearCover(): void
    {
        $oldPath = $this->cover_path;
        $this->forceFill(['cover_path' => null])->save();
        if ($oldPath && Storage::exists('public/'.$oldPath)) {
            Storage::delete('public/'.$oldPath);
        }
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
