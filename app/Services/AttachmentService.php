<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Member;
use App\Support\AliOssTicket;
use App\Support\ShanghaiTime;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    public function __construct(protected AliOssService $oss) {}

    public function findByMd5(string $workspaceId, string $md5, string $module): ?Attachment
    {
        return Attachment::where('workspace_id', $workspaceId)
            ->where('md5_key', $md5)
            ->where('module', $module)
            ->first();
    }

    public function createFromCallback(AliOssTicket $ticket, array $payload): Attachment
    {
        $path = (string) ($payload['filepath'] ?? '');
        $ext = strtolower((string) ($payload['image.ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $mime = (string) ($payload['mime_type'] ?? '');
        $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'], true) ? 'image' : 'file');

        $memberId = $ticket->memberId;
        $capturedAt = now('Asia/Shanghai');
        $dayAge = $memberId ? $this->calcDayAge($memberId, $capturedAt) : null;

        return Attachment::create([
            'workspace_id' => $ticket->workspaceId,
            'disk' => $this->oss->diskName($ticket->store),
            'bucket_name' => (string) ($payload['bucket'] ?? config("alioss.stores.{$ticket->store}.bucket")),
            'size' => (int) ($payload['size'] ?? 0),
            'module' => $ticket->module,
            'kind' => $kind,
            'file_name' => (string) ($payload['filename'] ?? basename($path)),
            'file_path' => ltrim($path, '/'),
            'md5_key' => isset($payload['md5']) ? trim((string) $payload['md5'], '"') : null,
            'mime_type' => $mime ?: null,
            'width' => $this->nullableInt($payload['image.width'] ?? null),
            'height' => $this->nullableInt($payload['image.height'] ?? null),
            'extension' => $ext ?: null,
            'guard' => $ticket->guard,
            'uploaded_by' => $ticket->userId,
            'member_id' => $memberId,
            'captured_at' => $capturedAt,
            'day_age' => $dayAge,
        ]);
    }

    /** 本地/未配 OSS 时的服务端上传兜底 */
    public function storeLocal(
        UploadedFile $file,
        string $workspaceId,
        string $module,
        int $userId,
        ?string $memberId = null,
        ?string $capturedAt = null,
        ?string $note = null,
        ?array $meta = null,
        ?string $attachableType = null,
        ?string $attachableId = null,
    ): Attachment {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $dir = 'workspace/'.$module.'/'.today()->format('Ym/d');
        $name = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs($dir, $name, 'public');
        $mime = $file->getClientMimeType() ?: 'application/octet-stream';
        $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'file');

        $captured = $capturedAt ? ShanghaiTime::parse($capturedAt) : now('Asia/Shanghai');

        return Attachment::create([
            'workspace_id' => $workspaceId,
            'disk' => 'public',
            'bucket_name' => null,
            'size' => $file->getSize() ?: 0,
            'module' => $module,
            'kind' => $kind,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'md5_key' => md5_file($file->getRealPath()) ?: null,
            'mime_type' => $mime,
            'extension' => $ext,
            'guard' => 'app',
            'uploaded_by' => $userId,
            'member_id' => $memberId,
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'captured_at' => $captured,
            'day_age' => $memberId ? $this->calcDayAge($memberId, $captured) : null,
            'note' => $note,
            'meta' => $meta,
        ]);
    }

    public function softDelete(Attachment $attachment, ?int $userId = null): void
    {
        if ($userId) {
            $attachment->deleted_by = $userId;
            $attachment->saveQuietly();
        }
        $attachment->delete();
    }

    public function toArray(Attachment $a): array
    {
        return [
            'id' => $a->id,
            'url' => $a->url(),
            'thumb_url' => $a->thumbUrl(),
            'disk' => $a->disk,
            'bucket_name' => $a->bucket_name,
            'size' => $a->size,
            'module' => $a->module,
            'module_label' => config("alioss.modules.{$a->module}.label", $a->module),
            'kind' => $a->kind,
            'file_name' => $a->file_name,
            'file_path' => $a->file_path,
            'md5_key' => $a->md5_key,
            'mime_type' => $a->mime_type,
            'width' => $a->width,
            'height' => $a->height,
            'duration' => $a->duration,
            'extension' => $a->extension,
            'member_id' => $a->member_id,
            'attachable_type' => $a->attachable_type,
            'attachable_id' => $a->attachable_id,
            'captured_at' => ShanghaiTime::format($a->captured_at),
            'captured_on' => optional($a->captured_at)?->timezone('Asia/Shanghai')->toDateString(),
            'day_age' => $a->day_age,
            'note' => $a->note,
            'meta' => $a->meta,
            'created_at' => ShanghaiTime::format($a->created_at),
        ];
    }

    public function calcDayAge(string $memberId, Carbon|string $at): ?int
    {
        $member = Member::find($memberId);
        if (! $member) {
            return null;
        }
        $born = $member->born_at ?: $member->birthday;
        if (! $born) {
            return null;
        }
        $start = Carbon::parse($born)->timezone('Asia/Shanghai')->startOfDay();
        $end = Carbon::parse($at)->timezone('Asia/Shanghai')->startOfDay();

        return (int) $start->diffInDays($end) + 1;
    }

    protected function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === 'null') {
            return null;
        }

        return (int) $v;
    }
}
