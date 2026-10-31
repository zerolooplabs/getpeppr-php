<?php

/*
 * Send a test invoice built with num-num/ubl-invoice, then read its status.
 *
 *   composer require getpeppr/getpeppr-php num-num/ubl-invoice guzzlehttp/guzzle
 *   GETPEPPR_API_KEY=sk_sandbox_… GETPEPPR_SUPPLIER=0208:0123456789 php send-with-num-num.php
 *
 * GETPEPPR_SUPPLIER is your own Peppol id (scheme:identifier), the one your
 * getpeppr account is registered with. The default recipient is the Belgian
 * administration's Peppol TEST participant.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Getpeppr\Client;
use Getpeppr\Exception\GetpepprException;
use NumNum\UBL;

[$supplierScheme, $supplierId] = explode(':', getenv('GETPEPPR_SUPPLIER') ?: 'scheme:identifier', 2);
$supplierName = getenv('GETPEPPR_SUPPLIER_NAME') ?: 'My Company';
$recipient = getenv('GETPEPPR_RECIPIENT') ?: '9925:BE0314595348';
[$recipientScheme, $recipientId] = explode(':', $recipient, 2);
$number = 'EXAMPLE-' . date('YmdHis');

$taxScheme = (new UBL\TaxScheme())->setId('VAT');
$country = (new UBL\Country())->setIdentificationCode('BE');

$supplier = (new UBL\Party())
    ->setName($supplierName)
    ->setEndpointId($supplierId, $supplierScheme)
    ->setPartyIdentificationId($supplierId)
    ->setPostalAddress((new UBL\Address())->setStreetName('1 Test Street')->setCityName('Brussels')->setPostalZone('1000')->setCountry($country))
    ->setLegalEntity((new UBL\LegalEntity())->setRegistrationName($supplierName));

$customer = (new UBL\Party())
    ->setName('SPF Economie (TEST)')
    ->setEndpointId($recipientId, $recipientScheme)
    ->setPostalAddress((new UBL\Address())->setStreetName('Rue du Progres 50')->setCityName('Brussels')->setPostalZone('1210')->setCountry($country))
    ->setLegalEntity((new UBL\LegalEntity())->setRegistrationName('SPF Economie (TEST)'));

$notSubjectToVat = (new UBL\TaxCategory())->setId('O')->setTaxScheme($taxScheme)->setTaxExemptionReason('Not subject to VAT');

$invoice = (new UBL\Invoice())
    ->setCustomizationId('urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0')
    ->setProfileId('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0')
    ->setId($number)
    ->setIssueDate(new DateTime('today'))
    ->setDueDate(new DateTime('+30 days'))
    ->setDocumentCurrencyCode('EUR')
    ->setBuyerReference('EXAMPLE')
    ->setAccountingSupplierParty((new UBL\AccountingParty())->setParty($supplier))
    ->setAccountingCustomerParty((new UBL\AccountingParty())->setParty($customer))
    ->setInvoiceLines([(new UBL\InvoiceLine())
        ->setId('1')
        ->setInvoicedQuantity(1)
        ->setLineExtensionAmount(100)
        ->setItem((new UBL\Item())->setName('Consulting')->setClassifiedTaxCategory((new UBL\ClassifiedTaxCategory())->setId('O')->setTaxScheme($taxScheme)))
        ->setPrice((new UBL\Price())->setPriceAmount(100)->setBaseQuantity(1)->setUnitCode(UBL\UnitCode::UNIT))])
    ->setTaxTotal((new UBL\TaxTotal())->setTaxAmount(0)->addTaxSubTotal((new UBL\TaxSubTotal())->setTaxableAmount(100)->setTaxAmount(0)->setTaxCategory($notSubjectToVat)))
    ->setLegalMonetaryTotal((new UBL\LegalMonetaryTotal())->setLineExtensionAmount(100)->setTaxExclusiveAmount(100)->setTaxInclusiveAmount(100)->setPayableAmount(100));

$xml = (new UBL\Generator())->invoice($invoice, 'EUR');

// Create the operation id once and store it with the invoice: reuse it only to resend this exact request.
$operationId = bin2hex(random_bytes(16));

$getpeppr = new Client((string) getenv('GETPEPPR_API_KEY'));

try {
    $sent = $getpeppr->importInvoice($xml, $number . '.xml', $recipient, idempotencyKey: $operationId);
} catch (GetpepprException $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Sent {$number}: document {$sent->providerDocumentId}, status {$sent->status}" . PHP_EOL;

for ($i = 0; $i < 12; $i++) {
    sleep(10);
    $document = $getpeppr->getInvoice((string) $sent->providerDocumentId);
    echo "Status: {$document->status}" . PHP_EOL;
    if ($document->status !== 'submitted') {
        break;
    }
}
