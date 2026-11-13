<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Photos live in public/<folder>/ and the row keeps "/<folder>/<file>" in photo_url: the mobile
 * app builds its image URLs from that path, so the format must not change.
 *
 * A form sends `photo` (a file) to replace the picture, or `remove_photo=1` to clear it; sending
 * neither keeps the current one.
 */
trait StoresPhotos
{
    public const PHOTO_RULE = 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:5120';

    protected function applyPhoto(Request $request, Model $model, string $folder, string $column = 'photo_url'): void
    {
        if ($request->hasFile('photo')) {
            $old = $model->{$column};
            $model->{$column} = $this->storePhoto($request->file('photo'), $folder);
            $this->deletePhoto($old);
        } elseif ($request->boolean('remove_photo')) {
            $this->deletePhoto($model->{$column});
            $model->{$column} = null;
        }
    }

    protected function storePhoto(UploadedFile $file, string $folder): string
    {
        $directory = public_path($folder);
        File::ensureDirectoryExists($directory, 0775);

        $name = time() . '_' . Str::lower(Str::random(8)) . '.' . ($file->guessExtension() ?: 'jpg');
        $file->move($directory, $name);

        return '/' . $folder . '/' . $name;
    }

    protected function deletePhoto(?string $url): void
    {
        if (! $url || str_starts_with($url, 'http')) {
            return;
        }

        $path = public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
        $real = realpath($path);

        // Only files inside public/, never a path that climbs out of it.
        if ($real && str_starts_with($real, realpath(public_path())) && is_file($real)) {
            File::delete($real);
        }
    }
}
