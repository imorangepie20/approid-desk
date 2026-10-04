<?php

namespace App\Enums;

enum WorkLogStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
