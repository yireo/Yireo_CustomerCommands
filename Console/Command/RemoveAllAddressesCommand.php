<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;
use Yireo\CustomerCommands\Console\RemovalConfirmation;

class RemoveAllAddressesCommand extends Command
{
    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CustomerResolver $customerResolver,
        private readonly RemovalConfirmation $removalConfirmation,
        private readonly OutputSanitizer $outputSanitizer,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('customer:address:remove-all');
        $this->setDescription('Remove all addresses of a specific customer');
        $this->customerResolver->addOptions($this);
        $this->removalConfirmation->addOption($this);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show which addresses would be removed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int)$this->state->emulateAreaCode(
            Area::AREA_ADMINHTML,
            fn () => $this->removeAllAddresses($input, $output)
        );
    }

    private function removeAllAddresses(InputInterface $input, OutputInterface $output): int
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
            $output->writeln(sprintf('<info>No addresses found for customer ID %d.</info>', $customer->getId()));
            return Command::SUCCESS;
        }

        $addressIds = [];
        foreach ($addresses as $address) {
            $addressIds[] = (int)$address->getId();
        }

        $summary = sprintf(
            '%d address(es) (IDs: %s) of customer %s (ID %d)',
            count($addresses),
            implode(', ', $addressIds),
            $this->outputSanitizer->sanitize($customer->getEmail()),
            $customer->getId()
        );

        if ($input->getOption('dry-run') === true) {
            $output->writeln('<info>Dry run: Would remove ' . $summary . '.</info>');
            return Command::SUCCESS;
        }

        if (!$this->removalConfirmation->isConfirmed($input, $output, 'Remove ' . $summary . '?')) {
            return Command::FAILURE;
        }

        $count = 0;
        foreach ($addresses as $address) {
            try {
                $this->addressRepository->delete($address);
                $count++;
            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    '<error>Could not delete address ID %d: %s</error>',
                    $address->getId(),
                    $this->outputSanitizer->sanitize($e->getMessage())
                ));
            }
        }

        $output->writeln(sprintf(
            '<info>Successfully removed %d address(es) for customer ID %d.</info>',
            $count,
            $customer->getId()
        ));

        if ($count < count($addresses)) {
            $output->writeln(sprintf(
                '<error>Failed to remove %d address(es).</error>',
                count($addresses) - $count
            ));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
