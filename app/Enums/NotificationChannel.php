<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Database = 'database';
    case Mail = 'mail';
}
