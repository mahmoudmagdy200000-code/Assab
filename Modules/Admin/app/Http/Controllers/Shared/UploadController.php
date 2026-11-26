<?php

namespace Modules\Admin\Http\Controllers\Shared;

use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;

/**
 * File uploads & attachments (BACKEND_API_SPEC.md §7.3). Uses the local public
 * disk; the presigned-URL flow points back at the direct-upload endpoint so the
 * client contract matches an S3 deployment (swap the disk for s3 in prod).
 */
class UploadController extends AsabController
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
        'html', 'htm', 'xhtml', 'svg', 'js', 'mjs', 'cjs',
        'exe', 'dll', 'sh', 'bash', 'bat', 'cmd', 'ps1',
    ];

    private const BLOCKED_MIMES = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'application/x-php',
        'text/x-php',
        'application/x-httpd-php',
        'application/javascript',
        'text/javascript',
        'application/x-msdownload',
        'application/x-sh',
    ];

    public function presignedUrl(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'filename' => 'required|string|max:255',
                'mimeType' => 'required|string|max:80',
                'size' => 'required|integer|min:1|max:10485760',
                'ownerType' => 'required|string|max:32',
                'ownerId' => 'nullable|string',
                'label' => 'nullable|string|max:80',
            ]);

            $this->assertSafeFileMeta($data['filename'], $data['mimeType']);
            $this->authorizeOwnerTarget($request, $data['ownerType'], $data['ownerId'] ?? null);

            $safeFilename = basename($data['filename']);
            $storageKey = ($request->user()->company_id ?? 'platform').'/'.$data['ownerType'].'/'.Str::uuid().'-'.$safeFilename;

            $attachment = Attachment::create([
                'owner_type' => $data['ownerType'],
                'owner_id' => $data['ownerId'] ?? (string) Str::uuid(),
                'filename' => $safeFilename,
                'mime_type' => $data['mimeType'],
                'size' => $data['size'],
                'storage_key' => $storageKey,
                'label' => $data['label'] ?? null,
                'uploaded_by_id' => $request->user()->id,
            ]);

            return $this->ok([
                'uploadUrl' => url('/api/v1/uploads/direct'),
                'storageKey' => $storageKey,
                'attachmentId' => $attachment->id,
                'expiresIn' => 600,
            ]);
        });
    }

    public function confirm(Request $request, string $attachmentId): JsonResponse
    {
        return $this->run(function () use ($request, $attachmentId) {
            $attachment = $this->findAuthorizedAttachment($request, $attachmentId);
            $attachment->update(['uploaded_at' => now()]);

            return $this->ok($this->present($attachment));
        });
    }

    public function direct(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'file' => 'required|file|max:10240',
                'ownerType' => 'required|string|max:32',
                'ownerId' => 'nullable|string',
                'label' => 'nullable|string|max:80',
            ]);

            $file = $request->file('file');
            $this->assertSafeFileMeta(
                (string) $file->getClientOriginalName(),
                (string) ($file->getClientMimeType() ?: $file->getMimeType()),
            );
            $this->authorizeOwnerTarget($request, (string) $request->input('ownerType'), $request->input('ownerId'));

            $dir = ($request->user()->company_id ?? 'platform').'/'.$request->input('ownerType');
            $path = $file->store($dir, 'public');

            $attachment = Attachment::create([
                'owner_type' => $request->input('ownerType'),
                'owner_id' => $request->input('ownerId') ?? (string) Str::uuid(),
                'filename' => basename($file->getClientOriginalName()),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'storage_key' => $path,
                'public_url' => PublicUrl::for($path),
                'label' => $request->input('label'),
                'uploaded_by_id' => $request->user()->id,
                'uploaded_at' => now(),
            ]);

            return $this->created($this->present($attachment));
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present($this->findAuthorizedAttachment($request, $id))));
    }

    public function download(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $a = $this->findAuthorizedAttachment($request, $id);
            $url = $a->public_url ?: (Storage::disk('public')->exists($a->storage_key) ? PublicUrl::for($a->storage_key) : null);

            return $this->ok(['url' => $url, 'expiresIn' => 600]);
        });
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $a = $this->findAuthorizedAttachment($request, $id);
            $a->update(['verified_at' => now(), 'verified_by_id' => $request->user()->id]);

            return $this->ok($this->present($a));
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $a = $this->findAuthorizedAttachment($request, $id);
            if ($a->storage_key && Storage::disk('public')->exists($a->storage_key)) {
                Storage::disk('public')->delete($a->storage_key);
            }
            $a->delete();

            return $this->noContent();
        });
    }

    private function assertSafeFileMeta(string $filename, string $mimeType): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        if (in_array($ext, self::BLOCKED_EXTENSIONS, true) || in_array($mime, self::BLOCKED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ['File type is not permitted for attachment upload.'],
            ]);
        }
    }

    private function authorizeOwnerTarget(Request $request, string $ownerType, ?string $ownerId): void
    {
        if ($ownerType !== 'operation' || $ownerId === null || trim($ownerId) === '') {
            return;
        }

        $op = Operation::withoutGlobalScopes()->find($ownerId);
        if (! $op) {
            throw (new ModelNotFoundException)->setModel(Operation::class, [$ownerId]);
        }

        $this->assertOperationInScope($request, $op);
    }

    private function findAuthorizedAttachment(Request $request, string $id): Attachment
    {
        $attachment = Attachment::findOrFail($id);

        if ($attachment->owner_type === 'operation' && $attachment->owner_id) {
            $op = Operation::withoutGlobalScopes()->find($attachment->owner_id);
            if (! $op) {
                throw (new ModelNotFoundException)->setModel(Attachment::class, [$id]);
            }
            $this->assertOperationInScope($request, $op);

            return $attachment;
        }

        $user = $request->user();
        $tenantIds = $this->tenantCompanyIdsFor($user);
        $assignedBranches = $this->assignedBranchIds();

        // Unrestricted platform users can access platform-level attachments;
        // company-scoped users may only access attachments within their tenant.
        if ($user->company_id !== null || $assignedBranches !== null) {
            $ownerCompanyId = $this->extractStorageCompanyPrefix($attachment->storage_key);
            if ($ownerCompanyId === null && $attachment->uploaded_by_id) {
                $ownerCompanyId = AsabUser::where('id', $attachment->uploaded_by_id)->value('company_id');
            }
            if ($ownerCompanyId === null || ! in_array($ownerCompanyId, $tenantIds, true)) {
                throw (new ModelNotFoundException)->setModel(Attachment::class, [$id]);
            }
        }

        return $attachment;
    }

    private function assertOperationInScope(Request $request, Operation $op): void
    {
        $tenantIds = $this->tenantCompanyIdsFor($request->user());
        if (! in_array($op->company_id, $tenantIds, true)) {
            throw (new ModelNotFoundException)->setModel(Operation::class, [$op->id]);
        }

        $assignedBranches = $this->assignedBranchIds();
        if ($assignedBranches !== null && ! in_array($op->branch_id, $assignedBranches, true)) {
            throw (new ModelNotFoundException)->setModel(Operation::class, [$op->id]);
        }
    }

    private function extractStorageCompanyPrefix(?string $storageKey): ?string
    {
        if (! $storageKey) {
            return null;
        }
        $prefix = explode('/', ltrim($storageKey, '/'))[0] ?? '';

        return ($prefix === '' || $prefix === 'platform') ? null : $prefix;
    }

    private function present(Attachment $a): array
    {
        return [
            'id' => $a->id,
            'ownerType' => $a->owner_type,
            'ownerId' => $a->owner_id,
            'filename' => $a->filename,
            'mimeType' => $a->mime_type,
            'size' => $a->size,
            'storageKey' => $a->storage_key,
            'publicUrl' => $a->public_url,
            'label' => $a->label,
            'verifiedAt' => optional($a->verified_at)->toIso8601String(),
            'uploadedAt' => optional($a->uploaded_at)->toIso8601String(),
        ];
    }
}
