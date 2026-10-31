import {test, expect, Locator, Page} from '@playwright/test';
import {spawnSync} from 'child_process';

export {test, expect};

export const HYVA_THEME = 'Hyva/default-csp';

export const CUSTOMER_PASSWORD = 'Playwright123!';

export type Theme = 'luma' | 'hyva';

export type AddressOptions = Record<string, string | null>;

export const texasAddress: AddressOptions = {
    firstname: 'Jane',
    lastname: 'Doe',
    street: '1 Congress Avenue',
    city: 'Austin',
    postcode: '78701',
    region: 'Texas',
    country: 'US',
    telephone: '0123456789',
};

export const dutchAddress: AddressOptions = {
    firstname: 'Jan',
    lastname: 'Jansen',
    street: 'Dorpsstraat 5',
    city: 'Utrecht',
    postcode: '3511 AA',
    country: 'NL',
    telephone: '0301234567',
};

const token = () => process.env.TEST_TOKEN;

export const uniqueEmail = (): string =>
    `customer-commands-${Date.now()}-${Math.floor(Math.random() * 10000)}@example.com`;

/**
 * Run bin/magento: Through `docker exec` when PHP lives in another container (PHP_CONTAINER, GitHub Actions),
 * otherwise directly (Hersir, where the Playwright container shares the Magento volume and has PHP)
 */
export function runMagento(args: string[]): { status: number, output: string } {
    const magentoRoot = process.env.MAGENTO_ROOT || '/tmp/magento';
    const phpContainer = (process.env.PHP_CONTAINER ?? '').trim();
    const magentoArgs = ['-d', 'memory_limit=-1', magentoRoot + '/bin/magento', ...args, '--no-ansi'];

    const result = phpContainer
        ? spawnSync('docker', ['exec', '-u', 'root', '-w', magentoRoot, phpContainer, 'php', ...magentoArgs], {encoding: 'utf8'})
        : spawnSync('php', magentoArgs, {encoding: 'utf8', cwd: magentoRoot});

    if (result.error) {
        throw result.error;
    }

    return {status: result.status ?? 1, output: (result.stdout ?? '') + (result.stderr ?? '')};
}

export function addAddress(email: string, address: AddressOptions, flags: string[] = []): number {
    const options = Object.entries(address).map(([name, value]) => `--${name}=${value}`);
    const result = runMagento([
        'customer:address:add',
        '--customer_email=' + email,
        ...options,
        ...flags.map(flag => '--' + flag),
    ]);

    expect(result.status, result.output).toBe(0);
    const match = result.output.match(/Successfully added address \(ID: (\d+)\)/);
    expect(match, result.output).not.toBeNull();

    return Number(match![1]);
}

export function removeAddress(addressId: number) {
    const result = runMagento(['customer:address:remove', String(addressId)]);
    expect(result.status, result.output).toBe(0);
    expect(result.output).toContain(`Address with ID ${addressId} has been removed.`);
}

export function removeAllAddresses(email: string): string {
    const result = runMagento(['customer:address:remove-all', '--customer_email=' + email]);
    expect(result.status, result.output).toBe(0);

    return result.output;
}

/**
 * Configure the store via the Loki_FunctionalTests endpoint
 *
 * The endpoint falls back to Magento/luma whenever `theme` is missing, so a Hyva run must pass it every time
 */
export async function configureStore(page: Page, theme: Theme, payload: Record<string, any> = {}) {
    const requestData = theme === 'hyva' ? {...payload, theme: HYVA_THEME} : payload;
    const response = await page.request.post('/loki_functional_tests/index/configure?token=' + token(), {
        form: {config: JSON.stringify(requestData)},
    });

    const body = await response.text();
    let data: any;
    try {
        data = JSON.parse(body);
    } catch (error) {
        throw new Error(`Configure endpoint returned no JSON (status ${response.status()}): ${body.slice(0, 500)}`);
    }

    const errors: string[] = [...(data.error ? [String(data.error)] : []), ...(data.result?.theme?.errors ?? [])];
    if (theme === 'hyva') {
        test.skip(errors.some(error => error.includes(`Theme "${HYVA_THEME}" not found`)), `Hyvä theme "${HYVA_THEME}" is not installed`);
    }

    if (errors.length > 0) {
        throw new Error('Configure error: ' + errors.join(', '));
    }
}

/**
 * Create a new customer without addresses and log in as that customer
 *
 * LokiCheckout_FunctionalTests (SaveAddresses) gives every new customer an address per development profile, so these
 * are removed first: Otherwise the addresses of a test end up on another page of the additional addresses grid
 */
export async function loginNewCustomer(page: Page, theme: Theme): Promise<string> {
    const email = uniqueEmail();
    await configureStore(page, theme, {
        customer: {
            email,
            password: CUSTOMER_PASSWORD,
            firstname: 'Playwright',
            lastname: 'Tester',
            login: true,
        },
    });

    removeAllAddresses(email);

    return email;
}

export async function gotoAddressBook(page: Page) {
    await page.goto('/customer/address/');
}

export const defaultBillingBox = (page: Page): Locator => page.locator('.box-address-billing');

export const defaultShippingBox = (page: Page): Locator => page.locator('.box-address-shipping');

export const additionalAddresses = (page: Page): Locator => page.locator('.block-addresses-list');

/**
 * Scenarios shared by the Luma and Hyva spec files, because both themes use the same CSS classes in the address book
 */
export function defineAddressBookTests(theme: Theme) {
    test('shows an address added with default flags as default billing and shipping address', async function ({page}) {
        const email = await loginNewCustomer(page, theme);
        addAddress(email, texasAddress, ['default-billing', 'default-shipping']);

        await gotoAddressBook(page);

        for (const box of [defaultBillingBox(page), defaultShippingBox(page)]) {
            await expect(box).toContainText('1 Congress Avenue');
            await expect(box).toContainText('Austin');
            await expect(box).toContainText('Texas');
        }
        await expect(additionalAddresses(page)).toContainText('You have no other address entries in your address book.');
    });

    test('shows an address added without default flags and without region as additional address', async function ({page}) {
        const email = await loginNewCustomer(page, theme);
        addAddress(email, texasAddress, ['default-billing', 'default-shipping']);
        addAddress(email, dutchAddress);

        await gotoAddressBook(page);

        await expect(defaultBillingBox(page)).toContainText('1 Congress Avenue');
        await expect(defaultShippingBox(page)).toContainText('1 Congress Avenue');
        await expect(defaultBillingBox(page)).not.toContainText('Dorpsstraat 5');
        await expect(defaultShippingBox(page)).not.toContainText('Dorpsstraat 5');

        await expect(additionalAddresses(page)).toContainText('Dorpsstraat 5');
        await expect(additionalAddresses(page)).toContainText('Utrecht');
    });

    test('no longer shows an address removed by ID', async function ({page}) {
        const email = await loginNewCustomer(page, theme);
        addAddress(email, texasAddress, ['default-billing', 'default-shipping']);
        const addressId = addAddress(email, dutchAddress);

        await gotoAddressBook(page);
        await expect(additionalAddresses(page)).toContainText('Dorpsstraat 5');

        removeAddress(addressId);

        await gotoAddressBook(page);
        await expect(defaultBillingBox(page)).toContainText('1 Congress Avenue');
        await expect(additionalAddresses(page)).not.toContainText('Dorpsstraat 5');
        await expect(additionalAddresses(page)).toContainText('You have no other address entries in your address book.');
    });

    test('redirects to the new address form after removing all addresses', async function ({page}) {
        const email = await loginNewCustomer(page, theme);
        addAddress(email, texasAddress, ['default-billing', 'default-shipping']);
        addAddress(email, dutchAddress);

        await gotoAddressBook(page);
        await expect(additionalAddresses(page)).toContainText('Dorpsstraat 5');

        const output = removeAllAddresses(email);
        expect(output).toMatch(/Successfully removed 2 address\(es\) for customer ID \d+\./);

        await gotoAddressBook(page);
        await expect(page).toHaveURL(/\/customer\/address\/new\//);
        await expect(page.locator('body')).not.toContainText('1 Congress Avenue');
    });
}
