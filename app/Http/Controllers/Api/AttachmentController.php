<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AliOssService;
use App\Services\AttachmentService;
use App\Support\AliOssTicket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class AttachmentController extends Controller
{
    public function __construct(
        protected AliOssService $oss,
        protected AttachmentService $attachments,
    ) {}

    public function modules()
    {
        $items = [];
        foreach (config('alioss.modules', []) as $key => $cfg) {
            $ext = $cfg['extensions'] ?? [];
            $items[] = [
                'value' => $key,
                'label' => $cfg['label'] ?? $key,
                'extensions' => $ext,
                'accept' => collect($ext)->map(fn ($e) => '.'.$e)->implode(','),
                'min_size' => $cfg['min_size'] ?? 1,
                'max_size' => $cfg['max_size'] ?? 0,
                'expire' => $cfg['expire'] ?? 300,
                'kinds' => $cfg['kinds'] ?? [],
            ];
        }

        return response()->json(['data' => $items]);
    }

    /** 申请直传凭证；未配置 OSS 时返回 mode=local 让前端走服务端上传 */
    public function ticket(Request $request)
    {
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(array_keys(config('alioss.modules', [])))],
            'store' => ['nullable', 'string'],
            'md5' => ['nullable', 'string', 'size:32'],
            'member_id' => ['nullable', 'uuid'],
        ]);

        $workspaceId = $request->attributes->get('workspace_id');
        $store = $data['store'] ?? 'default';

        if (! empty($data['md5'])) {
            $existing = $this->attachments->findByMd5($workspaceId, $data['md5'], $data['module']);
            if ($existing) {
                return response()->json([
                    'data' => [
                        'deduplicated' => true,
                        'attachment' => $this->attachments->toArray($existing),
                    ],
                ]);
            }
        }

        if (! $this->oss->isConfigured($store)) {
            return response()->json([
                'data' => [
                    'deduplicated' => false,
                    'mode' => 'local',
                    'module' => $data['module'],
                    'upload_url' => url('/api/v1/attachments/upload'),
                    'message' => 'OSS 未配置，请使用本地上传接口',
                ],
            ]);
        }

        try {
            $ticket = $this->oss->signTicket($store, $data['module']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $ticket->guard = 'app';
        $ticket->userId = $request->user()->id;
        $ticket->workspaceId = $workspaceId;
        $ticket->memberId = $data['member_id'] ?? null;
        $ticket->storeInCache($ticket->expiredAt);

        return response()->json([
            'data' => array_merge(
                ['deduplicated' => false, 'mode' => 'oss'],
                $ticket->toUploadPayload()
            ),
        ]);
    }

    /** OSS 回调（免登录，由阿里云调用） */
    public function callback(Request $request)
    {
        try {
            $this->oss->verifyCallback($request);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $payload = $request->all();
        $sessionId = (string) ($payload['session_id'] ?? '');
        $ticket = AliOssTicket::fromSessionId($sessionId);
        if (! $ticket) {
            return response()->json(['message' => '上传凭证已过期'], 410);
        }

        if (! empty($payload['md5']) && $ticket->workspaceId) {
            $md5 = trim((string) $payload['md5'], '"');
            $existing = $this->attachments->findByMd5($ticket->workspaceId, $md5, $ticket->module);
            if ($existing) {
                $ticket->destroy();

                return response()->json($this->attachments->toArray($existing));
            }
        }

        $attachment = $this->attachments->createFromCallback($ticket, $payload);
        $ticket->destroy();

        return response()->json($this->attachments->toArray($attachment));
    }

    /** 本地上传兜底 / 也可在 OSS 配好后继续使用 */
    public function upload(Request $request)
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'module' => ['required', 'string', Rule::in(array_keys(config('alioss.modules', [])))],
            'member_id' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
            'bind_type' => ['nullable', Rule::in(['date', 'anniversary', 'event', 'milestone'])],
            'record_id' => ['nullable', 'uuid'],
            'anniversary_id' => ['nullable', 'string', 'max:80'],
            'milestone_key' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:80'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = config("alioss.modules.{$data['module']}.extensions", []);
        if ($allowed && $ext && ! in_array($ext, $allowed, true)) {
            return response()->json(['message' => '不支持的文件类型: '.$ext], 422);
        }

        $meta = null;
        if ($data['module'] === 'love') {
            $meta = array_filter([
                'scope' => 'love',
                'bind_type' => $data['bind_type'] ?? 'date',
                'record_id' => $data['record_id'] ?? null,
                'anniversary_id' => $data['anniversary_id'] ?? null,
                'milestone_key' => $data['milestone_key'] ?? null,
                'title' => $data['title'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        $attachableType = null;
        $attachableId = null;
        if (($data['bind_type'] ?? null) === 'event' && ! empty($data['record_id'])) {
            $attachableType = \App\Models\Record::class;
            $attachableId = $data['record_id'];
        }

        $attachment = $this->attachments->storeLocal(
            $file,
            $workspaceId,
            $data['module'],
            $request->user()->id,
            $data['member_id'] ?? null,
            $data['captured_at'] ?? null,
            $data['note'] ?? null,
            $meta,
            $attachableType,
            $attachableId,
        );

        // 恋爱相册：用 LoveAlbumService 规范化绑定
        if ($data['module'] === 'love' && ! empty($data['bind_type'])) {
            $install = \App\Models\TemplatePackInstallation::where('workspace_id', $workspaceId)
                ->where('pack_key', 'love')
                ->first();
            if ($install) {
                try {
                    $attachment = app(\App\Services\LoveAlbumService::class)->bind($attachment, $install, $data);
                } catch (\InvalidArgumentException $e) {
                    // 保留上传结果，仅跳过绑定
                }
            }
        }

        return response()->json(['data' => $this->attachments->toArray($attachment)], 201);
    }

    public function show(Request $request, string $id)
    {
        $attachment = \App\Models\Attachment::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['data' => $this->attachments->toArray($attachment)]);
    }

    public function destroy(Request $request, string $id)
    {
        $attachment = \App\Models\Attachment::where('workspace_id', $request->attributes->get('workspace_id'))
            ->where('id', $id)
            ->firstOrFail();

        $this->attachments->softDelete($attachment, $request->user()->id);

        return response()->json(['message' => 'ok']);
    }
}
