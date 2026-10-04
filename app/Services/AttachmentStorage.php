<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class AttachmentStorage
{
    /** @return array{disk: string, path: string} */
    public function store(UploadedFile $file): array
    {
        $disk = $this->disk();
        $token = bin2hex(random_bytes(32));
        $directory = 'objects/'.substr($token, 0, 2).'/'.substr($token, 2, 2);
        $path = $directory.'/'.$token;

        try {
            $stored = Storage::disk($disk)->putFileAs(
                $directory,
                $file->getPathname(),
                $token,
                ['visibility' => 'private'],
            );
        } catch (Throwable $exception) {
            $this->delete($disk, $path);
            report($exception);
            throw ValidationException::withMessages(['file' => '첨부파일을 비공개 저장소에 저장하지 못했습니다.']);
        }
        if ($stored !== $path) {
            $this->delete($disk, $path);
            throw ValidationException::withMessages(['file' => '첨부파일을 비공개 저장소에 저장하지 못했습니다.']);
        }

        return ['disk' => $disk, 'path' => $path];
    }

    public function delete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function disk(): string
    {
        $disk = config('attachments.disk');
        $configuration = is_string($disk) ? config('filesystems.disks.'.$disk) : null;
        if (! is_string($disk) || $disk === '' || strlen($disk) > 64 || ! is_array($configuration)
            || ($configuration['visibility'] ?? null) !== 'private') {
            throw ValidationException::withMessages(['file' => '첨부파일 비공개 저장소 설정을 확인해야 합니다.']);
        }

        return $disk;
    }
}
