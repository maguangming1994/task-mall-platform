<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;

class MediaAssetController extends Controller
{
    public function show(MediaAsset $mediaAsset): RedirectResponse
    {
        abort_unless($mediaAsset->status === 'active' && filled($mediaAsset->public_url), 404);

        return redirect()->away($mediaAsset->public_url, 302, [
            'Cache-Control' => 'public, max-age=60',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
