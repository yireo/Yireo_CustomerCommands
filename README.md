# Yireo_CustomerCommands

<!-- badges.specs.start -->
![Magento version](https://img.shields.io/badge/Magento-2.4.6%20%7C%202.4.9-orange)
![PHP version](https://img.shields.io/badge/PHP-8.2%E2%80%938.5-777BB4)
![License](https://img.shields.io/badge/License-OSL--3.0-blue)
![Latest Version](https://img.shields.io/packagist/v/yireo/magento2-customer-commands)
<!-- badges.specs.end -->


> This Magento 2 module adds various CLI commands to manage customers

### Installation
Install this package via composer:
```bash
composer require yireo/magento2-customer-commands
```

Next, enable this module:
```bash
bin/magento module:enable Yireo_CustomerCommands
```
## Usage
All commands identify a customer with either `--customer_id` or `--customer_email` (not both). With `--website` (ID or code), the customer is looked up in that website, which is needed when customer accounts are shared per website.

```bash
bin/magento customer:address:list --customer_email=jane@example.com --website=base
bin/magento customer:address:add --customer_id=42 --firstname=Jane --lastname=Doe --street="1 Congress Avenue" \
    --city=Austin --postcode=78701 --region=Texas --country=US --telephone=0123456789 --default-billing --default-shipping
bin/magento customer:address:remove 12 --customer_id=42
bin/magento customer:address:remove-all --customer_email=jane@example.com --dry-run
```

- `customer:address:add`: `--country` is a two-letter ISO code. `--region` is a region ID or name that must belong to that country; for countries without predefined regions, a name is stored as text. `--default-billing` and `--default-shipping` are flags without a value.
- `customer:address:remove`: With `--customer_id` or `--customer_email`, the address is only removed when it belongs to that customer.
- `customer:address:remove` and `customer:address:remove-all` ask for confirmation. In non-interactive mode (scripts, cron, CI) they refuse to remove anything unless `--force` (`-f`) is passed. `customer:address:remove-all --dry-run` only shows what would be removed, and the command exits with a failure code when some addresses could not be removed.

Note that `customer:address:list` prints personal data, and options like `--customer_email` end up in your shell history.

## Current status

<!-- badges.test.start -->
![Static Tests](https://img.shields.io/github/actions/workflow/status/yireo/Yireo_CustomerCommands/static-tests.yml?label=static-tests)
![Unit Tests](https://img.shields.io/github/actions/workflow/status/yireo/Yireo_CustomerCommands/unit-tests.yml?label=unit-tests)
![Integration Tests](https://img.shields.io/github/actions/workflow/status/yireo/Yireo_CustomerCommands/integration-tests.yml?label=integration-tests)
![Playwright](https://img.shields.io/github/actions/workflow/status/yireo/Yireo_CustomerCommands/playwright.yml?label=playwright)
![DI Compilation](https://img.shields.io/github/actions/workflow/status/yireo/Yireo_CustomerCommands/compile.yml?label=compile)
<!-- badges.test.end -->
