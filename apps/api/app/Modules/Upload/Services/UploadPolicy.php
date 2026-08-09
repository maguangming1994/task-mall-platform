<?php

namespace App\Modules\Upload\Services;

use Illuminate\Validation\ValidationException;

class UploadPolicy
{
    /** @return array{extension:string,category:string} */
    public function validate(string $filename, string $mime, int $size, string $purpose): array
    {
        $rule = config("uploads.allowed.{$purpose}");
        if (! is_array($rule)) {
            throw ValidationException::withMessages(['purpose' => ['不支持该上传用途。']]);
        }
        $name = basename(str_replace('\\', '/', trim($filename)));
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw ValidationException::withMessages(['filename' => ['文件名不合法。']]);
        }
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $category = collect($rule['categories'])->first(fn ($candidate) => in_array($extension, config("uploads.extensions.{$candidate}", []), true));
        if ($category === null || ! in_array(strtolower($mime), config("uploads.mimes.{$category}", []), true)) {
            throw ValidationException::withMessages(['content_type' => ['扩展名与 MIME 类型不在该用途的允许范围。']]);
        }
        if ($size < 1 || $size > $rule['max_bytes']) {
            throw ValidationException::withMessages(['size' => ["文件大小必须在 1 到 {$rule['max_bytes']} 字节之间。"]]);
        }

        return ['extension' => $extension, 'category' => $category];
    }
}
