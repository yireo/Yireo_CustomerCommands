<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;

class ListAddressesCommand extends Command
{
    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CustomerResolver $customerResolver,
        private readonly OutputSanitizer $outputSanitizer,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('customer:address:list');
        $this->setDescription('List addresses of a customer');
        CustomerResolver::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int)$this->state->emulateAreaCode(
            Area::AREA_ADMINHTML,
            fn () => $this->listAddresses($input, $output)
        );
    }

    private function listAddresses(InputInterface $input, OutputInterface $output): int
    {
        try {
            $customer = $this->customerResolver->resolve($input);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $this->outputSanitizer->sanitize($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }

        $this->searchCriteriaBuilder->addFilter('parent_id', $customer->getId());
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $addresses = $this->addressRepository->getList($searchCriteria)->getItems();

        if (empty($addresses)) {
            $output->writeln('<info>No addresses found for this customer.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'First name',
            'Last name',
            'Street address',
            'City',
            'Postcode',
            'Country'
        ]);

        foreach ($addresses as $address) {
            $table->addRow(array_map([$this->outputSanitizer, 'sanitize'], [
                $address->getId(),
                $address->getFirstname(),
                $address->getLastname(),
                implode(', ', $address->getStreet() ?? []),
                $address->getCity(),
                $address->getPostcode(),
                $address->getCountryId(),
            ]));
        }

        $table->render();

        return Command::SUCCESS;
    }
}
