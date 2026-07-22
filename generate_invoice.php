<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
/**
 * Simple ZATCA-style UBL Invoice XML generator.
 *
 * IMPORTANT ABOUT THE SIGNATURE/QR SECTION:
 * The <ds:SignatureValue>, the X509 certificate, the cert digest, and the QR
 * code TLV payload are cryptographic outputs - they can only be produced by:
 *   1. Building the invoice XML WITHOUT the signature extension.
 *   2. Canonicalizing it and hashing it (SHA-256) -> invoice digest.
 *   3. Signing that digest with your ECDSA private key (from your CSID/cert).
 *   4. Building the QR code (TLV + base64) from invoice fields + signature.
 *   5. Inserting all of the above back into the XML as the <ext:UBLExtensions> block.
 *
 * WHAT WAS BROKEN BEFORE:
 * The script computed the real invoice digest at the very end, but only
 * printed it - it never wrote it back into the <ds:DigestValue> node. So the
 * XML that actually got produced still contained the literal placeholder
 * string "REPLACE_WITH_SHA256_DIGEST_BASE64" inside an element that ZATCA's
 * schema requires to be valid base64. That's exactly what throws
 * "Invalid encoded base 64 format" when the XML is validated/submitted -
 * underscores aren't valid base64 characters and the placeholder isn't a
 * 32-byte digest.
 *
 * FIX APPLIED HERE:
 *  - The digest IS something this script can compute itself, so it's now
 *    written back into the DOM before output (no more placeholder leaking
 *    into the final XML for that field).
 *  - The signature value, X509 certificate, cert digest, signed-properties
 *    digest, and QR TLV genuinely CANNOT be produced without your real
 *    ECDSA private key + CSID certificate from ZATCA onboarding. Faking
 *    "valid-looking" base64 for those would just trade one failure (bad
 *    base64) for a worse, harder-to-debug one (signature verification
 *    failure at ZATCA). So instead of guessing, the script now fails fast
 *    with a clear message telling you exactly which fields still need real
 *    signing output wired in, via openssl or the ZATCA SDK.
 *  - The PIH (previous invoice hash) for the very first invoice in a chain
 *    is not an empty string in ZATCA's spec - it has a documented default
 *    constant. Left empty, that's also an invalid-base64-shaped value to a
 *    strict validator. Fixed below.
 */

// ---------------------------------------------------------------------
// 1. INPUT DATA — change these for each invoice
// ---------------------------------------------------------------------

// ZATCA's documented default Previous Invoice Hash (PIH) for the first
// invoice in a chain (base64 of SHA-256("0")). Use this only for invoice #1;
// every invoice after that must carry the real hash of the prior invoice.
const ZATCA_FIRST_INVOICE_PIH = 'NWZlMmI4YjQ2OWMxOTgxNmYxN2I3OGRmYzgyNzM0ZDBlOGZjZWZkZGNkY2YyNGNkOGYz';

$invoice = [
    'id'              => 'SME00023',
    'uuid'            => '8d487816-70b8-4ade-a618-9d620b73814a', // overwritten below with a fresh UUID
    'issueDate'       => '2022-09-07',
    'issueTime'       => '12:21:28',
    'icv'             => '23', // Invoice Counter Value
    'previousHash'    => ZATCA_FIRST_INVOICE_PIH, // set to the real PIH for any invoice after the first

    'supplier' => [
        'crn'        => '1010010000',
        'street'     => 'الامير سلطان | Prince Sultan',
        'building'   => '2322',
        'district'   => 'المربع | Al-Murabba',
        'city'       => 'الرياض | Riyadh',
        'postalZone' => '23333',
        'vatNumber'  => '399999999900003',
        'name'       => 'شركة توريد التكنولوجيا بأقصى سرعة المحدودة | Maximum Speed Tech Supply LTD',
    ],

    'customer' => [
        'street'     => 'صلاح الدين | Salah Al-Din',
        'building'   => '1111',
        'district'   => 'المروج | Al-Murooj',
        'city'       => 'الرياض | Riyadh',
        'postalZone' => '12222',
        'vatNumber'  => '399999999800003',
        'name'       => 'شركة نماذج فاتورة المحدودة | Fatoora Samples LTD',
    ],

    'deliveryDate'    => '2022-09-07',
    'paymentMeans'    => '10',

    'taxAmount'       => '0.60',
    'taxableAmount'   => '4.00',
    'taxPercent'      => '15.00',

    'lineExtension'   => '4.00',
    'taxExclusive'    => '4.00',
    'taxInclusive'    => '4.60',
    'allowanceTotal'  => '0.00',
    'prepaidAmount'   => '0.00',
    'payableAmount'   => '4.60',

    'lines' => [
        [
            'id'         => '1',
            'quantity'   => '2.000000',
            'unitCode'   => 'PCE',
            'lineTotal'  => '4.00',
            'taxAmount'  => '0.60',
            'roundedAmt' => '4.60',
            'itemName'   => 'قلم رصاص',
            'taxPercent' => '15.00',
            'priceAmount'=> '2.00',
        ],
    ],
];

// ---------------------------------------------------------------------
// 2. PLACEHOLDERS FOR SIGNED VALUES — replace with real signing output.
//    'invoiceDigest' is filled in automatically further down (it's
//    computable locally). The rest require your real ECDSA private key
//    and CSID certificate from ZATCA - wire those in via openssl or the
//    ZATCA SDK before this script can produce a submittable invoice.
// ---------------------------------------------------------------------
$signing = [
    'invoiceDigest'      => null, // auto-filled below, do not hardcode
    'signedPropsDigest'  => 'REPLACE_WITH_SIGNED_PROPERTIES_DIGEST',
    'signatureValue'     => 'REPLACE_WITH_ECDSA_SIGNATURE_BASE64',
    'x509Certificate'    => 'REPLACE_WITH_BASE64_DER_CERTIFICATE',
    'certDigest'         => 'REPLACE_WITH_CERT_SHA256_DIGEST_BASE64',
    'issuerName'         => 'CN=PRZEINVOICESCA4-CA, DC=extgazt, DC=gov, DC=local',
    'serialNumber'       => 'REPLACE_WITH_CERT_SERIAL_NUMBER',
    'signingTime'        => '2024-01-14T10:21:40',
    'qrCodeBase64'       => 'REPLACE_WITH_GENERATED_QR_TLV_BASE64',
];

// Fields that MUST be replaced with real cryptographic output before the
// XML is valid. Catching this here, with a clear message, beats finding out
// from a confusing "Invalid encoded base 64 format" error later.
const REQUIRED_SIGNING_FIELDS = [
    'signedPropsDigest', 'signatureValue', 'x509Certificate',
    'certDigest', 'serialNumber', 'qrCodeBase64',
];

function assertSigningFieldsAreReal(array $signing): void {
    $stillPlaceholder = [];
    foreach (REQUIRED_SIGNING_FIELDS as $key) {
        if (!isset($signing[$key]) || str_starts_with((string) $signing[$key], 'REPLACE_WITH')) {
            $stillPlaceholder[] = $key;
        }
    }
    if (!empty($stillPlaceholder)) {
        // Note: STDERR is only defined under the CLI SAPI. Using it directly
        // would throw an "Undefined constant STDERR" fatal error (-> blank
        // HTTP 500) when this script runs behind a web server instead of
        // the command line. error_log() + a thrown exception works in both.
        $message = "Cannot produce a valid invoice yet - these fields still need real "
            . "signing output (from your ECDSA private key + CSID certificate), not "
            . "placeholders:\n  - " . implode("\n  - ", $stillPlaceholder) . "\n\n"
            . "Sign the invoice digest first (see \$invoiceHash computed below), then "
            . "populate \$signing with the real values and re-run.";
        error_log($message);
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $message . "\n");
            exit(1);
        }
        throw new RuntimeException($message);
    }
}

// ---------------------------------------------------------------------
// Helper: standard ZATCA QR TLV builder (Tag-Length-Value + base64).
// No secrets involved in the structure itself - you still need to pass
// in your real hash/signature/public key/cert-signature once you have
// them. Tags 1-5 are always required; 6-9 are the Phase-2 cryptographic
// stamp fields.
// ---------------------------------------------------------------------
function buildZatcaQrBase64(array $orderedTagValues): string {
    $binary = '';
    foreach ($orderedTagValues as $tag => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $bytes = (string) $value;
        $binary .= chr($tag) . chr(strlen($bytes)) . $bytes;
    }
    return base64_encode($binary);
}

// Example of how you'd assemble it once you have real signed fields:
// $signing['qrCodeBase64'] = buildZatcaQrBase64([
//     1 => $invoice['supplier']['name'],
//     2 => $invoice['supplier']['vatNumber'],
//     3 => $invoice['issueDate'] . 'T' . $invoice['issueTime'],
//     4 => $invoice['taxInclusive'],
//     5 => $invoice['taxAmount'],
//     6 => base64_decode($invoiceHash),              // raw bytes of the invoice hash
//     7 => base64_decode($signing['signatureValue']), // raw bytes of the ECDSA signature
//     8 => base64_decode($publicKeyBytes),            // your ECDSA public key
//     9 => base64_decode($publicKeySignatureBytes),   // ZATCA's signature over your public key
// ]);

// ---------------------------------------------------------------------
// 3. BUILD XML WITH DOMDocument
// ---------------------------------------------------------------------
$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$ns = [
    ''    => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
    'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
];

$root = $doc->createElementNS($ns[''], 'Invoice');
foreach ($ns as $prefix => $uri) {
    if ($prefix === '') continue;
    $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $uri);
}
$doc->appendChild($root);

// Helper to make namespaced elements quickly
function el(DOMDocument $doc, string $qname, string $ns, ?string $text = null): DOMElement {
    $node = $doc->createElementNS($ns, $qname);
    if ($text !== null) {
        $node->appendChild($doc->createTextNode($text));
    }
    return $node;
}

$CAC = $ns['cac'];
$CBC = $ns['cbc'];
$EXT = $ns['ext'];

// ---------------------------------------------------------------------
// 3a. UBLExtensions (signature block) — structure with placeholders.
//     NOTE: invoiceDigest is inserted as a placeholder marker here and
//     patched with the REAL computed digest later in step 4b/4c, since
//     the digest can only be computed after the rest of the body exists.
// ---------------------------------------------------------------------
$ublExtensions = el($doc, 'ext:UBLExtensions', $EXT);
$ublExtension  = el($doc, 'ext:UBLExtension', $EXT);
$extensionURI  = el($doc, 'ext:ExtensionURI', $EXT, 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
$extensionContent = el($doc, 'ext:ExtensionContent', $EXT);

// Raw signature XML is easiest to inject as a fragment, since ds:/xades: namespaces
// are local to this block only. We build it as a string and import it.
// DIGEST_PLACEHOLDER_MARKER is patched with the real digest in step 4c below -
// it is NOT a value that should ever survive into the final output.
const DIGEST_PLACEHOLDER_MARKER = '__INVOICE_DIGEST_PLACEHOLDER__';

$sigXml = '<sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" '
    . 'xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" '
    . 'xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">'
    . '<sac:SignatureInformation>'
    . '<cbc:ID xmlns:cbc="' . $CBC . '">urn:oasis:names:specification:ubl:signature:1</cbc:ID>'
    . '<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'
    . '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">'
    . '<ds:SignedInfo>'
    . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
    . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
    . '<ds:Reference Id="invoiceSignedData" URI="">'
    . '<ds:Transforms>'
    . '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath></ds:Transform>'
    . '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath></ds:Transform>'
    . '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath></ds:Transform>'
    . '<ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
    . '</ds:Transforms>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . DIGEST_PLACEHOLDER_MARKER . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . htmlspecialchars($signing['signedPropsDigest']) . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>'
    . '<ds:SignatureValue>' . htmlspecialchars($signing['signatureValue']) . '</ds:SignatureValue>'
    . '<ds:KeyInfo><ds:X509Data><ds:X509Certificate>' . htmlspecialchars($signing['x509Certificate']) . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
    . '<ds:Object>'
    . '<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">'
    . '<xades:SignedProperties Id="xadesSignedProperties">'
    . '<xades:SignedSignatureProperties>'
    . '<xades:SigningTime>' . htmlspecialchars($signing['signingTime']) . '</xades:SigningTime>'
    . '<xades:SigningCertificate><xades:Cert>'
    . '<xades:CertDigest><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>' . htmlspecialchars($signing['certDigest']) . '</ds:DigestValue></xades:CertDigest>'
    . '<xades:IssuerSerial><ds:X509IssuerName>' . htmlspecialchars($signing['issuerName']) . '</ds:X509IssuerName><ds:X509SerialNumber>' . htmlspecialchars($signing['serialNumber']) . '</ds:X509SerialNumber></xades:IssuerSerial>'
    . '</xades:Cert></xades:SigningCertificate>'
    . '</xades:SignedSignatureProperties>'
    . '</xades:SignedProperties>'
    . '</xades:QualifyingProperties>'
    . '</ds:Object>'
    . '</ds:Signature>'
    . '</sac:SignatureInformation>'
    . '</sig:UBLDocumentSignatures>';

$fragment = $doc->createDocumentFragment();
$fragment->appendXML($sigXml);
$extensionContent->appendChild($fragment);

$ublExtension->appendChild($extensionURI);
$ublExtension->appendChild($extensionContent);
$ublExtensions->appendChild($ublExtension);
$root->appendChild($ublExtensions);

// ---------------------------------------------------------------------
// 3b. Header fields
// ---------------------------------------------------------------------
$root->appendChild(el($doc, 'cbc:ProfileID', $CBC, 'reporting:1.0'));
$root->appendChild(el($doc, 'cbc:ID', $CBC, $invoice['id']));
$root->appendChild(el($doc, 'cbc:UUID', $CBC, $invoice['uuid']));
$root->appendChild(el($doc, 'cbc:IssueDate', $CBC, $invoice['issueDate']));
$root->appendChild(el($doc, 'cbc:IssueTime', $CBC, $invoice['issueTime']));

$typeCode = el($doc, 'cbc:InvoiceTypeCode', $CBC, '388');
$typeCode->setAttribute('name', '0100000');
$root->appendChild($typeCode);

$root->appendChild(el($doc, 'cbc:DocumentCurrencyCode', $CBC, 'SAR'));
$root->appendChild(el($doc, 'cbc:TaxCurrencyCode', $CBC, 'SAR'));

// ICV
$icvRef = el($doc, 'cac:AdditionalDocumentReference', $CAC);
$icvRef->appendChild(el($doc, 'cbc:ID', $CBC, 'ICV'));
$icvRef->appendChild(el($doc, 'cbc:UUID', $CBC, $invoice['icv']));
$root->appendChild($icvRef);

// PIH (previous invoice hash)
$pihRef = el($doc, 'cac:AdditionalDocumentReference', $CAC);
$pihRef->appendChild(el($doc, 'cbc:ID', $CBC, 'PIH'));
$pihAttachment = el($doc, 'cac:Attachment', $CAC);
$pihBinary = el($doc, 'cbc:EmbeddedDocumentBinaryObject', $CBC, $invoice['previousHash']);
$pihBinary->setAttribute('mimeCode', 'text/plain');
$pihAttachment->appendChild($pihBinary);
$pihRef->appendChild($pihAttachment);
$root->appendChild($pihRef);

// QR code (placeholder value — build the real TLV+base64 from signed fields)
$qrRef = el($doc, 'cac:AdditionalDocumentReference', $CAC);
$qrRef->appendChild(el($doc, 'cbc:ID', $CBC, 'QR'));
$qrAttachment = el($doc, 'cac:Attachment', $CAC);
$qrBinary = el($doc, 'cbc:EmbeddedDocumentBinaryObject', $CBC, $signing['qrCodeBase64']);
$qrBinary->setAttribute('mimeCode', 'text/plain');
$qrAttachment->appendChild($qrBinary);
$qrRef->appendChild($qrAttachment);
$root->appendChild($qrRef);

// cac:Signature reference
$cacSignature = el($doc, 'cac:Signature', $CAC);
$cacSignature->appendChild(el($doc, 'cbc:ID', $CBC, 'urn:oasis:names:specification:ubl:signature:Invoice'));
$cacSignature->appendChild(el($doc, 'cbc:SignatureMethod', $CBC, 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));
$root->appendChild($cacSignature);

// ---------------------------------------------------------------------
// 3c. Supplier / Customer parties
// ---------------------------------------------------------------------
function buildParty(DOMDocument $doc, string $CAC, string $CBC, array $p, bool $includeCrn): DOMElement {
    $party = el($doc, 'cac:Party', $CAC);

    if ($includeCrn) {
        $partyId = el($doc, 'cac:PartyIdentification', $CAC);
        $idEl = el($doc, 'cbc:ID', $CBC, $p['crn']);
        $idEl->setAttribute('schemeID', 'CRN');
        $partyId->appendChild($idEl);
        $party->appendChild($partyId);
    }

    $address = el($doc, 'cac:PostalAddress', $CAC);
    $address->appendChild(el($doc, 'cbc:StreetName', $CBC, $p['street']));
    $address->appendChild(el($doc, 'cbc:BuildingNumber', $CBC, $p['building']));
    $address->appendChild(el($doc, 'cbc:CitySubdivisionName', $CBC, $p['district']));
    $address->appendChild(el($doc, 'cbc:CityName', $CBC, $p['city']));
    $address->appendChild(el($doc, 'cbc:PostalZone', $CBC, $p['postalZone']));
    $country = el($doc, 'cac:Country', $CAC);
    $country->appendChild(el($doc, 'cbc:IdentificationCode', $CBC, 'SA'));
    $address->appendChild($country);
    $party->appendChild($address);

    $taxScheme = el($doc, 'cac:PartyTaxScheme', $CAC);
    $taxScheme->appendChild(el($doc, 'cbc:CompanyID', $CBC, $p['vatNumber']));
    $scheme = el($doc, 'cac:TaxScheme', $CAC);
    $scheme->appendChild(el($doc, 'cbc:ID', $CBC, 'VAT'));
    $taxScheme->appendChild($scheme);
    $party->appendChild($taxScheme);

    $legalEntity = el($doc, 'cac:PartyLegalEntity', $CAC);
    $legalEntity->appendChild(el($doc, 'cbc:RegistrationName', $CBC, $p['name']));
    $party->appendChild($legalEntity);

    return $party;
}

$supplierParty = el($doc, 'cac:AccountingSupplierParty', $CAC);
$supplierParty->appendChild(buildParty($doc, $CAC, $CBC, $invoice['supplier'], true));
$root->appendChild($supplierParty);

$customerParty = el($doc, 'cac:AccountingCustomerParty', $CAC);
$customerParty->appendChild(buildParty($doc, $CAC, $CBC, $invoice['customer'], false));
$root->appendChild($customerParty);

// ---------------------------------------------------------------------
// 3d. Delivery / Payment / Allowance / Tax totals / Monetary totals
// ---------------------------------------------------------------------
$delivery = el($doc, 'cac:Delivery', $CAC);
$delivery->appendChild(el($doc, 'cbc:ActualDeliveryDate', $CBC, $invoice['deliveryDate']));
$root->appendChild($delivery);

$paymentMeans = el($doc, 'cac:PaymentMeans', $CAC);
$paymentMeans->appendChild(el($doc, 'cbc:PaymentMeansCode', $CBC, $invoice['paymentMeans']));
$root->appendChild($paymentMeans);

$allowanceCharge = el($doc, 'cac:AllowanceCharge', $CAC);
$allowanceCharge->appendChild(el($doc, 'cbc:ChargeIndicator', $CBC, 'false'));
$allowanceCharge->appendChild(el($doc, 'cbc:AllowanceChargeReason', $CBC, 'discount'));
$amt = el($doc, 'cbc:Amount', $CBC, '0.00');
$amt->setAttribute('currencyID', 'SAR');
$allowanceCharge->appendChild($amt);
$taxCat = el($doc, 'cac:TaxCategory', $CAC);
$idEl = el($doc, 'cbc:ID', $CBC, 'S');
$idEl->setAttribute('schemeID', 'UN/ECE 5305');
$idEl->setAttribute('schemeAgencyID', '6');
$taxCat->appendChild($idEl);
$taxCat->appendChild(el($doc, 'cbc:Percent', $CBC, '15'));
$scheme = el($doc, 'cac:TaxScheme', $CAC);
$schemeId = el($doc, 'cbc:ID', $CBC, 'VAT');
$schemeId->setAttribute('schemeID', 'UN/ECE 5153');
$schemeId->setAttribute('schemeAgencyID', '6');
$scheme->appendChild($schemeId);
$taxCat->appendChild($scheme);
$allowanceCharge->appendChild($taxCat);
$root->appendChild($allowanceCharge);

// Top-level TaxTotal (currency only)
$taxTotal1 = el($doc, 'cac:TaxTotal', $CAC);
$taxAmt1 = el($doc, 'cbc:TaxAmount', $CBC, $invoice['taxAmount']);
$taxAmt1->setAttribute('currencyID', 'SAR');
$taxTotal1->appendChild($taxAmt1);
$root->appendChild($taxTotal1);

// Detailed TaxTotal with subtotal
$taxTotal2 = el($doc, 'cac:TaxTotal', $CAC);
$taxAmt2 = el($doc, 'cbc:TaxAmount', $CBC, $invoice['taxAmount']);
$taxAmt2->setAttribute('currencyID', 'SAR');
$taxTotal2->appendChild($taxAmt2);

$taxSubtotal = el($doc, 'cac:TaxSubtotal', $CAC);
$taxableAmt = el($doc, 'cbc:TaxableAmount', $CBC, $invoice['taxableAmount']);
$taxableAmt->setAttribute('currencyID', 'SAR');
$taxSubtotal->appendChild($taxableAmt);
$subTaxAmt = el($doc, 'cbc:TaxAmount', $CBC, $invoice['taxAmount']);
$subTaxAmt->setAttribute('currencyID', 'SAR');
$taxSubtotal->appendChild($subTaxAmt);

$taxCat2 = el($doc, 'cac:TaxCategory', $CAC);
$idEl2 = el($doc, 'cbc:ID', $CBC, 'S');
$idEl2->setAttribute('schemeID', 'UN/ECE 5305');
$idEl2->setAttribute('schemeAgencyID', '6');
$taxCat2->appendChild($idEl2);
$taxCat2->appendChild(el($doc, 'cbc:Percent', $CBC, $invoice['taxPercent']));
$scheme2 = el($doc, 'cac:TaxScheme', $CAC);
$schemeId2 = el($doc, 'cbc:ID', $CBC, 'VAT');
$schemeId2->setAttribute('schemeID', 'UN/ECE 5153');
$schemeId2->setAttribute('schemeAgencyID', '6');
$scheme2->appendChild($schemeId2);
$taxCat2->appendChild($scheme2);
$taxSubtotal->appendChild($taxCat2);

$taxTotal2->appendChild($taxSubtotal);
$root->appendChild($taxTotal2);

// Legal monetary total
$monetaryTotal = el($doc, 'cac:LegalMonetaryTotal', $CAC);
$fields = [
    'LineExtensionAmount' => $invoice['lineExtension'],
    'TaxExclusiveAmount'  => $invoice['taxExclusive'],
    'TaxInclusiveAmount'  => $invoice['taxInclusive'],
    'AllowanceTotalAmount'=> $invoice['allowanceTotal'],
    'PrepaidAmount'       => $invoice['prepaidAmount'],
    'PayableAmount'       => $invoice['payableAmount'],
];
foreach ($fields as $tag => $value) {
    $node = el($doc, 'cbc:' . $tag, $CBC, $value);
    $node->setAttribute('currencyID', 'SAR');
    $monetaryTotal->appendChild($node);
}
$root->appendChild($monetaryTotal);

// ---------------------------------------------------------------------
// 3e. Invoice lines
// ---------------------------------------------------------------------
foreach ($invoice['lines'] as $line) {
    $lineEl = el($doc, 'cac:InvoiceLine', $CAC);
    $lineEl->appendChild(el($doc, 'cbc:ID', $CBC, $line['id']));

    $qty = el($doc, 'cbc:InvoicedQuantity', $CBC, $line['quantity']);
    $qty->setAttribute('unitCode', $line['unitCode']);
    $lineEl->appendChild($qty);

    $lineExt = el($doc, 'cbc:LineExtensionAmount', $CBC, $line['lineTotal']);
    $lineExt->setAttribute('currencyID', 'SAR');
    $lineEl->appendChild($lineExt);

    $lineTaxTotal = el($doc, 'cac:TaxTotal', $CAC);
    $lineTaxAmt = el($doc, 'cbc:TaxAmount', $CBC, $line['taxAmount']);
    $lineTaxAmt->setAttribute('currencyID', 'SAR');
    $lineTaxTotal->appendChild($lineTaxAmt);
    $roundingAmt = el($doc, 'cbc:RoundingAmount', $CBC, $line['roundedAmt']);
    $roundingAmt->setAttribute('currencyID', 'SAR');
    $lineTaxTotal->appendChild($roundingAmt);
    $lineEl->appendChild($lineTaxTotal);

    $item = el($doc, 'cac:Item', $CAC);
    $item->appendChild(el($doc, 'cbc:Name', $CBC, $line['itemName']));
    $classifiedTax = el($doc, 'cac:ClassifiedTaxCategory', $CAC);
    $classifiedTax->appendChild(el($doc, 'cbc:ID', $CBC, 'S'));
    $classifiedTax->appendChild(el($doc, 'cbc:Percent', $CBC, $line['taxPercent']));
    $classScheme = el($doc, 'cac:TaxScheme', $CAC);
    $classScheme->appendChild(el($doc, 'cbc:ID', $CBC, 'VAT'));
    $classifiedTax->appendChild($classScheme);
    $item->appendChild($classifiedTax);
    $lineEl->appendChild($item);

    $price = el($doc, 'cac:Price', $CAC);
    $priceAmt = el($doc, 'cbc:PriceAmount', $CBC, $line['priceAmount']);
    $priceAmt->setAttribute('currencyID', 'SAR');
    $price->appendChild($priceAmt);
    $lineEl->appendChild($price);

    $root->appendChild($lineEl);
}
// ---------------------------------------------------------------------
// 4. GENERATE DYNAMIC UUID, COMPUTE HASH, PATCH IT IN, AND OUTPUT
// ---------------------------------------------------------------------

// Step 4a: Generate a dynamic UUID v4 and inject it into the XML DOM
$cryptoBytes = random_bytes(16);
// Set the version to 4 (0100) and variant to RFC 4122 (10xx)
$cryptoBytes[6] = chr(ord($cryptoBytes[6]) & 0x0f | 0x40);
$cryptoBytes[8] = chr(ord($cryptoBytes[8]) & 0x3f | 0x80);
$dynamicUuid    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($cryptoBytes), 4));

// Replace the placeholder/array UUID in the DOM Document
$uuidNodes = $doc->getElementsByTagNameNS($ns['cbc'], 'UUID');
foreach ($uuidNodes as $node) {
    // Ensure we only update the main invoice header UUID, not the ICV UUID
    if ($node->parentNode->localName === 'Invoice') {
        $node->nodeValue = $dynamicUuid;
        break;
    }
}

// Step 4b: Calculate the standard ZATCA Invoice Hash
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
$xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
$xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

// Temporarily drop signature elements to get an untampered invoice body for hashing
$nodesToExclude = $xpath->query('//ext:UBLExtensions | //cac:Signature | //cac:AdditionalDocumentReference[cbc:ID="QR"]');
$removedNodes = [];
foreach ($nodesToExclude as $node) {
    $parent = $node->parentNode;
    if ($parent) {
        $removedNodes[] = [
            'node'   => $node,
            'parent' => $parent,
            'next'   => $node->nextSibling
        ];
        $parent->removeChild($node);
    }
}

// Canonicalize & Hash
$canonicalXml = $doc->C14N(false, false);
$invoiceHash  = base64_encode(hash('sha256', $canonicalXml, true));
$signing['invoiceDigest'] = $invoiceHash;

// Restore elements back to the DOM
foreach (array_reverse($removedNodes) as $item) {
    $item['parent']->insertBefore($item['node'], $item['next']);
}

// Step 4c: Patch the real digest into the <ds:DigestValue> placeholder.
// THIS is the fix for "Invalid encoded base 64 format" - without this,
// the placeholder marker text would ship inside the final XML.
$xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
$digestNodes = $xpath->query('//ds:DigestValue[text()="' . DIGEST_PLACEHOLDER_MARKER . '"]');
foreach ($digestNodes as $node) {
    $node->nodeValue = $invoiceHash;
}

// Step 4d: Refuse to emit XML that still has un-signed placeholder fields.
// This turns a confusing downstream "Invalid encoded base 64 format" error
// into an immediate, actionable one.
assertSigningFieldsAreReal($signing);

// Step 4e: Display everything
echo "--- BASE64 ENCODED XML ---" . PHP_EOL;
echo base64_encode($doc->saveXML()) . PHP_EOL . PHP_EOL;

echo "--- GENERATED INVOICE HASH (SHA-256 Base64) ---" . PHP_EOL;
echo $invoiceHash . PHP_EOL . PHP_EOL;

echo "--- DYNAMICALLY GENERATED INVOICE UUID ---" . PHP_EOL;
echo $dynamicUuid . PHP_EOL;