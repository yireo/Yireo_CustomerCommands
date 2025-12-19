<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveAllAddressesCommand extends Command
{
    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @param AddressRepositoryInterface $addressRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param string|null $name
     */
    public function __construct(
        AddressRepositoryInterface $addressRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $name = null
    ) {
        parent::__construct($name);
        $this->addressRepository = $addressRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('customer:address:remove-all');
        $this->setDescription('Remove all addresses of a specific customer');
        $this->addArgument('customer_id', InputArgument::REQUIRED, 'Customer ID');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $input->getArgument('customer_id');

        $this->searchCriteriaBuilder->addFilter('parent_id', $customerId);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $addresses = $this->addressRepository->getList($searchCriteria)->getItems();

        if (empty($addresses)) {
            $output->writeln(sprintf('<info>No addresses found for customer ID %s.</info>', $customerId));
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($addresses as $address) {
            try {
                $this->addressRepository->delete($address);
                $count++;
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Could not delete address ID %s: %s</error>', $address->getId(), $e->getMessage()));
            }
        }

        $output->writeln(sprintf('<info>Successfully removed %d address(es) for customer ID %s.</info>', $count, $customerId));

        return Command::SUCCESS;
    }
}
