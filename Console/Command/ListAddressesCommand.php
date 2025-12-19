<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListAddressesCommand extends Command
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
        $this->setName('customer:address:list');
        $this->setDescription('List addresses of a customer');
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
            $output->writeln('<info>No addresses found for this customer.</info>');
            return Command::SUCCESS;
        }

        foreach ($addresses as $address) {
            $output->writeln(sprintf(
                'ID: %s, %s %s, %s, %s, %s',
                $address->getId(),
                $address->getFirstname(),
                $address->getLastname(),
                implode(', ', $address->getStreet()),
                $address->getCity(),
                $address->getPostcode()
            ));
        }

        return Command::SUCCESS;
    }
}
