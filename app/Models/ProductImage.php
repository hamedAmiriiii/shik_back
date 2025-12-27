<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'image_path', 'order'];

    protected $appends = ['image_url'];

    /**
     * محصول این عکس
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * برگرداندن URL کامل عکس
     */
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->attributes['image_path']) {
            return null;
        }
        return Storage::url($this->attributes['image_path']);
    }
}

