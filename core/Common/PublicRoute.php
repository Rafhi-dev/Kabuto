<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class PublicRoute {}
