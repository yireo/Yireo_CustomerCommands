<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration\Console\Command;

use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfig;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Yireo\CustomerCommands\Console\Command\AddAddressCommand;
use Yireo\CustomerCommands\Console\Command\ListAddressesCommand;
use Yireo\CustomerCommands\Console\Command\RemoveAddressCommand;
use Yireo\CustomerCommands\Console\Command\RemoveAllAddressesCommand;

final class CommandListTest extends TestCase
{
    /**
     * @param class-string<Command> $className
     */
    #[DataProvider('commandProvider')]
    public function testCommandIsRegistered(string $itemName, string $className, string $commandName): void
    {
        $commands = Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands();
        $command = $commands[$itemName] ?? null;

        $this->assertInstanceOf($className, $command);
        $this->assertSame($commandName, $command->getName());
    }

    #[DataProvider('repositoryArgumentProvider')]
    public function testRepositoriesAreInjectedAsProxies(string $className, string $argumentName, string $proxyClass): void
    {
        $arguments = Bootstrap::getObjectManager()->get(ObjectManagerConfig::class)->getArguments($className);

        $this->assertSame($proxyClass, $arguments[$argumentName]['instance'] ?? null);
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function repositoryArgumentProvider(): array
    {
        $customerRepositoryProxy = 'Magento\\Customer\\Api\\CustomerRepositoryInterface\\Proxy';
        $addressRepositoryProxy = 'Magento\\Customer\\Api\\AddressRepositoryInterface\\Proxy';

        return [
            'list customer' => [ListAddressesCommand::class, 'customerRepository', $customerRepositoryProxy],
            'list address' => [ListAddressesCommand::class, 'addressRepository', $addressRepositoryProxy],
            'add customer' => [AddAddressCommand::class, 'customerRepository', $customerRepositoryProxy],
            'add address' => [AddAddressCommand::class, 'addressRepository', $addressRepositoryProxy],
            'remove address' => [RemoveAddressCommand::class, 'addressRepository', $addressRepositoryProxy],
            'remove-all customer' => [RemoveAllAddressesCommand::class, 'customerRepository', $customerRepositoryProxy],
            'remove-all address' => [RemoveAllAddressesCommand::class, 'addressRepository', $addressRepositoryProxy],
        ];
    }

    /**
     * @return array<string, array{string, class-string<Command>, string}>
     */
    public static function commandProvider(): array
    {
        return [
            'list' => ['customer_address_list', ListAddressesCommand::class, 'customer:address:list'],
            'add' => ['customer_address_add', AddAddressCommand::class, 'customer:address:add'],
            'remove' => ['customer_address_remove', RemoveAddressCommand::class, 'customer:address:remove'],
            'remove-all' => [
                'customer_address_remove_all',
                RemoveAllAddressesCommand::class,
                'customer:address:remove-all',
            ],
        ];
    }
}
