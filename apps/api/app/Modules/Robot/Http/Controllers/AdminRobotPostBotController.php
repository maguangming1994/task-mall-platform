<?php

namespace App\Modules\Robot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Robot\Models\RobotPostBot;
use App\Modules\Robot\Models\RobotPostTemplate;
use App\Modules\Robot\Services\RobotPostBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminRobotPostBotController extends Controller
{
    public function __construct(private readonly RobotPostBotService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['keyword' => ['nullable', 'string', 'max:100']]);
        $query = RobotPostBot::query()->orderByDesc('id');
        if (! empty($data['keyword'])) {
            $query->where('name', 'like', '%'.$data['keyword'].'%');
        }

        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => [
            'items' => $query->get()->map(fn (RobotPostBot $bot): array => $this->service->present($bot)),
        ]]);
    }

    public function show(RobotPostBot $bot): JsonResponse
    {
        return $this->showBot($bot);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $bot = RobotPostBot::query()->create($payload + [
            'created_by_admin_id' => $request->user()->id,
            'updated_by_admin_id' => $request->user()->id,
        ]);

        return $this->showBot($bot);
    }

    public function update(Request $request, RobotPostBot $bot): JsonResponse
    {
        $bot->update($this->validatePayload($request) + ['updated_by_admin_id' => $request->user()->id]);

        return $this->showBot($bot->fresh());
    }

    public function status(Request $request, RobotPostBot $bot): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $bot->update(['enabled' => $data['enabled'], 'updated_by_admin_id' => $request->user()->id]);

        return $this->showBot($bot->fresh());
    }

    public function destroy(RobotPostBot $bot): JsonResponse
    {
        $bot->delete();

        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => ['deleted' => true]]);
    }

    public function publishNow(Request $request, RobotPostBot $bot): JsonResponse
    {
        $post = $this->service->publishNow($bot, $request->user());

        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => [
            'post_id' => $post->public_id,
            'title' => $post->title,
            'status' => $post->status,
        ]]);
    }

    public function templates(RobotPostBot $bot): JsonResponse
    {
        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => [
            'items' => $bot->templates()->orderBy('sort')->orderBy('id')->get()->map(fn (RobotPostTemplate $template): array => [
                'id' => $template->public_id,
                'title' => $template->title,
                'content' => $template->content,
                'enabled' => $template->enabled,
                'sort' => $template->sort,
                'used_at' => $template->used_at?->toIso8601String(),
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function storeTemplate(Request $request, RobotPostBot $bot): JsonResponse
    {
        $data = $this->validateTemplate($request);
        $template = $bot->templates()->create($data + ['sort' => $data['sort'] ?? 0]);

        return $this->template($template);
    }

    public function updateTemplate(Request $request, RobotPostBot $bot, RobotPostTemplate $template): JsonResponse
    {
        abort_unless($template->robot_post_bot_id === $bot->id, 404);
        $template->update($this->validateTemplate($request));

        return $this->template($template->fresh());
    }

    public function destroyTemplate(RobotPostBot $bot, RobotPostTemplate $template): JsonResponse
    {
        abort_unless($template->robot_post_bot_id === $bot->id, 404);
        $template->delete();

        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => ['deleted' => true]]);
    }

    private function showBot(RobotPostBot $bot): JsonResponse
    {
        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => $this->service->present($bot->fresh())]);
    }

    private function template(RobotPostTemplate $template): JsonResponse
    {
        return response()->json(['code' => 'OK', 'message' => 'success', 'data' => [
            'id' => $template->public_id,
            'title' => $template->title,
            'content' => $template->content,
            'enabled' => $template->enabled,
            'sort' => $template->sort,
            'used_at' => $template->used_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'campus_id' => ['required', 'string', 'max:64'],
            'category_code' => ['required', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'content_source' => ['required', Rule::in(['pool', 'history', 'ai'])],
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:10000'],
            'posts_per_run' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'active_weekdays' => ['sometimes', 'array', 'max:7'],
            'active_weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'active_start' => ['required', 'date_format:H:i'],
            'active_end' => ['required', 'date_format:H:i'],
            'min_gap_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'history_min_likes' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'ai_prompt' => ['nullable', 'string', 'max:1000'],
        ]);
        $campus = Campus::query()->where('public_id', $data['campus_id'])->where('status', 'active')->first();
        if ($campus === null) {
            throw ValidationException::withMessages(['campus_id' => '所选学校无效或已停用。']);
        }
        $data['campus_id'] = $campus->id;
        $data['posts_per_run'] = $data['posts_per_run'] ?? 1;
        $data['active_weekdays'] = $data['active_weekdays'] ?? [1, 2, 3, 4, 5, 6, 7];
        $data['history_min_likes'] = $data['history_min_likes'] ?? 0;
        $data['enabled'] = $data['enabled'] ?? false;

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:100'],
            'content' => ['required', 'string', 'min:10', 'max:5000'],
            'enabled' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
