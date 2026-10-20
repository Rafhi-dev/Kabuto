<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Can
{
    public function __construct(public readonly string $ability, public readonly string $model = '', public readonly string $parameter = '') {}
}
