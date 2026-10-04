<?php

namespace App\Enums;

enum AttachmentDeletionStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Deleted = 'deleted';
    case Failed = 'failed';
}
