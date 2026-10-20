<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Marks a class as a provider (service, repository, guard, …) managed by the
 * container. Mostly documentary — every class listed in a module's
 * `providers:` is registered automatically.
 *
 *   #[Injectable()]
 *   final class UserService { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Injectable
{
    public function __construct()
    {
    }
}
