<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AttachmentFileTypeRules
{
    public function validate(string $name, string $mime): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = config('attachments.allowed_types');
        $allowed = is_array($types) ? ($types[$extension] ?? null) : null;
        if ($extension === '' || ! is_array($allowed) || ! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => '허용되지 않은 파일 형식이거나 확장자와 실제 파일 유형이 일치하지 않습니다.',
            ]);
        }
    }
}
