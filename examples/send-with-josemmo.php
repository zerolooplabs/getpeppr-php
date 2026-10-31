<?php

/*
 * Send a test invoice built with josemmo/einvoicing.
 *
 *   composer require getpeppr/getpeppr-php josemmo/einvoicing guzzlehttp/guzzle
 *   GETPEPPR_API_KEY=sk_sandbox_… GETPEPPR_SUPPLIER=0208:0123456789 php send-with-josemmo.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\UblWriter;
use Getpeppr\Client;
use Getpeppr\Exception\GetpepprException;

[$supplierScheme, $supplierId] = explode(':', getenv('GETPEPPR_SUPPLIER') ?: 'scheme:identifier', 2);
$recipient = getenv('GETPEPPR_RECIPIENT') ?: '9925:BE0314595348';
[$recipientScheme, $recipientId] = explode(':', $recipient, 2);
$number = 'EXAMPLE-' . date('YmdHis');

$invoice = new Invoice(Peppol::class);
$invoice->setNumber($number)
    ->setIssueDate(new DateTime('today'))
    ->setDueDate(new DateTime('+30 days'))
    ->setBuyerReference('EXAMPLE');

$seller = new Party();
$seller->setElectronicAddress(new Identifier($supplierId, $supplierScheme))
    ->addIdentifier(new Identifier($supplierId))
    ->setName(getenv('GETPEPPR_SUPPLIER_NAME') ?: 'My Company')
    ->setAddress(['1 Test Street'])
    ->setCity('Brussels')
    ->setPostalCode('1000')
    ->setCountry('BE');
$invoice->setSeller($seller);

$buyer = new Party();
$buyer->setElectronicAddress(new Identifier($recipientId, $recipientScheme))
    ->setName('SPF Economie (TEST)')
    ->setAddress(['Rue du Progres 50'])
    ->setCity('Brussels')
    ->setPostalCode('1210')
    ->setCountry('BE');
$invoice->setBuyer($buyer);

$line = new InvoiceLine();
$line->setName('Consulting')
    ->setPrice(100)
    ->setQuantity(1)
    ->setVatCategory('O')
    ->setVatRate(null)
    ->setVatExemptionReason('Not subject to VAT');
$invoice->addLine($line);

$xml = (new UblWriter())->export($invoice);

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
