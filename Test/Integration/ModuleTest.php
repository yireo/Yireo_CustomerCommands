<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration;

use PHPUnit\Framework\TestCase;
use Yireo\IntegrationTestHelper\Test\Integration\Traits\AssertModuleIsEnabled;
use Yireo\IntegrationTestHelper\Test\Integration\Traits\AssertModuleIsRegistered;

final class ModuleTest extends TestCase
{
    use AssertModuleIsEnabled;
    use AssertModuleIsRegistered;

    public function testIfModuleIsEnabled(): void
    {
        $requiredModules = [
            'Magento_Customer',
            'Magento_Directory',
            'Yireo_CustomerCommands',
        ];

        foreach ($requiredModules as $moduleName) {
            $this->assertModuleIsRegistered($moduleName);
            $this->assertModuleIsEnabled($moduleName);
        }
    }
}
