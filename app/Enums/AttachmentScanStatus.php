<?php

namespace App\Enums;

enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Scanning = 'scanning';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
