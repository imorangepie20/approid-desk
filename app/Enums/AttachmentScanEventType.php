<?php

namespace App\Enums;

enum AttachmentScanEventType: string
{
    case Requested = 'requested';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
