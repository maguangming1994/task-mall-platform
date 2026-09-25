<?php

namespace App\Modules\Account\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserAvatarController extends Controller
{
    public function show(UploadedFile $file): StreamedResponse
    {
        $allowedMimes = config('uploads.mimes.image', []);
        $isReferencedByActiveOwner = $file->owner_user_id !== null
            && User::query()
                ->whereKey($file->owner_user_id)
                ->where('avatar_file_id', $file->id)
                ->where('status', 'active')
                ->exists();

        abort_unless(
            $file->purpose === 'avatar'
                && $file->status === 'clean'
                && is_string($file->detected_mime)
                && in_array($file->detected_mime, $allowedMimes, true)
                && $isReferencedByActiveOwner,
            404,
        );
        abort_unless(Storage::disk($file->disk)->exists($file->quarantine_path), 404);

        return response()->stream(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->detected_mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
