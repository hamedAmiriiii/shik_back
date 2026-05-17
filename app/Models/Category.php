<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'parent_id', 'order', 'is_active', 'atelier_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * والد این کتگوری
     */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * فرزندان این کتگوری
     */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('order');
    }

    /**
     * تمام فرزندان به صورت بازگشتی (recursive)
     */
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /**
     * محصولات این کتگوری
     */
    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * بررسی اینکه آیا کتگوری والد است یا نه
     */
    public function isParent()
    {
        return $this->children()->count() > 0;
    }

    /**
     * بررسی اینکه آیا کتگوری ریشه است یا نه
     */
    public function isRoot()
    {
        return $this->parent_id === null;
    }

    /**
     * دریافت مسیر کامل کتگوری (مثل: پدر > فرزند > نوه)
     */
    public function getFullPathAttribute()
    {
        $path = [$this->name];
        $parent = $this->parent;
        
        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }
        
        return implode(' > ', $path);
    }

    /**
     * دریافت تمام IDهای زیرمجموعه‌ها (شامل خود category)
     */
    public function getAllDescendantIds()
    {
        $ids = [$this->id];
        $this->collectDescendantIds($ids);
        return $ids;
    }

    /**
     * جمع‌آوری IDهای زیرمجموعه‌ها به صورت بازگشتی
     */
    private function collectDescendantIds(&$ids)
    {
        $children = $this->children()->get();
        foreach ($children as $child) {
            $ids[] = $child->id;
            $child->collectDescendantIds($ids);
        }
    }
}

