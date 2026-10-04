<?php

namespace App\Enums;

enum AttachmentScanFailure: string
{
    case StorageUnavailable = 'storage_unavailable';
    case ContentChanged = 'content_changed';
    case ScannerUnavailable = 'scanner_unavailable';
    case ScannerProtocolError = 'scanner_protocol_error';
    case Interrupted = 'interrupted';
}
