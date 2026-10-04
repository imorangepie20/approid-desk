<?php

namespace App\Enums;

enum AttachmentDeletionEventType: string
{
    case Requested = 'requested';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
