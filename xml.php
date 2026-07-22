<?php
/**
 * Simple ZATCA-style UBL Invoice XML generator.
 *
 * IMPORTANT ABOUT THE SIGNATURE/QR SECTION:
 * The <ds:DigestValue>, <ds:SignatureValue>, the X509 certificate, and the QR
 * code payload are cryptographic outputs - they can only be produced by:
 *   1. Building the invoice XML WITHOUT the signature extension.
 *   2. Canonicalizing it and hashing it (SHA-256) -> invoice digest.
 *   3. Signing that digest with your ECDSA private key (from your CSID/cert).
 *   4. Building the QR code (TLV + base64) from invoice fields + signature.
 *   5. Inserting all of the above back into the XML as the <ext:UBLExtensions> block.
 *
 * This script generates the FULL invoice structure and leaves clearly marked
 * placeholders for those signed values. Wire in your real signing routine
 * (openssl, or ZATCA SDK) where indicated.
 */

// ---------------------------------------------------------------------
// 1. INPUT DATA — change these for each invoice
// ---------------------------------------------------------------------
$invoice = [
    'id'              => 'SME00023',
    'uuid'            => '8d487816-70b8-4ade-a618-9d620b73814a',
    'issueDate'       => '2022-09-07',
    'issueTime'       => '12:21:28',
    'icv'             => '23', // Invoice Counter Value
    'previousHash'    => 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==', // PIH

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
// 2. PLACEHOLDERS FOR SIGNED VALUES — replace with real signing output
// ---------------------------------------------------------------------
$signing = [
    'invoiceDigest'      => 'REPLACE_WITH_SHA256_DIGEST_BASE64',
    'signedPropsDigest'  => 'REPLACE_WITH_SIGNED_PROPERTIES_DIGEST',
    'signatureValue'     => 'REPLACE_WITH_ECDSA_SIGNATURE_BASE64',
    'x509Certificate'    => 'REPLACE_WITH_BASE64_DER_CERTIFICATE',
    'certDigest'         => 'REPLACE_WITH_CERT_SHA256_DIGEST_BASE64',
    'issuerName'         => 'CN=PRZEINVOICESCA4-CA, DC=extgazt, DC=gov, DC=local',
    'serialNumber'       => 'REPLACE_WITH_CERT_SERIAL_NUMBER',
    'signingTime'        => '2024-01-14T10:21:40',
    'qrCodeBase64'       => 'REPLACE_WITH_GENERATED_QR_TLV_BASE64',
];

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
// 3a. UBLExtensions (signature block) — structure with placeholders
// ---------------------------------------------------------------------
$ublExtensions = el($doc, 'ext:UBLExtensions', $EXT);
$ublExtension  = el($doc, 'ext:UBLExtension', $EXT);
$extensionURI  = el($doc, 'ext:ExtensionURI', $EXT, 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
$extensionContent = el($doc, 'ext:ExtensionContent', $EXT);

// Raw signature XML is easiest to inject as a fragment, since ds:/xades: namespaces
// are local to this block only. We build it as a string and import it.
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
    . '<ds:DigestValue>' . htmlspecialchars($signing['invoiceDigest']) . '</ds:DigestValue>'
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
// 4. OUTPUT
// ---------------------------------------------------------------------
echo $doc->saveXML();

// To save to file instead:
// $doc->save(__DIR__ . '/invoice_output.xml');