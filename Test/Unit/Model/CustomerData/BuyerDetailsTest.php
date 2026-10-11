<?php
/**
 * The quote form's prefill for a signed-in customer: a customer-data section with the account's
 * name, email and default billing telephone; nothing for a guest; and registered as a section
 * (private content), never baked into the full-page-cached quote form.
 *
 * Mutations (applied, red, restored):
 *   B1  read the telephone from the first address, not the default billing one ->
 *       testTheTelephoneIsTheDefaultBillingAddresssOne
 *   B2  drop the guest check -> testAGuestGetsNothing
 *   B3  rename the frontend section registration -> testItIsRegisteredAsAStorefrontSection
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\CustomerData;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\CustomerData\BuyerDetails;

/**
 * @covers \TackQuote\Quotes\Model\CustomerData\BuyerDetails
 */
class BuyerDetailsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /**
     * @param int $customerId
     * @param string|null $defaultBilling
     * @return BuyerDetails
     */
    private function section(int $customerId, ?string $defaultBilling = '12'): BuyerDetails
    {
        $addresses = [];
        foreach (['11' => '555-0100', '12' => '555-0199'] as $id => $phone) {
            $address = $this->createMock(AddressInterface::class);
            $address->method('getId')->willReturn($id);
            $address->method('getTelephone')->willReturn($phone);
            $addresses[] = $address;
        }
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstname')->willReturn('Pat');
        $customer->method('getLastname')->willReturn('Buyer');
        $customer->method('getEmail')->willReturn('buyer@example.test');
        $customer->method('getDefaultBilling')->willReturn($defaultBilling);
        $customer->method('getAddresses')->willReturn($addresses);

        $current = $this->createMock(CurrentCustomer::class);
        $current->method('getCustomerId')->willReturn($customerId);
        $current->method('getCustomer')->willReturn($customer);

        return new BuyerDetails($current);
    }

    public function testTheTelephoneIsTheDefaultBillingAddresssOne(): void
    {
        self::assertSame(
            ['firstname' => 'Pat', 'lastname' => 'Buyer', 'email' => 'buyer@example.test', 'telephone' => '555-0199'],
            $this->section(7)->getSectionData()
        );
    }

    public function testNoDefaultBillingAddressMeansNoTelephone(): void
    {
        self::assertSame('', $this->section(7, null)->getSectionData()['telephone']);
    }

    public function testAGuestGetsNothing(): void
    {
        self::assertSame([], $this->section(0)->getSectionData());
    }

    public function testItIsRegisteredAsAStorefrontSection(): void
    {
        $frontend = simplexml_load_file(self::ROOT . '/etc/frontend/di.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $frontend);
        $items = $frontend->xpath(
            '//type[@name="Magento\Customer\CustomerData\SectionPoolInterface"]'
            . '/arguments/argument[@name="sectionSourceMap"]/item[@name="' . BuyerDetails::SECTION . '"]'
        ) ?: [];
        self::assertCount(1, $items);
        self::assertSame(BuyerDetails::class, trim((string) $items[0]));

        // Not in the global file, where Magento_Customer's frontend map would replace it whole.
        self::assertStringNotContainsString(
            'SectionPoolInterface',
            (string) file_get_contents(self::ROOT . '/etc/di.xml')
        );

        // The script reads the same name (buyer-prefill.js, which quote-app.js loads).
        self::assertStringContainsString(
            "SECTION = '" . BuyerDetails::SECTION . "'",
            (string) file_get_contents(self::ROOT . '/view/frontend/web/js/buyer-prefill.js')
        );
        self::assertStringContainsString(
            "'TackQuote_Quotes/js/buyer-prefill'",
            (string) file_get_contents(self::ROOT . '/view/frontend/web/js/quote-app.js')
        );
    }

    public function testTheQuoteFormTemplateCarriesNoCustomerData(): void
    {
        // The form is full-page cached: no session or customer read may reach its template.
        $template = (string) file_get_contents(self::ROOT . '/view/frontend/templates/quote-list.phtml');
        self::assertStringNotContainsString('getCustomer', $template);
        self::assertStringNotContainsString('getEmail', $template);
    }
}
