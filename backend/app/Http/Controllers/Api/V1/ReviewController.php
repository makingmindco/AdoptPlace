<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdoptionRequest;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(string $slug)
    {
        $org = Organization::where('slug', $slug)->where('verification_status', 'approved')->firstOrFail();
        $query = AdoptionRequest::where('organization_id', $org->id)->where('status', 'completed')->where('review.status', 'published');
        $count = (clone $query)->count();
        $average = (clone $query)->avg('review.rating');
        $items = $query->latest()->paginate(10)->through(fn ($item) => ['id' => (string) $item->id, 'rating' => $item->review['rating'], 'comment' => $item->review['comment'], 'created_at' => $item->review['created_at'], 'author' => 'Adotante com adoção concluída']);

        return response()->json(['reviews' => $items, 'count' => $count, 'average' => $average ? round($average, 1) : null]);
    }

    public function moderation(Request $request)
    {
        $request->validate(['status' => 'nullable|in:pending,published,hidden']);

        return AdoptionRequest::where('review.status', $request->input('status', 'pending'))->latest()->paginate(20)->through(fn ($item) => ['id' => (string) $item->id, 'review' => $item->review]);
    }

    public function moderate(Request $request, AdoptionRequest $adoptionRequest, AuditService $audit)
    {
        $data = $request->validate(['status' => 'required|in:published,hidden']);
        abort_unless($adoptionRequest->review, 404);
        $review = $adoptionRequest->review;
        $review['status'] = $data['status'];
        $adoptionRequest->update(['review' => $review]);
        $audit->record($request->user(), 'review.moderated', $adoptionRequest, $data);

        return response()->json(['message' => 'Avaliação moderada.']);
    }
}
