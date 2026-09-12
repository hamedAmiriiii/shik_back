<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use Illuminate\Http\Request;

class BlogCommentController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->requireStaffShopUser($request);

        $query = BlogComment::query()
            ->where('atelier_id', $atelierId)
            ->with(['post:id,title,slug']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('blog_post_id')) {
            $query->where('blog_post_id', $request->input('blog_post_id'));
        }

        $comments = $query->orderByDesc('id')->paginate(min(50, max(1, (int) $request->input('per_page', 20))));

        return response($comments);
    }

    public function store(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $fields = $request->validate([
            'blog_post_id' => 'required|integer|exists:blog_posts,id',
            'parent_id' => 'nullable|integer|exists:blog_comments,id',
            'author_name' => 'required|string|max:120',
            'author_email' => 'nullable|email|max:255',
            'body' => 'required|string|max:2000',
        ]);

        $post = BlogPost::where('atelier_id', $atelierId)
            ->where('id', $fields['blog_post_id'])
            ->published()
            ->firstOrFail();

        if (! $post->allow_comments) {
            return response(['message' => 'ثبت نظر برای این مطلب غیرفعال است.', 'hasError' => true], 422);
        }

        if (! empty($fields['parent_id'])) {
            $parent = BlogComment::where('id', $fields['parent_id'])
                ->where('blog_post_id', $post->id)
                ->where('status', 'approved')
                ->first();
            if (! $parent) {
                return response(['message' => 'نظر والد معتبر نیست.', 'hasError' => true], 422);
            }
        }

        $comment = BlogComment::create([
            'atelier_id' => $atelierId,
            'blog_post_id' => $post->id,
            'parent_id' => $fields['parent_id'] ?? null,
            'author_name' => $fields['author_name'],
            'author_email' => $fields['author_email'] ?? null,
            'body' => $fields['body'],
            'status' => 'pending',
            'ip_address' => $request->ip(),
        ]);

        return response([
            'message' => 'نظر شما ثبت شد و پس از تایید نمایش داده می‌شود.',
            'comment' => $comment,
        ], 201);
    }

    public function update(Request $request, BlogComment $blogComment)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);

        if ((int) $blogComment->atelier_id !== (int) $atelierId) {
            abort(404);
        }

        $fields = $request->validate([
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $blogComment->update($fields);

        return response($blogComment->fresh()->load('post:id,title,slug'));
    }

    public function destroy(Request $request, BlogComment $blogComment)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $this->requireStaffShopUser($request);

        if ((int) $blogComment->atelier_id !== (int) $atelierId) {
            abort(404);
        }

        $blogComment->replies()->delete();
        $blogComment->delete();

        return response(['message' => 'نظر حذف شد.']);
    }
}
