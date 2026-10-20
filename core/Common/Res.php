<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects the current response into a handler argument. Only needed for
 * full control (custom headers, redirects); plain return values are
 * normalized automatically.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Res
{
    public function __construct()
    {
    }
}
