<?php

// Report all PHP errors
error_reporting(E_ALL);

// Force errors to display on screen
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// ==============================================================================
// 1. INPUT DATA STRUCTURE
// ==============================================================================

$invoicehash = 'V4U5qlZ3yXQ/Si1AC/R8SLc3F+iNy27wdVe8IWRqFAQ=';
$uuid='8d487816-70b8-4ade-a618-9d620b73814a';
$pih="NWZIY2ViNjZmYmY1OWM4N2U0ODhkYTU4ZjFiNTkyZmI4ZDAyM2I4OWAyNDhkYWU4ZTQwODEyOThlY2E4MjI2Mg==";
$seller_private_key='MEUCIBxyR8rc4K8728wdSF4XSDqPs+rIL+3TFh9m+aNxQPtSAiEA6cHapItvp13yMSu66NbOg2CpomHwUSnYJ9h6uGQ65aY=';
$csid='MIID3jCCA4SgAwIBAgITEQAAOAPF90Ajs/xcXwABAAA4AzAKBggqhkjOPQQDAjBiMRUwEwYKCZImiZPyLGQBGRYFbG9jYWwxEzARBgoJkiaJk/IsZAEZFgNnb3YxFzAVBgoJkiaJk/IsZAEZFgdleHRnYXp0MRswGQYDVQQDExJQUlpFSU5WT0lDRVNDQTQtQ0EwHhcNMjQwMTExMDkxOTMwWhcNMjkwMTA5MDkxOTMwWjB1MQswCQYDVQQGEwJTQTEmMCQGA1UEChMdTWF4aW11bSBTcGVlZCBUZWNoIFN1cHBseSBMVEQxFjAUBgNVBAsTDVJpeWFkaCBCcmFuY2gxJjAkBgNVBAMTHVRTVC04ODY0MzExNDUtMzk5OTk5OTk5OTAwMDAzMFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEoWCKa0Sa9FIErTOv0uAkC1VIKXxU9nPpx2vlf4yhMejy8c02XJblDq7tPydo8mq0ahOMmNo8gwni7Xt1KT9UeKOCAgcwggIDMIGtBgNVHREEgaUwgaKkgZ8wgZwxOzA5BgNVBAQMMjEtVFNUfDItVFNUfDMtZWQyMmYxZDgtZTZhMi0xMTE4LTliNTgtZDlhOGYxMWU0NDVmMR8wHQYKCZImiZPyLGQBAQwPMzk5OTk5OTk5OTAwMDAzMQ0wCwYDVQQMDAQxMTAwMREwDwYDVQQaDAhSUlJEMjkyOTEaMBgGA1UEDwwRU3VwcGx5IGFjdGl2aXRpZXMwHQYDVR0OBBYEFEX+YvmmtnYoDf9BGbKo7ocTKYK1MB8GA1UdIwQYMBaAFJvKqqLtmqwskIFzVvpP2PxT+9NnMHsGCCsGAQUFBwEBBG8wbTBrBggrBgEFBQcwAoZfaHR0cDovL2FpYTQuemF0Y2EuZ292LnNhL0NlcnRFbnJvbGwvUFJaRUludm9pY2VTQ0E0LmV4dGdhenQuZ292LmxvY2FsX1BSWkVJTlZPSUNFU0NBNC1DQSgxKS5jcnQwDgYDVR0PAQH/BAQDAgeAMDwGCSsGAQQBgjcVBwQvMC0GJSsGAQQBgjcVCIGGqB2E0PsShu2dJIfO+xnTwFVmh/qlZYXZhD4CAWQCARIwHQYDVR0lBBYwFAYIKwYBBQUHAwMGCCsGAQUFBwMCMCcGCSsGAQQBgjcVCgQaMBgwCgYIKwYBBQUHAwMwCgYIKwYBBQUHAwIwCgYIKoZIzj0EAwIDSAAwRQIhALE/ichmnWXCUKUbca3yci8oqwaLvFdHVjQrveI9uqAbAiA9hC4M8jgMBADPSzmd2uiPJA6gKR3LE03U75eqbC/rXA==';

// Load your CSID PEM certificate
$certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($csid, 64, "\n") . "-----END CERTIFICATE-----";
$parsed = openssl_x509_parse($certPem);

// 1. Get X509IssuerName formatted for XML
// OpenSSL returns an array like ['CN' => 'PRZEINVOICESCA4-CA', 'DC' => ['extgazt', 'gov', 'local']]
$issuerParts = [];
foreach ($parsed['issuer'] as $key => $value) {
    if (is_array($value)) {
        foreach ($value as $subValue) {
            $issuerParts[] = "$key=$subValue";
        }
    } else {
        $issuerParts[] = "$key=$value";
    }
}
$x509IssuerName = implode(', ', $issuerParts);
// Result: "CN=PRZEINVOICESCA4-CA, DC=extgazt, DC=gov, DC=local"

// 2. Get X509SerialNumber in Base-10 Decimal
$issuerSerialHex = $parsed['serialNumberHex'];
$x509SerialNumber = hexToDecPure($issuerSerialHex);
// echo "x509SerialNumber: $x509SerialNumber\n";exit;
// Result: "379112742831380471835263969587287663520528387"

$cert_digest_value=generateZatcaCertDigest($csid);
// echo "cert_digest_value: $cert_digest_value\n";exit;



$input = [
    // Basic Meta
    'currency'        => 'SAR',
    'vat_percent'     => 15.00,
    'profile_id'      => 'reporting:1.0',
    'invoice_id'      => 'SME00023',
    'uuid'            => $uuid,
    'issue_date'      => '2022-09-07',
    'issue_time'      => '12:21:28',
    'invoice_type'    => '388',      // Standard Tax Invoice / Simplified Tax Invoice
    'subtype_name'    => '0100000',  // ZATCA Subtype Flags (e.g., B2B vs B2C, Exports, etc.)

    // Additional Reference Identifiers (ICV, PIH, QR)
    'icv'             => '23',
    'pih'             => $pih,
    'qr_code'         => 'AW/YtNix2YPYqSDYqtmI2LHZitivINin2YTYqtmD2YbZiNmE2YjYrNmK2Kcg2KjYo9mC2LXZiSDYs9ix2LnYqSDYp9mE2YXYrdiv2YjYr9ipIHwgTWF4aW11bSBTcGVlZCBUZWNoIFN1cHBseSBMVEQCDzM5OTk5OTk5OTkwMDAwMwMTMjAyMi0wOS0wN1QxMjoyMToyOAQENC42MAUDMC42BixmKzBXQ3FuUGtJbkkrZUw5RzNMQXJ5MTJmVFBmK3RvQzlVWDA3RjRmSStzPQdgTUVVQ0lCeHlSOHJjNEs4NzI4d2RTRjRYU0RxUHMrcklMKzNURmg5bSthTnhRUHRTQWlFQTZjSGFwSXR2cDEzeU1TdTY2TmJPZzJDcG9tSHdVU25ZSjloNnVHUTY1YVk9CFgwVjAQBgcqhkjOPQIBBgUrgQQACgNCAAShYIprRJr0UgStM6/S4CQLVUgpfFT2c+nHa+V/jKEx6PLxzTZcluUOru0/J2jyarRqE4yY2jyDCeLte3UpP1R4',

    // Supplier Info
    'supplier' => [
        'crn'            => '1010010000',
        'vat_number'     => '399999999900003',
        'name'           => 'شركة توريد التكنولوجيا بأقصى سرعة المحدودة | Maximum Speed Tech Supply LTD',
        'street'         => 'الامير سلطان | Prince Sultan',
        'building_no'    => '2322',
        'subdivision'    => 'المربع | Al-Murabba',
        'city'           => 'الرياض | Riyadh',
        'postal_zone'    => '23333',
        'country_code'   => 'SA',
    ],

    // Customer Info
    'customer' => [
        'vat_number'     => '399999999800003',
        'name'           => 'شركة نماذج فاتورة المحدودة | Fatoora Samples LTD',
        'street'         => 'صلاح الدين | Salah Al-Din',
        'building_no'    => '1111',
        'subdivision'    => 'المروج | Al-Murooj',
        'city'           => 'الرياض | Riyadh',
        'postal_zone'    => '12222',
        'country_code'   => 'SA',
    ],

    // Logistics & Payment
    'delivery_date'     => '2022-09-07',
    'payment_means_code'=> '10', // 10 = Cash, 30 = Credit Transfer, 42 = Payment to Bank Account, etc.
    'discount_amount'   => 0.00,

    // Line Items
    'line_items' => [
        [
            'id'        => '1',
            'name'      => 'قلم رصاص',
            'quantity'  => 2.000000,
            'unit_code' => 'PCE',
            'price'     => 2.00,
        ],
    ],

    // Cryptographic Elements (Dynamic or Hardcoded Placeholders)
    'crypto' => [
        'digest_value_1'      => $invoicehash,
        'digest_value_2'      => 'ODQwNTg1NTBhMjMzM2YxY2ZkZjVkYzdlNTZiZjY0ODJjMjNkYWI4MTUzNjdmNDVjMjAwZTBjODc2YTNhMWQ1Ng==',
        'signature_value'     => $seller_private_key,
        'x509_certificate'    => $csid,
        'signing_time'        => date('Y-m-d\TH:i:s'),
        'cert_digest_value'   => $cert_digest_value,
        'x509_issuer_name'    => $x509IssuerName,
        'x509_serial_number'  => $x509SerialNumber,
    ]
];

// ==============================================================================
// 2. DYNAMIC CALCULATIONS
// ==============================================================================

$lineExtensionTotal = 0.00;
foreach ($input['line_items'] as $item) {
    $lineExtensionTotal += ($item['quantity'] * $item['price']);
}

$vatRate            = $input['vat_percent'] / 100;
$taxAmount          = round($lineExtensionTotal * $vatRate, 2);
$taxInclusiveAmount = $lineExtensionTotal + $taxAmount - $input['discount_amount'];

// ==============================================================================
// 3. XML GENERATION (DOMDocument)
// ==============================================================================

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;

// Root Element
$invoice = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
$invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
$invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
$invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
$dom->appendChild($invoice);

// --- UBL Extensions (Cryptographic Section) ---
$ublExtensions = $dom->createElement('ext:UBLExtensions');
$ublExtension  = $dom->createElement('ext:UBLExtension');
$ublExtension->appendChild($dom->createElement('ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));

$extContent = $dom->createElement('ext:ExtensionContent');
$ublDocSigs = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2', 'sig:UBLDocumentSignatures');
$ublDocSigs->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2');
$ublDocSigs->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sbc', 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2');

$sigInfo = $dom->createElement('sac:SignatureInformation');
$sigInfo->appendChild($dom->createElement('cbc:ID', 'urn:oasis:names:specification:ubl:signature:1'));
$sigInfo->appendChild($dom->createElement('sbc:ReferencedSignatureID', 'urn:oasis:names:specification:ubl:signature:Invoice'));

// ds:Signature
$dsSignature = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
$dsSignature->setAttribute('Id', 'signature');

$dsSignedInfo = $dom->createElement('ds:SignedInfo');
$c14n = $dom->createElement('ds:CanonicalizationMethod');
$c14n->setAttribute('Algorithm', 'http://www.w3.org/2006/12/xml-c14n11');
$dsSignedInfo->appendChild($c14n);

$sigMethod = $dom->createElement('ds:SignatureMethod');
$sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256');
$dsSignedInfo->appendChild($sigMethod);

// Reference 1 (Invoice Signed Data)
$ref1 = $dom->createElement('ds:Reference');
$ref1->setAttribute('Id', 'invoiceSignedData');
$ref1->setAttribute('URI', '');
$transforms = $dom->createElement('ds:Transforms');
$xpaths = [
    'not(//ancestor-or-self::ext:UBLExtensions)',
    'not(//ancestor-or-self::cac:Signature)',
    "not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])"
];
foreach ($xpaths as $path) {
    $tr = $dom->createElement('ds:Transform');
    $tr->setAttribute('Algorithm', 'http://www.w3.org/TR/1999/REC-xpath-19991116');
    $tr->appendChild($dom->createElement('ds:XPath', $path));
    $transforms->appendChild($tr);
}
$trC14n = $dom->createElement('ds:Transform');
$trC14n->setAttribute('Algorithm', 'http://www.w3.org/2006/12/xml-c14n11');
$transforms->appendChild($trC14n);
$ref1->appendChild($transforms);

$ref1->appendChild($dom->createElement('ds:DigestMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
$ref1->appendChild($dom->createElement('ds:DigestValue', $input['crypto']['digest_value_1']));
$dsSignedInfo->appendChild($ref1);

// Reference 2 (Xades Signed Properties)
$ref2 = $dom->createElement('ds:Reference');
$ref2->setAttribute('Type', 'http://www.w3.org/2000/09/xmldsig#SignatureProperties');
$ref2->setAttribute('URI', '#xadesSignedProperties');
$ref2->appendChild($dom->createElement('ds:DigestMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
$ref2->appendChild($dom->createElement('ds:DigestValue', $input['crypto']['digest_value_2']));
$dsSignedInfo->appendChild($ref2);
$dsSignature->appendChild($dsSignedInfo);

// SignatureValue & KeyInfo
$dsSignature->appendChild($dom->createElement('ds:SignatureValue', $input['crypto']['signature_value']));
$x509Data = $dom->createElement('ds:X509Data');
$x509Data->appendChild($dom->createElement('ds:X509Certificate', $input['crypto']['x509_certificate']));
$domKeyInfo = $dom->createElement('ds:KeyInfo');
$domKeyInfo->appendChild($x509Data);
$dsSignature->appendChild($domKeyInfo);

// ds:Object -> QualifyingProperties
$dsObject = $dom->createElement('ds:Object');
$qp = $dom->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:QualifyingProperties');
$qp->setAttribute('Target', 'signature');
$sp = $dom->createElement('xades:SignedProperties');
$sp->setAttribute('Id', 'xadesSignedProperties');
$ssp = $dom->createElement('xades:SignedSignatureProperties');
$ssp->appendChild($dom->createElement('xades:SigningTime', $input['crypto']['signing_time']));

$cert = $dom->createElement('xades:Cert');
$certDigest = $dom->createElement('xades:CertDigest');
$certDigest->appendChild($dom->createElement('ds:DigestMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
$certDigest->appendChild($dom->createElement('ds:DigestValue', $input['crypto']['cert_digest_value']));
$cert->appendChild($certDigest);

$issuerSerial = $dom->createElement('xades:IssuerSerial');
$issuerSerial->appendChild($dom->createElement('ds:X509IssuerName', $input['crypto']['x509_issuer_name']));
$issuerSerial->appendChild($dom->createElement('ds:X509SerialNumber', $input['crypto']['x509_serial_number']));
$cert->appendChild($issuerSerial);

$ssp->appendChild($dom->createElement('xades:SigningCertificate'))->appendChild($cert);
$sp->appendChild($ssp);
$qp->appendChild($sp);
$dsObject->appendChild($qp);
$dsSignature->appendChild($dsObject);

$sigInfo->appendChild($dsSignature);
$ublDocSigs->appendChild($sigInfo);
$extContent->appendChild($ublDocSigs);
$ublExtension->appendChild($extContent);
$ublExtensions->appendChild($ublExtension);
$invoice->appendChild($ublExtensions);

// --- Core Invoice Meta ---
$invoice->appendChild($dom->createElement('cbc:ProfileID', $input['profile_id']));
$invoice->appendChild($dom->createElement('cbc:ID', $input['invoice_id']));
$invoice->appendChild($dom->createElement('cbc:UUID', $input['uuid']));
$invoice->appendChild($dom->createElement('cbc:IssueDate', $input['issue_date']));
$invoice->appendChild($dom->createElement('cbc:IssueTime', $input['issue_time']));

$invoiceTypeCode = $dom->createElement('cbc:InvoiceTypeCode', $input['invoice_type']);
$invoiceTypeCode->setAttribute('name', $input['subtype_name']);
$invoice->appendChild($invoiceTypeCode);

$invoice->appendChild($dom->createElement('cbc:DocumentCurrencyCode', $input['currency']));
$invoice->appendChild($dom->createElement('cbc:TaxCurrencyCode', $input['currency']));

// --- Additional Document References (ICV, PIH, QR) ---
$adrIcv = $dom->createElement('cac:AdditionalDocumentReference');
$adrIcv->appendChild($dom->createElement('cbc:ID', 'ICV'));
$adrIcv->appendChild($dom->createElement('cbc:UUID', $input['icv']));
$invoice->appendChild($adrIcv);

$adrPih = $dom->createElement('cac:AdditionalDocumentReference');
$adrPih->appendChild($dom->createElement('cbc:ID', 'PIH'));
$embedPih = $dom->createElement('cbc:EmbeddedDocumentBinaryObject', $input['pih']);
$embedPih->setAttribute('mimeCode', 'text/plain');
$adrPih->appendChild($dom->createElement('cac:Attachment'))->appendChild($embedPih);
$invoice->appendChild($adrPih);

$adrQr = $dom->createElement('cac:AdditionalDocumentReference');
$adrQr->appendChild($dom->createElement('cbc:ID', 'QR'));
$embedQr = $dom->createElement('cbc:EmbeddedDocumentBinaryObject', $input['qr_code']);
$embedQr->setAttribute('mimeCode', 'text/plain');
$adrQr->appendChild($dom->createElement('cac:Attachment'))->appendChild($embedQr);
$invoice->appendChild($adrQr);

// --- Signature Hook ---
$cacSignature = $dom->createElement('cac:Signature');
$cacSignature->appendChild($dom->createElement('cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice'));
$cacSignature->appendChild($dom->createElement('cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));
$invoice->appendChild($cacSignature);

// --- Supplier Party ---
$supplier = $dom->createElement('cac:AccountingSupplierParty');
$sParty = $dom->createElement('cac:Party');

$sPartyIdent = $dom->createElement('cac:PartyIdentification');
$sPartyIdent->appendChild($dom->createElement('cbc:ID', $input['supplier']['crn']))->setAttribute('schemeID', 'CRN');
$sParty->appendChild($sPartyIdent);

$sAddress = $dom->createElement('cac:PostalAddress');
$sAddress->appendChild($dom->createElement('cbc:StreetName', $input['supplier']['street']));
$sAddress->appendChild($dom->createElement('cbc:BuildingNumber', $input['supplier']['building_no']));
$sAddress->appendChild($dom->createElement('cbc:CitySubdivisionName', $input['supplier']['subdivision']));
$sAddress->appendChild($dom->createElement('cbc:CityName', $input['supplier']['city']));
$sAddress->appendChild($dom->createElement('cbc:PostalZone', $input['supplier']['postal_zone']));
$sAddress->appendChild($dom->createElement('cac:Country'))->appendChild($dom->createElement('cbc:IdentificationCode', $input['supplier']['country_code']));
$sParty->appendChild($sAddress);

$sTaxScheme = $dom->createElement('cac:PartyTaxScheme');
$sTaxScheme->appendChild($dom->createElement('cbc:CompanyID', $input['supplier']['vat_number']));
$sTaxScheme->appendChild($dom->createElement('cac:TaxScheme'))->appendChild($dom->createElement('cbc:ID', 'VAT'));
$sParty->appendChild($sTaxScheme);

$sParty->appendChild($dom->createElement('cac:PartyLegalEntity'))->appendChild($dom->createElement('cbc:RegistrationName', $input['supplier']['name']));
$supplier->appendChild($sParty);
$invoice->appendChild($supplier);

// --- Customer Party ---
$customer = $dom->createElement('cac:AccountingCustomerParty');
$cParty = $dom->createElement('cac:Party');

$cAddress = $dom->createElement('cac:PostalAddress');
$cAddress->appendChild($dom->createElement('cbc:StreetName', $input['customer']['street']));
$cAddress->appendChild($dom->createElement('cbc:BuildingNumber', $input['customer']['building_no']));
$cAddress->appendChild($dom->createElement('cbc:CitySubdivisionName', $input['customer']['subdivision']));
$cAddress->appendChild($dom->createElement('cbc:CityName', $input['customer']['city']));
$cAddress->appendChild($dom->createElement('cbc:PostalZone', $input['customer']['postal_zone']));
$cAddress->appendChild($dom->createElement('cac:Country'))->appendChild($dom->createElement('cbc:IdentificationCode', $input['customer']['country_code']));
$cParty->appendChild($cAddress);

$cTaxScheme = $dom->createElement('cac:PartyTaxScheme');
$cTaxScheme->appendChild($dom->createElement('cbc:CompanyID', $input['customer']['vat_number']));
$cTaxScheme->appendChild($dom->createElement('cac:TaxScheme'))->appendChild($dom->createElement('cbc:ID', 'VAT'));
$cParty->appendChild($cTaxScheme);

$cParty->appendChild($dom->createElement('cac:PartyLegalEntity'))->appendChild($dom->createElement('cbc:RegistrationName', $input['customer']['name']));
$customer->appendChild($cParty);
$invoice->appendChild($customer);

// --- Delivery & Payment ---
$delivery = $dom->createElement('cac:Delivery');
$delivery->appendChild($dom->createElement('cbc:ActualDeliveryDate', $input['delivery_date']));
$invoice->appendChild($delivery);

$payment = $dom->createElement('cac:PaymentMeans');
$payment->appendChild($dom->createElement('cbc:PaymentMeansCode', $input['payment_means_code']));
$invoice->appendChild($payment);

// --- Allowance Charge ---
$allowance = $dom->createElement('cac:AllowanceCharge');
$allowance->appendChild($dom->createElement('cbc:ChargeIndicator', 'false'));
$allowance->appendChild($dom->createElement('cbc:AllowanceChargeReason', 'discount'));
$allowanceAmount = $dom->createElement('cbc:Amount', sprintf("%.2f", $input['discount_amount']));
$allowanceAmount->setAttribute('currencyID', $input['currency']);
$allowance->appendChild($allowanceAmount);

$allowanceTaxCat = $dom->createElement('cac:TaxCategory');
$allowanceTaxCatId = $dom->createElement('cbc:ID', 'S');
$allowanceTaxCatId->setAttribute('schemeID', 'UN/ECE 5305');
$allowanceTaxCatId->setAttribute('schemeAgencyID', '6');
$allowanceTaxCat->appendChild($allowanceTaxCatId);
$allowanceTaxCat->appendChild($dom->createElement('cbc:Percent', sprintf("%.0f", $input['vat_percent'])));

$allowanceTaxScheme = $dom->createElement('cac:TaxScheme');
$allowanceTaxSchemeId = $dom->createElement('cbc:ID', 'VAT');
$allowanceTaxSchemeId->setAttribute('schemeID', 'UN/ECE 5153');
$allowanceTaxSchemeId->setAttribute('schemeAgencyID', '6');
$allowanceTaxScheme->appendChild($allowanceTaxSchemeId);

$allowanceTaxCat->appendChild($allowanceTaxScheme);
$allowance->appendChild($allowanceTaxCat);
$invoice->appendChild($allowance);

// --- TaxTotal Blocks ---
// Block 1: Simple Summary
$taxTotal1 = $dom->createElement('cac:TaxTotal');
$tAmount1 = $dom->createElement('cbc:TaxAmount', sprintf("%.1f", $taxAmount));
$tAmount1->setAttribute('currencyID', $input['currency']);
$taxTotal1->appendChild($tAmount1);
$invoice->appendChild($taxTotal1);

// Block 2: Detailed Subtotal
$taxTotal2 = $dom->createElement('cac:TaxTotal');
$tAmount2 = $dom->createElement('cbc:TaxAmount', sprintf("%.2f", $taxAmount));
$tAmount2->setAttribute('currencyID', $input['currency']);
$taxTotal2->appendChild($tAmount2);

$taxSubtotal = $dom->createElement('cac:TaxSubtotal');
$taxableAmt = $dom->createElement('cbc:TaxableAmount', sprintf("%.2f", $lineExtensionTotal));
$taxableAmt->setAttribute('currencyID', $input['currency']);
$taxSubtotal->appendChild($taxableAmt);

$subTaxAmt = $dom->createElement('cbc:TaxAmount', sprintf("%.2f", $taxAmount));
$subTaxAmt->setAttribute('currencyID', $input['currency']);
$taxSubtotal->appendChild($subTaxAmt);

$subTaxCat = $dom->createElement('cac:TaxCategory');
$subTaxCatId = $dom->createElement('cbc:ID', 'S');
$subTaxCatId->setAttribute('schemeID', 'UN/ECE 5305');
$subTaxCatId->setAttribute('schemeAgencyID', '6');
$subTaxCat->appendChild($subTaxCatId);
$subTaxCat->appendChild($dom->createElement('cbc:Percent', sprintf("%.2f", $input['vat_percent'])));

$subTaxScheme = $dom->createElement('cac:TaxScheme');
$subTaxSchemeId = $dom->createElement('cbc:ID', 'VAT');
$subTaxSchemeId->setAttribute('schemeID', 'UN/ECE 5153');
$subTaxSchemeId->setAttribute('schemeAgencyID', '6');
$subTaxScheme->appendChild($subTaxSchemeId);
$subTaxCat->appendChild($subTaxScheme);

$taxSubtotal->appendChild($subTaxCat);
$taxTotal2->appendChild($taxSubtotal);
$invoice->appendChild($taxTotal2);

// --- Legal Monetary Total ---
$legalTotal = $dom->createElement('cac:LegalMonetaryTotal');

$elements = [
    'LineExtensionAmount'  => sprintf("%.2f", $lineExtensionTotal),
    'TaxExclusiveAmount'   => sprintf("%.2f", $lineExtensionTotal),
    'TaxInclusiveAmount'   => sprintf("%.2f", $taxInclusiveAmount),
    'AllowanceTotalAmount' => sprintf("%.2f", $input['discount_amount']),
    'PrepaidAmount'        => '0.00',
    'PayableAmount'        => sprintf("%.2f", $taxInclusiveAmount),
];

foreach ($elements as $tag => $val) {
    $node = $dom->createElement("cbc:$tag", $val);
    $node->setAttribute('currencyID', $input['currency']);
    $legalTotal->appendChild($node);
}
$invoice->appendChild($legalTotal);

// --- Invoice Line Items (Loop) ---
foreach ($input['line_items'] as $item) {
    $itemLineTotal = $item['quantity'] * $item['price'];
    $itemTaxAmount = $itemLineTotal * $vatRate;

    $invoiceLine = $dom->createElement('cac:InvoiceLine');
    $invoiceLine->appendChild($dom->createElement('cbc:ID', $item['id']));
    
    $qtyNode = $dom->createElement('cbc:InvoicedQuantity', sprintf("%.6f", $item['quantity']));
    $qtyNode->setAttribute('unitCode', $item['unit_code']);
    $invoiceLine->appendChild($qtyNode);
    
    $lineExtAmtNode = $dom->createElement('cbc:LineExtensionAmount', sprintf("%.2f", $itemLineTotal));
    $lineExtAmtNode->setAttribute('currencyID', $input['currency']);
    $invoiceLine->appendChild($lineExtAmtNode);
    
    // Line Tax Total
    $lineTaxTotal = $dom->createElement('cac:TaxTotal');
    $lineTaxAmt   = $dom->createElement('cbc:TaxAmount', sprintf("%.2f", $itemTaxAmount));
    $lineTaxAmt->setAttribute('currencyID', $input['currency']);
    $lineTaxTotal->appendChild($lineTaxAmt);
    
    $roundingAmt = $dom->createElement('cbc:RoundingAmount', sprintf("%.2f", $itemLineTotal + $itemTaxAmount));
    $roundingAmt->setAttribute('currencyID', $input['currency']);
    $lineTaxTotal->appendChild($roundingAmt);
    $invoiceLine->appendChild($lineTaxTotal);
    
    // Item Details
    $cacItem = $dom->createElement('cac:Item');
    $cacItem->appendChild($dom->createElement('cbc:Name', $item['name']));
    
    $classTaxCat = $dom->createElement('cac:ClassifiedTaxCategory');
    $classTaxCat->appendChild($dom->createElement('cbc:ID', 'S'));
    $classTaxCat->appendChild($dom->createElement('cbc:Percent', sprintf("%.2f", $input['vat_percent'])));
    $classTaxCat->appendChild($dom->createElement('cac:TaxScheme'))->appendChild($dom->createElement('cbc:ID', 'VAT'));
    $cacItem->appendChild($classTaxCat);
    $invoiceLine->appendChild($cacItem);
    
    // Price Details
    $cacPrice = $dom->createElement('cac:Price');
    $priceAmtNode = $dom->createElement('cbc:PriceAmount', sprintf("%.2f", $item['price']));
    $priceAmtNode->setAttribute('currencyID', $input['currency']);
    $cacPrice->appendChild($priceAmtNode);
    $invoiceLine->appendChild($cacPrice);
    
    $invoice->appendChild($invoiceLine);
}

// Save File Output
$filename = __DIR__ . '/generatedxml1.xml';

if (is_writable(__DIR__)) {
    $dom->save($filename);
    echo "XML successfully generated at: " . $filename;
} else {
    echo "Error: Directory is not writable. Check folder permissions.";
}


function hexToDecPure(string $hex): string {
    $hex = ltrim(strtolower($hex), '0');
    if ($hex === '') return '0';
    
    $dec = '0';
    for ($i = 0; $i < strlen($hex); $i++) {
        $digit = hexdec($hex[$i]);
        // Multiply current dec by 16 and add digit using string math
        $carry = $digit;
        $newDec = '';
        for ($j = strlen($dec) - 1; $j >= 0; $j--) {
            $val = ((int)$dec[$j] * 16) + $carry;
            $newDec = ($val % 10) . $newDec;
            $carry = (int)($val / 10);
        }
        while ($carry > 0) {
            $newDec = ($carry % 10) . $newDec;
            $carry = (int)($carry / 10);
        }
        $dec = $newDec;
    }
    return $dec;
}

function generateZatcaCertDigest(string $csid): string 
{
    // // 1. Strip headers, footers, whitespace, and newlines
    // $cleanCsid = preg_replace('/\s+/', '', $csid);
    // $cleanCsid = str_replace(
    //     ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], 
    //     '', 
    //     $cleanCsid
    // );


    // // 2. Decode Base64 to get raw DER binary bytes
    // $certDer = base64_decode($cleanCsid);

    // return $certDer;

    // // 3. Compute SHA-256 on DER binary bytes (returns 64-char hex string)
    // $hexHash = hash('sha256', $certDer);

    // // 4. Base64-encode the 64-character Hex string
    // return base64_encode($hexHash);

    $csid = 'MIID3jCCA4SgAwIBAgITEQAAOAPF90Ajs/xcXwABAAA4AzAKBggqhkjOPQQDAjBiMRUwEwYKCZImiZPyLGQBGRYFbG9jYWwxEzARBgoJkiaJk/IsZAEZFgNnb3YxFzAVBgoJkiaJk/IsZAEZFgdleHRnYXp0MRswGQYDVQQDExJQUlpFSU5WT0lDRVNDQTQtQ0EwHhcNMjQwMTExMDkxOTMwWhcNMjkwMTA5MDkxOTMwWjB1MQswCQYDVQQGEwJTQTEmMCQGA1UEChMdTWF4aW11bSBTcGVlZCBUZWNoIFN1cHBseSBMVEQxFjAUBgNVBAsTDVJpeWFkaCBCcmFuY2gxJjAkBgNVBAMTHVRTVC04ODY0MzExNDUtMzk5OTk5OTk5OTAwMDAzMFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEoWCKa0Sa9FIErTOv0uAkC1VIKXxU9nPpx2vlf4yhMejy8c02XJblDq7tPydo8mq0ahOMmNo8gwni7Xt1KT9UeKOCAgcwggIDMIGtBgNVHREEgaUwgaKkgZ8wgZwxOzA5BgNVBAQMMjEtVFNUfDItVFNUfDMtZWQyMmYxZDgtZTZhMi0xMTE4LTliNTgtZDlhOGYxMWU0NDVmMR8wHQYKCZImiZPyLGQBAQwPMzk5OTk5OTk5OTAwMDAzMQ0wCwYDVQQMDAQxMTAwMREwDwYDVQQaDAhSUlJEMjkyOTEaMBgGA1UEDwwRU3VwcGx5IGFjdGl2aXRpZXMwHQYDVR0OBBYEFEX+YvmmtnYoDf9BGbKo7ocTKYK1MB8GA1UdIwQYMBaAFJvKqqLtmqwskIFzVvpP2PxT+9NnMHsGCCsGAQUFBwEBBG8wbTBrBggrBgEFBQcwAoZfaHR0cDovL2FpYTQuemF0Y2EuZ292LnNhL0NlcnRFbnJvbGwvUFJaRUludm9pY2VTQ0E0LmV4dGdhenQuZ292LmxvY2FsX1BSWkVJTlZPSUNFU0NBNC1DQSgxKS5jcnQwDgYDVR0PAQH/BAQDAgeAMDwGCSsGAQQBgjcVBwQvMC0GJSsGAQQBgjcVCIGGqB2E0PsShu2dJIfO+xnTwFVmh/qlZYXZhD4CAWQCARIwHQYDVR0lBBYwFAYIKwYBBQUHAwMGCCsGAQUFBwMCMCcGCSsGAQQBgjcVCgQaMBgwCgYIKwYBBQUHAwMwCgYIKwYBBQUHAwIwCgYIKoZIzj0EAwIDSAAwRQIhALE/ichmnWXCUKUbca3yci8oqwaLvFdHVjQrveI9uqAbAiA9hC4M8jgMBADPSzmd2uiPJA6gKR3LE03U75eqbC/rXA==';

// Step 2: Clean the CSID (Remove PEM headers/footers, newlines, and spaces)
$cleanCsid = preg_replace('/\s+/', '', $csid);
$cleanCsid = str_replace(
    ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
    '',
    $cleanCsid
);

// Step 3: Decode the Base64 CSID string into raw DER binary bytes
$certDerBytes = base64_decode($cleanCsid);

// Step 4: Generate SHA-256 hash in lowercase Hex format (64 characters)
// Note: Do NOT set the 3rd parameter to true; ZATCA expects a lowercase hex string
$hexHash = hash('sha256', $certDerBytes);

// Step 5: Base64-encode the 64-character Hex string (results in an 88-character string)
$certDigestValue = base64_encode($hexHash);
return $certDigestValue;
}

// Result will be: ZDMwMmI0MTE1NzVjOTU2NTk4YzVlODhhYmI0ODU2NDUyNTU2YTVhYjhhMDFmN2FjYjk1YTA2OWQ0NjY2MjQ4NQ==