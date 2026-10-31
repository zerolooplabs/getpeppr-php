<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Contract\Support;

use Einvoicing\Identifier;
use Einvoicing\Invoice as EInvoice;
use Einvoicing\InvoiceLine as EInvoiceLine;
use Einvoicing\Party as EParty;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\UblWriter;
use NumNum\UBL;

/**
 * Fictitious test invoices produced by the two PHP libraries this package
 * complements. Not subject to VAT (category O), like the sandbox account.
 * The recipient is the Belgian administration's Peppol TEST participant.
 */
final class UblFactory
{
    public const RECIPIENT_SCHEME = '9925';
    public const RECIPIENT_ID = 'BE0314595348';
    public const RECIPIENT = self::RECIPIENT_SCHEME . ':' . self::RECIPIENT_ID;

    private const CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
    private const PROFILE = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    /** num-num/ubl-invoice. `$withBuyerReference = false` violates PEPPOL-EN16931-R003 on purpose. */
    public static function numNum(string $number, string $supplierScheme, string $supplierId, string $supplierName, bool $withBuyerReference = true): string
    {
        $taxScheme = (new UBL\TaxScheme())->setId('VAT');
        $country = (new UBL\Country())->setIdentificationCode('BE');

        $supplier = (new UBL\Party())
            ->setName($supplierName)
            ->setEndpointId($supplierId, $supplierScheme)
            ->setPartyIdentificationId($supplierId)
            ->setPartyIdentificationSchemeId(self::icdOrNull($supplierScheme))
            ->setPostalAddress((new UBL\Address())->setStreetName('1 Test Street')->setCityName('Brussels')->setPostalZone('1000')->setCountry($country))
            ->setLegalEntity((new UBL\LegalEntity())->setRegistrationName($supplierName));

        $customer = (new UBL\Party())
            ->setName('SPF Economie (TEST)')
            ->setEndpointId(self::RECIPIENT_ID, self::RECIPIENT_SCHEME)
            ->setPostalAddress((new UBL\Address())->setStreetName('Rue du Progres 50')->setCityName('Brussels')->setPostalZone('1210')->setCountry($country))
            ->setLegalEntity((new UBL\LegalEntity())->setRegistrationName('SPF Economie (TEST)'));

        $category = (new UBL\TaxCategory())
            ->setId('O')
            ->setTaxScheme($taxScheme)
            ->setTaxExemptionReason('Not subject to VAT');

        $line = (new UBL\InvoiceLine())
            ->setId('1')
            ->setInvoicedQuantity(1)
            ->setLineExtensionAmount(100)
            ->setItem((new UBL\Item())->setName('getpeppr-php contract test')->setClassifiedTaxCategory(
                (new UBL\ClassifiedTaxCategory())->setId('O')->setTaxScheme($taxScheme),
            ))
            ->setPrice((new UBL\Price())->setPriceAmount(100)->setBaseQuantity(1)->setUnitCode(UBL\UnitCode::UNIT));

        $invoice = (new UBL\Invoice())
            ->setCustomizationId(self::CUSTOMIZATION)
            ->setProfileId(self::PROFILE)
            ->setId($number)
            ->setIssueDate(new \DateTime('today'))
            ->setDueDate(new \DateTime('+30 days'))
            ->setDocumentCurrencyCode('EUR')
            ->setAccountingSupplierParty((new UBL\AccountingParty())->setParty($supplier))
            ->setAccountingCustomerParty((new UBL\AccountingParty())->setParty($customer))
            ->setInvoiceLines([$line])
            ->setTaxTotal((new UBL\TaxTotal())->setTaxAmount(0)->addTaxSubTotal(
                (new UBL\TaxSubTotal())->setTaxableAmount(100)->setTaxAmount(0)->setTaxCategory($category),
            ))
            ->setLegalMonetaryTotal((new UBL\LegalMonetaryTotal())
                ->setLineExtensionAmount(100)
                ->setTaxExclusiveAmount(100)
                ->setTaxInclusiveAmount(100)
                ->setPayableAmount(100));
        if ($withBuyerReference) {
            $invoice->setBuyerReference('GETPEPPR-PHP-TEST');
        }

        $xml = (new UBL\Generator())->invoice($invoice, 'EUR');
        if (!is_string($xml)) {
            throw new \RuntimeException('num-num/ubl-invoice produced no XML');
        }

        return $xml;
    }

    /** josemmo/einvoicing. */
    public static function josemmo(string $number, string $supplierScheme, string $supplierId, string $supplierName): string
    {
        $invoice = new EInvoice(Peppol::class);
        $invoice->setNumber($number)
            ->setIssueDate(new \DateTime('today'))
            ->setDueDate(new \DateTime('+30 days'))
            ->setCurrency('EUR')
            ->setBuyerReference('GETPEPPR-PHP-TEST');

        $seller = new EParty();
        $seller->setElectronicAddress(new Identifier($supplierId, $supplierScheme))
            ->addIdentifier(new Identifier($supplierId, self::icdOrNull($supplierScheme)))
            ->setName($supplierName)
            ->setAddress(['1 Test Street'])
            ->setCity('Brussels')
            ->setPostalCode('1000')
            ->setCountry('BE');
        $invoice->setSeller($seller);

        $buyer = new EParty();
        $buyer->setElectronicAddress(new Identifier(self::RECIPIENT_ID, self::RECIPIENT_SCHEME))
            ->setName('SPF Economie (TEST)')
            ->setAddress(['Rue du Progres 50'])
            ->setCity('Brussels')
            ->setPostalCode('1210')
            ->setCountry('BE');
        $invoice->setBuyer($buyer);

        $line = new EInvoiceLine();
        $line->setName('getpeppr-php contract test')
            ->setPrice(100)
            ->setQuantity(1)
            ->setVatCategory('O')
            ->setVatRate(null)
            ->setVatExemptionReason('Not subject to VAT');
        $invoice->addLine($line);

        return (new UblWriter())->export($invoice);
    }

    /**
     * A party identifier's scheme must come from the ISO 6523 ICD list (BR-CL-10).
     * 99xx codes exist only as electronic address (EAS) schemes, so they are left out.
     */
    private static function icdOrNull(string $scheme): ?string
    {
        return str_starts_with($scheme, '99') ? null : $scheme;
    }
}
