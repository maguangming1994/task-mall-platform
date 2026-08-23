<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Community\Models\CommunityActivity;
use App\Modules\Community\Models\CommunityActivityParticipant;
use App\Modules\Community\Services\CommunityActivityService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunityActivityController extends Controller
{
    public function categories(): JsonResponse
    {
        return ApiResponse::success(['items' => collect(CommunityActivity::CATEGORIES)->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category' => ['nullable', Rule::in(array_keys(CommunityActivity::CATEGORIES))],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = CommunityActivity::query()->where('status', 'approved')
            ->where('starts_at', '>', now())
            ->when($data['category'] ?? null, fn ($query, $category) => $query->where('category_code', $category))
            ->orderBy('starts_at')->orderBy('id');
        $total = $query->count();
        $user = $request->user();

        return ApiResponse::success([
            'items' => $query->forPage($page, $size)->get()->map(fn (CommunityActivity $activity) => $this->data($activity, $user)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Request $request, CommunityActivity $activity): JsonResponse
    {
        abort_unless($activity->status === 'approved', 404);

        return ApiResponse::success($this->data($activity, $request->user()));
    }

    public function store(Request $request, CommunityActivityService $service): JsonResponse
    {
        $data = $request->validate([
            'category_code' => ['required', Rule::in(array_keys(CommunityActivity::CATEGORIES))],
            'title' => ['required', 'string', 'min:4', 'max:80'],
            'details' => ['required', 'string', 'min:10', 'max:3000'],
            'image_file_ids' => ['nullable', 'array', 'max:9'],
            'image_file_ids.*' => ['string', 'size:26'],
            'location' => ['required', 'string', 'min:2', 'max:160'],
            'starts_at' => ['required', 'date', 'after:now'],
            'participant_limit' => ['required', 'integer', 'min:2', 'max:500'],
        ]);

        return ApiResponse::success($this->data($service->create($request->user(), $data, $this->key($request)), $request->user(), true), 201);
    }

    public function join(Request $request, CommunityActivity $activity, CommunityActivityService $service): JsonResponse
    {
        return ApiResponse::success($this->data($service->join($request->user(), $activity), $request->user()));
    }

    public function leave(Request $request, CommunityActivity $activity, CommunityActivityService $service): JsonResponse
    {
        return ApiResponse::success($this->data($service->leave($request->user(), $activity), $request->user()));
    }

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:created,joined'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $scope = $data['scope'] ?? 'joined';
        $query = CommunityActivity::query()
            ->when($scope === 'created', fn ($query) => $query->where('creator_user_id', $request->user()->id))
            ->when($scope === 'joined', fn ($query) => $query->whereHas('participants', fn ($participants) => $participants->where('user_id', $request->user()->id)->where('status', 'joined')))
            ->latest();
        $total = $query->count();

        return ApiResponse::success([
            'items' => $query->forPage($page, $size)->get()->map(fn (CommunityActivity $activity) => $this->data($activity, $request->user(), $scope === 'created')),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending_review,approved,rejected,closed']]);
        $items = CommunityActivity::query()->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest()->limit(100)->get();

        return ApiResponse::success(['items' => $items->map(fn (CommunityActivity $activity) => $this->data($activity, null, true))]);
    }

    public function review(Request $request, CommunityActivity $activity, CommunityActivityService $service): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason_code' => ['nullable', 'string', 'max:64'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::success($this->data($service->review($request->user(), $activity, $data), null, true));
    }

    public function close(Request $request, CommunityActivity $activity, CommunityActivityService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success($this->data($service->close($request->user(), $activity, $data['reason']), null, true));
    }

    public function removeParticipant(Request $request, CommunityActivity $activity, CommunityActivityParticipant $participant, CommunityActivityService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::success($this->data($service->removeParticipant($request->user(), $activity, $participant, $data['reason']), null, true));
    }

    public function media(UploadedFile $file): StreamedResponse
    {
        $published = DB::table('community_activity_images as image')
            ->join('community_activities as activity', 'activity.id', '=', 'image.community_activity_id')
            ->where('image.uploaded_file_id', $file->id)->where('activity.status', 'approved')->exists();
        abort_unless($published && $file->purpose === 'community' && $file->status === 'clean', 404);

        return $this->stream($file);
    }

    public function adminMedia(UploadedFile $file): StreamedResponse
    {
        $attached = DB::table('community_activity_images')->where('uploaded_file_id', $file->id)->exists();
        abort_unless($attached && $file->purpose === 'community' && $file->status === 'clean', 404);

        return $this->stream($file);
    }

    private function stream(UploadedFile $file): StreamedResponse
    {
        abort_unless(Storage::disk($file->disk)->exists($file->quarantine_path), 404);

        return response()->stream(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->detected_mime ?? $file->declared_mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function data(CommunityActivity $activity, ?User $viewer = null, bool $includeReview = false): array
    {
        $creator = $activity->creator()->first();
        $joined = $viewer !== null && CommunityActivityParticipant::query()
            ->where('community_activity_id', $activity->id)->where('user_id', $viewer->id)->where('status', 'joined')->exists();
        $isCreator = $viewer?->id === $activity->creator_user_id;
        $publicImages = $activity->status === 'approved';
        $images = DB::table('community_activity_images as image')
            ->join('uploaded_files as file', 'file.id', '=', 'image.uploaded_file_id')
            ->where('image.community_activity_id', $activity->id)->where('file.status', 'clean')
            ->orderBy('image.sort_order')->get(['file.public_id as file_id'])
            ->map(fn ($image) => [
                'file_id' => $image->file_id,
                'url' => $publicImages
                    ? "/community/activities/media/{$image->file_id}"
                    : ($includeReview ? "/admin/community/activities/media/{$image->file_id}" : null),
            ])->all();
        $joinedParticipants = CommunityActivityParticipant::query()
            ->with('user:id,public_id,nickname')
            ->where('community_activity_id', $activity->id)
            ->whereIn('status', $includeReview ? ['joined', 'left', 'removed'] : ['joined'])
            ->orderBy('joined_at')
            ->get();
        $participantPreview = collect([
            ['id' => $creator?->public_id, 'nickname' => $creator?->nickname ?: '校园用户', 'role' => 'creator'],
        ])->concat($joinedParticipants->where('status', 'joined')->map(fn (CommunityActivityParticipant $participant) => [
            'id' => $participant->user?->public_id,
            'nickname' => $participant->user?->nickname ?: '校园用户',
            'role' => 'participant',
        ]))->take(6)->values()->all();
        $participants = $includeReview ? collect([
            ['participant_id' => null, 'user_id' => $creator?->public_id, 'nickname' => $creator?->nickname ?: '校园用户', 'role' => 'creator', 'status' => 'joined', 'joined_at' => $activity->created_at->toIso8601String(), 'left_at' => null],
        ])->concat($joinedParticipants->map(fn (CommunityActivityParticipant $participant) => [
            'participant_id' => $participant->id,
            'user_id' => $participant->user?->public_id,
            'nickname' => $participant->user?->nickname ?: '校园用户',
            'role' => 'participant',
            'status' => $participant->status,
            'joined_at' => $participant->joined_at?->toIso8601String(),
            'left_at' => $participant->left_at?->toIso8601String(),
        ]))->values()->all() : null;

        return array_filter([
            'id' => $activity->public_id,
            'category_code' => $activity->category_code,
            'category_label' => CommunityActivity::CATEGORIES[$activity->category_code] ?? '其他',
            'title' => $activity->title,
            'details' => $activity->details,
            'images' => $images,
            'location' => $activity->location,
            'starts_at' => $activity->starts_at->toIso8601String(),
            'participant_limit' => $activity->participant_limit,
            'participant_count' => $activity->participant_count,
            'participant_preview' => $participantPreview,
            'participants' => $participants,
            'remaining_slots' => max(0, $activity->participant_limit - $activity->participant_count),
            'status' => $activity->status,
            'joined_by_me' => $joined,
            'is_creator' => $isCreator,
            'can_join' => $activity->status === 'approved' && $activity->starts_at->isFuture() && ! $isCreator && ! $joined && $activity->participant_count < $activity->participant_limit,
            'creator' => ['id' => $creator?->public_id, 'nickname' => $creator?->nickname ?: '校园用户'],
            'review_reason_code' => $includeReview ? $activity->review_reason_code : null,
            'review_note' => $includeReview ? $activity->review_note : null,
            'reviewed_at' => $includeReview ? $activity->reviewed_at?->toIso8601String() : null,
            'created_at' => $activity->created_at->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return $key;
    }
}
