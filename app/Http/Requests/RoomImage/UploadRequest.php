<?php

namespace App\Http\Requests\RoomImage;

use App\Http\Requests\ApiFormRequest;

class UploadRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                // Confirms genuine image data (dimension-readable), not
                // just a plausible-looking extension.
                'image',
                // Explicit allowlist on top of 'image' - the bare
                // 'image' rule alone would also accept gif/bmp/svg.
                // svg specifically is excluded deliberately: it can
                // embed <script> content, a real risk if ever rendered
                // directly by a browser. Both rules use content-derived
                // detection (Symfony's mime-type guesser), never the
                // client-supplied filename or Content-Type header.
                'mimes:jpg,jpeg,png,webp',
                // 5MB - a chosen default, not a number given in the
                // spec. See docs/STEP-08-ROOM-IMAGE-PLAN.md §4.
                'max:5120',
            ],
        ];
    }
}
