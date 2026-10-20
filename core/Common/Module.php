<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Declares a class as a module: a group of controllers and providers that
 * belong together.
 *
 *   #[Module(
 *       imports: [PostModule::class],
 *       controllers: [UserController::class],
 *       providers: [UserService::class, UserRepository::class],
 *   )]
 *   final class UserModule {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Module
{
    /**
     * @param list<class-string> $imports     Other modules this module depends on.
     * @param list<class-string> $controllers Controllers whose routes are registered.
     * @param list<class-string> $providers   Services registered in the container.
     * @param list<class-string> $exports     Services exported by this module.
     */
    public function __construct(
        public readonly array $imports = [],
        public readonly array $controllers = [],
        public readonly array $providers = [],
        public readonly array $exports = [],
    ) {
    }
}
