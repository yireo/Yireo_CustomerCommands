<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveAddressCommand extends Command
{
    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @param AddressRepositoryInterface $addressRepository
     * @param string|null $name
     */
    public function __construct(
        AddressRepositoryInterface $addressRepository,
        string $name = null
    ) {
        parent::__construct($name);
        $this->addressRepository = $addressRepository;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('customer:address:remove');
        $this->setDescription('Remove a customer address by ID');
        $this->addArgument('address_id', InputArgument::REQUIRED, 'Address ID');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $addressId = (int)$input->getArgument('address_id');

        try {
            $this->addressRepository->deleteById($addressId);
            $output->writeln(sprintf('<info>Address with ID %d has been removed.</info>', $addressId));
        } catch (NoSuchEntityException $e) {
            $output->writeln(sprintf('<error>Address with ID %d does not exist.</error>', $addressId));
            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>An error occurred: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
