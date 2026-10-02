<?php

namespace App\Data;

use App\Models\UserInvitation;

final readonly class CreatedUserInvitation
{
    public function __construct(
        public UserInvitation $invitation,
        public string $token,
    ) {}
}
