<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveAllAddressesCommand extends Command
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private AddressRepositoryInterface $addressRepository,
        private SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly State $state,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('customer:address:remove-all');
        $this->setDescription('Remove all addresses of a specific customer');
        $this->addOption('customer_id', null, InputOption::VALUE_OPTIONAL, 'Customer ID');
        $this->addOption('customer_email', null, InputOption::VALUE_OPTIONAL, 'Customer email');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $input->getOption('customer_id');
        $customerEmail = $input->getOption('customer_email');
        if (empty($customerId) && empty($customerEmail)) {
            $output->writeln('<error>Please supply either customer ID or email</error>');
            return Command::FAILURE;
        }

        $customer = null;
        if ($customerId > 0) {
            $customer = $this->customerRepository->getById($customerId);
        }

        $this->state->setAreaCode('frontend');

        if (!empty($customerEmail)) {
            $customer = $this->customerRepository->get($customerEmail);
        }

        if (empty($customer)) {
            $output->writeln('<error>Unable to load customer</error>');
            return Command::FAILURE;
        }

        $this->searchCriteriaBuilder->addFilter('parent_id', $customer->getId());
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
