<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects the current request into a handler argument.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Req
{
    public function __construct()
    {
    }
}
