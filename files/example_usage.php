<?php

declare(strict_types=1);

require_once __DIR__ . '/ZatcaInvoiceBuilder.php';
require_once __DIR__ . '/ZatcaSigner.php';

use Zatca\ZatcaInvoiceBuilder;
use Zatca\ZatcaSigner;

// ---------------------------------------------------------------------
// 1) Certificate + private key from the ZATCA onboarding process.
//    These come out of the CSR / CSID flow:
//      - Compliance CSID: used while you run the compliance checks
//        (CSIDs from the "compliance" endpoint), good for ~ a few days.
//      - Production CSID: swapped in once compliance checks pass, used
//        for real invoices.
//    Store the private key like any other production secret (vault /
//    HSM / encrypted at rest) -- never commit it to source control.
// ---------------------------------------------------------------------
$certPem = file_get_contents(__DIR__ . '/certs/production_csid_cert.pem');
$privateKeyPem = file_get_contents(__DIR__ . '/certs/production_csid_private.pem');

if ($certPem === false || $privateKeyPem === false) {
    fwrite(STDERR, "Could not read certificate/key files. Update the paths above.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// 2) Build the invoice with multiple line items.
// ---------------------------------------------------------------------
$invoiceId = 'INV-2026-000123';
$uuid = bin2hex(random_bytes(16)); // replace with a proper RFC4122 v4 UUID generator
$icv = 123;                         // your persisted, strictly-incrementing counter
$previousInvoiceHash = null;        // null => treated as the first invoice in the chain

$builder = new ZatcaInvoiceBuilder([
    'id' => $invoiceId,
    'uuid' => $uuid,
    'issueDate' => gmdate('Y-m-d'),
    'issueTime' => gmdate('H:i:s'),
    'invoiceTypeCode' => '388',
    'invoiceSubtype' => 'standard', // 'standard' (B2B, requires clearance) or 'simplified' (B2C, reporting)
    'currency' => 'SAR',
    'icv' => $icv,
    'previousInvoiceHash' => $previousInvoiceHash,
    'seller' => [
        'name' => 'Example Trading Co',
        'legalName' => 'Example Trading Co LLC',
        'vatNumber' => '300000000000003',
        'street' => 'King Fahd Road',
        'buildingNumber' => '1234',
        'district' => 'Al Olaya',
        'city' => 'Riyadh',
        'postalZone' => '12211',
        'countryCode' => 'SA',
    ],
    'buyer' => [
        'name' => 'Acme Buyer Co',
        'legalName' => 'Acme Buyer Co LLC',
        'vatNumber' => '300000000000099',
        'street' => 'Olaya Street',
        'buildingNumber' => '5678',
        'district' => 'Al Murabba',
        'city' => 'Riyadh',
        'postalZone' => '11564',
        'countryCode' => 'SA',
    ],
    'payment' => [
        'meansCode' => '10', // 10 = cash, 30 = credit, 42 = bank transfer
    ],
]);

// Multiple line items:
$builder
    ->addLine([
        'id' => '1',
        'name' => 'Consulting services - June',
        'quantity' => 10,
        'unitCode' => 'HUR', // hour
        'unitPrice' => 250.00,
        'taxPercent' => 15.00,
    ])
    ->addLine([
        'id' => '2',
        'name' => 'Laptop stand',
        'quantity' => 3,
        'unitCode' => 'PCE', // piece
        'unitPrice' => 80.00,
        'taxPercent' => 15.00,
        'discount' => 15.00, // 15 SAR off this line
    ])
    ->addLine([
        'id' => '3',
        'name' => 'Exported training manual (zero-rated)',
        'quantity' => 1,
        'unitCode' => 'PCE',
        'unitPrice' => 500.00,
        'taxPercent' => 0.00,
        'taxCategory' => 'Z',
    ]);

$unsignedXml = $builder->build();

// ---------------------------------------------------------------------
// 3) Sign it.
// ---------------------------------------------------------------------
// You need the invoice totals for the QR code -- compute the same way
// the builder did, or read LegalMonetaryTotal back out of $unsignedXml.
// Recomputed here directly for clarity:
$lines = [
    ['qty' => 10, 'price' => 250.00, 'tax' => 15.00, 'discount' => 0],
    ['qty' => 3, 'price' => 80.00, 'tax' => 15.00, 'discount' => 15.00],
    ['qty' => 1, 'price' => 500.00, 'tax' => 0.00, 'discount' => 0],
];
$taxExclusiveTotal = 0.0;
$vatTotal = 0.0;
foreach ($lines as $l) {
    $ext = ($l['qty'] * $l['price']) - $l['discount'];
    $taxExclusiveTotal += $ext;
    $vatTotal += $ext * ($l['tax'] / 100);
}
$grandTotal = round($taxExclusiveTotal + $vatTotal, 2);

$signer = new ZatcaSigner();
$result = $signer->sign($unsignedXml, $certPem, $privateKeyPem, [
    'sellerName' => 'Example Trading Co',
    'vatNumber' => '300000000000003',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'total' => number_format($grandTotal, 2, '.', ''),
    'vatTotal' => number_format($vatTotal, 2, '.', ''),
]);

// ---------------------------------------------------------------------
// 4) Persist + use the results.
// ---------------------------------------------------------------------
file_put_contents(__DIR__ . '/output/' . $invoiceId . '.xml', $result['xml']);

echo "Signed invoice written to output/{$invoiceId}.xml\n";
echo "Invoice hash (save this as the PIH for the *next* invoice): {$result['invoiceHash']}\n";
echo "QR base64 length: " . strlen($result['qr']) . " chars\n";

// At this point, $result['xml'] is what you submit to ZATCA's
// Clearance API (standard invoices, before sharing with the buyer) or
// Reporting API (simplified invoices, within 24h of issuance) -- that
// HTTP call is a separate step from generation/signing and isn't
// included here.
