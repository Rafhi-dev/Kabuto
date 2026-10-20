<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UploadedFile
{
    /**
     * @param string|null $name Form-data field name (defaults to parameter name if null)
     * @param string|null $maxSize Maximum allowed size, e.g. '2MB', '500KB', '10M'
     * @param list<string> $mimes Allowed MIME types or extensions, e.g. ['image/png', 'image/jpeg'] or ['png', 'jpg']
     * @param bool $required Whether the uploaded file is strictly required
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $maxSize = null,
        public readonly array $mimes = [],
        public readonly bool $required = true,
    ) {
    }
}
