<?php

namespace App\Enums;

enum AttachmentDeletionFailure: string
{
    case StorageUnavailable = 'storage_unavailable';
    case StorageDeleteFailed = 'storage_delete_failed';
    case Interrupted = 'interrupted';
}
