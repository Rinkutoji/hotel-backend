<?php

namespace App\Services;

use App\Models\RoomImage;
use App\Models\RoomType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RoomImageService
{
    public function upload(RoomType $roomType, UploadedFile $file): RoomImage
    {
        // Generated filename, never the client-supplied one - avoids
        // path traversal, collisions, and any executable-extension
        // trickery riding on a client-controlled name. extension() is
        // guessed from the file's actual content (same mechanism the
        // 'mimes' validation rule uses), not the original upload name.
        $filename = Str::uuid()->toString().'.'.$file->extension();
        $path = $file->storeAs("room-types/{$roomType->id}", $filename, 'public');

        try {
            $nextSortOrder = (int) ($roomType->images()->max('sort_order')) + 1;

            return RoomImage::create([
                'room_type_id' => $roomType->id,
                'path' => $path,
                'sort_order' => $nextSortOrder,
            ]);
        } catch (Throwable $e) {
            // The file was already written above - if the DB insert
            // fails for any reason, clean it up rather than leaving a
            // genuinely orphaned file (zero DB reference at all). See
            // docs/STEP-08-ROOM-IMAGE-PLAN.md §5.
            Storage::disk('public')->delete($path);

            throw $e;
        }
    }

    public function updateSortOrder(RoomImage $image, int $sortOrder): RoomImage
    {
        $image->update(['sort_order' => $sortOrder]);

        return $image->fresh();
    }

    public function delete(RoomImage $image): void
    {
        $path = $image->path;

        // DB deletion is the authoritative step - once this commits,
        // the image no longer exists as far as the application is
        // concerned, regardless of what happens to the physical file
        // next. This ordering is deliberate: the failure mode if file
        // deletion below has a problem is an orphaned file (wasted
        // space, harmless - nothing references it anymore). The
        // reverse order would risk a worse failure mode: a DB record
        // pointing at a file that no longer exists, which a real user
        // could hit and get a broken image on. See
        // docs/STEP-08-ROOM-IMAGE-PLAN.md §6.
        DB::transaction(function () use ($image) {
            $image->delete();
        });

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            // Best-effort cleanup. The DB is already the source of
            // truth and the record is legitimately gone - a
            // filesystem-level hiccup here (permissions, already-missing
            // file, etc.) must not turn into a 500 for the API caller.
            // "Handle missing physical files safely."
            report($e);
        }
    }
}
