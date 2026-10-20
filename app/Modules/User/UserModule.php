<?php

declare(strict_types=1);

namespace App\Modules\User;

use Kabuto\Common\Module;

#[Module(
    controllers: [UserController::class],
    providers: [UserService::class, UserRepository::class],
    exports: [UserService::class],
)]
final class UserModule
{
}
