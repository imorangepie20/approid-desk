<?php

namespace App\Http\Controllers;

use App\Actions\DownloadAttachment;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('view', $attachment);

        return (new DownloadAttachment)->handle($attachment);
    }
}
