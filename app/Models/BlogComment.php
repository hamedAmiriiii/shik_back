<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'atelier_id',
        'blog_post_id',
        'parent_id',
        'author_name',
        'author_email',
        'body',
        'status',
        'ip_address',
    ];

    public function post()
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id')
            ->where('status', 'approved')
            ->orderBy('id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
