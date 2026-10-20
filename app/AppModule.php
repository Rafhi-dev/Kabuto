<?php

declare(strict_types=1);

namespace App;

use App\Modules\Status\StatusModule;
use App\Modules\User\UserModule;
use Kabuto\Common\Module;

#[Module(
    imports: [
        UserModule::class,
        StatusModule::class,
    ],
    controllers: [
        AppController::class,
    ],
)]
final class AppModule
{
}
