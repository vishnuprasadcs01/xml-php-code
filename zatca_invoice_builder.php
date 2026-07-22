<?php
/**
 * ZATCA (Fatoora) UBL 2.1 Invoice XML Builder
 * ---------------------------------------------
 * Builds the unsigned invoice XML structure matching ZATCA's UBL invoice
 * format (as used for Saudi Arabia e-invoicing "reporting" / simplified
 * invoices, InvoiceTypeCode 388).
 *
 * IMPORTANT: This script produces the UNSIGNED invoice. The
 * <ext:UBLExtensions> digital signature block, the PIH (Previous Invoice
 * Hash), and the QR code TLV payload all depend on real cryptographic
 * operations against a ZATCA-issued certificate/private key and the actual
 * hash of the previous invoice in your chain. Those must be produced by
 * your own signing routine (or ZATCA's SDK / a compliant middleware), not
 * hardcoded — copying the sample's signature values would make the
 * document invalid. Placeholder functions are marked clearly below so you
 * know exactly where to plug in real signing logic.
 *
 * Usage:
 *   php zatca_invoice_builder.php
 */

class ZatcaInvoiceBuilder
{
    private DOMDocument $doc;
    private DOMElement $root;

    // UBL namespaces
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const NS_CAC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const NS_EXT     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $this->root = $this->doc->createElementNS(self::NS_INVOICE, 'Invoice');
        $this->root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC
        );
        $this->root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC
        );
        $this->root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::NS_EXT
        );
        $this->doc->appendChild($this->root);
    }

    /** Shorthand: create a <cbc:...> element with optional text + attributes */
    private function cbc(string $name, ?string $text = null, array $attrs = []): DOMElement
    {
        $el = $this->doc->createElementNS(self::NS_CBC, "cbc:$name");
        if ($text !== null) {
            $el->appendChild($this->doc->createTextNode($text));
        }
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, $v);
        }
        return $el;
    }

    /** Shorthand: create a <cac:...> element */
    private function cac(string $name): DOMElement
    {
        return $this->doc->createElementNS(self::NS_CAC, "cac:$name");
    }

    // -----------------------------------------------------------------
    // Section builders
    // -----------------------------------------------------------------

    // Extra namespaces needed only inside the signature block
    private const NS_SIG   = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';
    private const NS_SAC   = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';
    private const NS_SBC   = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';
    private const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /** Shorthand for creating elements in arbitrary namespaces (ds:, sig:, sac:, sbc:, xades:) */
    private function el(string $ns, string $qualifiedName, ?string $text = null, array $attrs = []): DOMElement
    {
        $e = $this->doc->createElementNS($ns, $qualifiedName);
        if ($text !== null) {
            $e->appendChild($this->doc->createTextNode($text));
        }
        foreach ($attrs as $k => $v) {
            $e->setAttribute($k, $v);
        }
        return $e;
    }

    /**
     * Builds the ext:UBLExtensions signature block with the FULL element
     * tree the ZATCA/UBL XSD requires (ds:SignedInfo, ds:SignatureValue,
     * ds:KeyInfo/X509Certificate, xades:QualifyingProperties, etc.) so the
     * document is structurally schema-valid.
     *
     * IMPORTANT — this does NOT make the invoice cryptographically valid.
     * $sig['digestValue'], $sig['signatureValue'], and $sig['certificate']
     * must come from actually hashing/signing the canonicalized invoice
     * with your real ECDSA P-256 private key and ZATCA-issued certificate
     * (via ZATCA's SDK, a compliant middleware, or your own openssl
     * routine). Passing dummy strings will pass XSD structural validation
     * (fixing the error you saw) but will be REJECTED by ZATCA's clearance/
     * reporting API, which independently re-verifies the signature.
     *
     * Expected $sig keys (all optional — placeholders used if omitted,
     * which is fine for local schema testing but NOT for real submission):
     *   'digestValue'         - base64 SHA-256 digest of the invoice content
     *   'signedPropsDigest'   - base64 digest of the SignedProperties
     *   'signatureValue'      - base64 ECDSA signature value
     *   'certificateBase64'   - base64 DER X.509 certificate (no PEM headers)
     *   'signingTime'         - ISO 8601 datetime, e.g. '2024-01-14T10:21:40'
     *   'issuerName'          - X.509 issuer distinguished name string
     *   'issuerSerialNumber'  - X.509 serial number (decimal string)
     *   'certDigest'          - base64 SHA-256 digest of the certificate
     */
    public function addUblExtensions(array $sig = []): void
    {
        $digestValue        = $sig['digestValue'] ?? 'PLACEHOLDER_INVOICE_DIGEST';
        $signedPropsDigest   = $sig['signedPropsDigest'] ?? 'PLACEHOLDER_SIGNED_PROPS_DIGEST';
        $signatureValue      = $sig['signatureValue'] ?? 'PLACEHOLDER_SIGNATURE_VALUE';
        $certificateBase64   = $sig['certificateBase64'] ?? 'PLACEHOLDER_CERTIFICATE_BASE64';
        $signingTime         = $sig['signingTime'] ?? gmdate('Y-m-d\TH:i:s');
        $issuerName          = $sig['issuerName'] ?? 'CN=PLACEHOLDER-CA, DC=example, DC=gov, DC=local';
        $issuerSerialNumber  = $sig['issuerSerialNumber'] ?? '0';
        $certDigest          = $sig['certDigest'] ?? 'PLACEHOLDER_CERT_DIGEST';

        $ext = $this->el(self::NS_EXT, 'ext:UBLExtensions');
        $extension = $this->el(self::NS_EXT, 'ext:UBLExtension');
        $extension->appendChild($this->el(
            self::NS_EXT, 'ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'
        ));

        $content = $this->el(self::NS_EXT, 'ext:ExtensionContent');

        $sigDoc = $this->el(self::NS_SIG, 'sig:UBLDocumentSignatures');
        $sigDoc->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::NS_SAC);
        $sigDoc->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sbc', self::NS_SBC);

        $sigInfo = $this->el(self::NS_SAC, 'sac:SignatureInformation');
        $sigInfo->appendChild($this->el(self::NS_CBC, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:1'));
        $sigInfo->appendChild($this->el(
            self::NS_SBC, 'sbc:ReferencedSignatureID', 'urn:oasis:names:specification:ubl:signature:Invoice'
        ));

        // --- ds:Signature ---
        $signature = $this->el(self::NS_DS, 'ds:Signature', null, ['Id' => 'signature']);

        $signedInfo = $this->el(self::NS_DS, 'ds:SignedInfo');
        $signedInfo->appendChild($this->el(
            self::NS_DS, 'ds:CanonicalizationMethod', null,
            ['Algorithm' => 'http://www.w3.org/2006/12/xml-c14n11']
        ));
        $signedInfo->appendChild($this->el(
            self::NS_DS, 'ds:SignatureMethod', null,
            ['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256']
        ));

        $ref1 = $this->el(self::NS_DS, 'ds:Reference', null, ['Id' => 'invoiceSignedData', 'URI' => '']);
        $transforms = $this->el(self::NS_DS, 'ds:Transforms');
        foreach ([
            "not(//ancestor-or-self::ext:UBLExtensions)",
            "not(//ancestor-or-self::cac:Signature)",
            "not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])",
        ] as $xpath) {
            $t = $this->el(self::NS_DS, 'ds:Transform', null, ['Algorithm' => 'http://www.w3.org/TR/1999/REC-xpath-19991116']);
            $t->appendChild($this->el(self::NS_DS, 'ds:XPath', $xpath));
            $transforms->appendChild($t);
        }
        $transforms->appendChild($this->el(
            self::NS_DS, 'ds:Transform', null, ['Algorithm' => 'http://www.w3.org/2006/12/xml-c14n11']
        ));
        $ref1->appendChild($transforms);
        $ref1->appendChild($this->el(
            self::NS_DS, 'ds:DigestMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']
        ));
        $ref1->appendChild($this->el(self::NS_DS, 'ds:DigestValue', $digestValue));
        $signedInfo->appendChild($ref1);

        $ref2 = $this->el(
            self::NS_DS, 'ds:Reference', null,
            ['Type' => 'http://www.w3.org/2000/09/xmldsig#SignatureProperties', 'URI' => '#xadesSignedProperties']
        );
        $ref2->appendChild($this->el(
            self::NS_DS, 'ds:DigestMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']
        ));
        $ref2->appendChild($this->el(self::NS_DS, 'ds:DigestValue', $signedPropsDigest));
        $signedInfo->appendChild($ref2);

        $signature->appendChild($signedInfo);
        $signature->appendChild($this->el(self::NS_DS, 'ds:SignatureValue', $signatureValue));

        $keyInfo = $this->el(self::NS_DS, 'ds:KeyInfo');
        $x509Data = $this->el(self::NS_DS, 'ds:X509Data');
        $x509Data->appendChild($this->el(self::NS_DS, 'ds:X509Certificate', $certificateBase64));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        // --- ds:Object > xades:QualifyingProperties ---
        $object = $this->el(self::NS_DS, 'ds:Object');
        $qProps = $this->el(self::NS_XADES, 'xades:QualifyingProperties', null, ['Target' => 'signature']);

        $signedProps = $this->el(self::NS_XADES, 'xades:SignedProperties', null, ['Id' => 'xadesSignedProperties']);
        $signedSigProps = $this->el(self::NS_XADES, 'xades:SignedSignatureProperties');
        $signedSigProps->appendChild($this->el(self::NS_XADES, 'xades:SigningTime', $signingTime));

        $signingCert = $this->el(self::NS_XADES, 'xades:SigningCertificate');
        $cert = $this->el(self::NS_XADES, 'xades:Cert');
        $certDigestEl = $this->el(self::NS_XADES, 'xades:CertDigest');
        $certDigestEl->appendChild($this->el(
            self::NS_DS, 'ds:DigestMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']
        ));
        $certDigestEl->appendChild($this->el(self::NS_DS, 'ds:DigestValue', $certDigest));
        $cert->appendChild($certDigestEl);

        $issuerSerial = $this->el(self::NS_XADES, 'xades:IssuerSerial');
        $issuerSerial->appendChild($this->el(self::NS_DS, 'ds:X509IssuerName', $issuerName));
        $issuerSerial->appendChild($this->el(self::NS_DS, 'ds:X509SerialNumber', $issuerSerialNumber));
        $cert->appendChild($issuerSerial);

        $signingCert->appendChild($cert);
        $signedSigProps->appendChild($signingCert);
        $signedProps->appendChild($signedSigProps);
        $qProps->appendChild($signedProps);
        $object->appendChild($qProps);
        $signature->appendChild($object);

        $sigInfo->appendChild($signature);
        $sigDoc->appendChild($sigInfo);
        $content->appendChild($sigDoc);
        $extension->appendChild($content);
        $ext->appendChild($extension);
        $this->root->appendChild($ext);
    }

    /** @deprecated use addUblExtensions() instead — kept for backward compatibility */
    public function addUblExtensionsPlaceholder(array $signature): void
    {
        $this->addUblExtensions($signature);
    }

    public function addHeader(array $h): void
    {
        $this->root->appendChild($this->cbc('ProfileID', $h['profileId']));
        $this->root->appendChild($this->cbc('ID', $h['id']));
        $this->root->appendChild($this->cbc('UUID', $h['uuid']));
        $this->root->appendChild($this->cbc('IssueDate', $h['issueDate']));
        $this->root->appendChild($this->cbc('IssueTime', $h['issueTime']));
        $this->root->appendChild($this->cbc(
            'InvoiceTypeCode', $h['typeCode'], ['name' => $h['typeCodeName']]
        ));
        $this->root->appendChild($this->cbc('DocumentCurrencyCode', $h['currency']));
        $this->root->appendChild($this->cbc('TaxCurrencyCode', $h['taxCurrency']));
    }

    /** ICV — Invoice Counter Value (must increment sequentially per your chain) */
    public function addIcv(int $counterValue): void
    {
        $ref = $this->cac('AdditionalDocumentReference');
        $ref->appendChild($this->cbc('ID', 'ICV'));
        $ref->appendChild($this->cbc('UUID', (string) $counterValue));
        $this->root->appendChild($ref);
    }

    /**
     * PIH — Previous Invoice Hash.
     * $previousInvoiceHashBase64 must be the base64 of the SHA-256 hash of
     * the previous invoice's canonicalized XML (base64-of-base64, matching
     * ZATCA's actual format) — compute this from your real previous invoice,
     * don't hardcode.
     */
    public function addPih(string $previousInvoiceHashBase64): void
    {
        $ref = $this->cac('AdditionalDocumentReference');
        $ref->appendChild($this->cbc('ID', 'PIH'));
        $attachment = $this->cac('Attachment');
        $bin = $this->cbc(
            'EmbeddedDocumentBinaryObject',
            $previousInvoiceHashBase64,
            ['mimeCode' => 'text/plain']
        );
        $attachment->appendChild($bin);
        $ref->appendChild($attachment);
        $this->root->appendChild($ref);
    }

    /**
     * QR — placeholder for the base64 TLV QR payload.
     * Real usage: build the TLV (seller name, VAT number, timestamp,
     * total, VAT total, hash, signature, public key, cert signature),
     * base64-encode it, and pass it in here.
     */
    public function addQr(string $qrBase64Tlv): void
    {
        $ref = $this->cac('AdditionalDocumentReference');
        $ref->appendChild($this->cbc('ID', 'QR'));
        $attachment = $this->cac('Attachment');
        $bin = $this->cbc(
            'EmbeddedDocumentBinaryObject',
            $qrBase64Tlv,
            ['mimeCode' => 'text/plain']
        );
        $attachment->appendChild($bin);
        $ref->appendChild($attachment);
        $this->root->appendChild($ref);
    }

    public function addSignatureReference(): void
    {
        $sig = $this->cac('Signature');
        $sig->appendChild($this->cbc('ID', 'urn:oasis:names:specification:ubl:signature:Invoice'));
        $sig->appendChild($this->cbc('SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));
        $this->root->appendChild($sig);
    }

    private function buildParty(array $p, bool $includeCrn): DOMElement
    {
        $party = $this->cac('Party');

        if ($includeCrn && !empty($p['crn'])) {
            $ident = $this->cac('PartyIdentification');
            $ident->appendChild($this->cbc('ID', $p['crn'], ['schemeID' => 'CRN']));
            $party->appendChild($ident);
        }

        $addr = $this->cac('PostalAddress');
        $addr->appendChild($this->cbc('StreetName', $p['street']));
        $addr->appendChild($this->cbc('BuildingNumber', $p['buildingNumber']));
        $addr->appendChild($this->cbc('CitySubdivisionName', $p['district']));
        $addr->appendChild($this->cbc('CityName', $p['city']));
        $addr->appendChild($this->cbc('PostalZone', $p['postalZone']));
        $country = $this->cac('Country');
        $country->appendChild($this->cbc('IdentificationCode', $p['countryCode']));
        $addr->appendChild($country);
        $party->appendChild($addr);

        $taxScheme = $this->cac('PartyTaxScheme');
        $taxScheme->appendChild($this->cbc('CompanyID', $p['vatNumber']));
        $scheme = $this->cac('TaxScheme');
        $scheme->appendChild($this->cbc('ID', 'VAT'));
        $taxScheme->appendChild($scheme);
        $party->appendChild($taxScheme);

        $legal = $this->cac('PartyLegalEntity');
        $legal->appendChild($this->cbc('RegistrationName', $p['name']));
        $party->appendChild($legal);

        return $party;
    }

    public function addSupplier(array $p): void
    {
        $wrapper = $this->cac('AccountingSupplierParty');
        $wrapper->appendChild($this->buildParty($p, true));
        $this->root->appendChild($wrapper);
    }

    public function addCustomer(array $p): void
    {
        $wrapper = $this->cac('AccountingCustomerParty');
        $wrapper->appendChild($this->buildParty($p, false));
        $this->root->appendChild($wrapper);
    }

    public function addDelivery(string $date): void
    {
        $delivery = $this->cac('Delivery');
        $delivery->appendChild($this->cbc('ActualDeliveryDate', $date));
        $this->root->appendChild($delivery);
    }

    /** e.g. 10 = cash, per UN/EDIFACT 4461 code list */
    public function addPaymentMeans(string $code): void
    {
        $pm = $this->cac('PaymentMeans');
        $pm->appendChild($this->cbc('PaymentMeansCode', $code));
        $this->root->appendChild($pm);
    }

    public function addDocumentAllowanceCharge(
        bool $isCharge,
        string $reason,
        float $amount,
        float $vatPercent,
        string $currency = 'SAR'
    ): void {
        $ac = $this->cac('AllowanceCharge');
        $ac->appendChild($this->cbc('ChargeIndicator', $isCharge ? 'true' : 'false'));
        $ac->appendChild($this->cbc('AllowanceChargeReason', $reason));
        $ac->appendChild($this->cbc('Amount', number_format($amount, 2, '.', ''), ['currencyID' => $currency]));

        $cat = $this->cac('TaxCategory');
        $cat->appendChild($this->cbc('ID', 'S', ['schemeID' => 'UN/ECE 5305', 'schemeAgencyID' => '6']));
        $cat->appendChild($this->cbc('Percent', (string) $vatPercent));
        $scheme = $this->cac('TaxScheme');
        $scheme->appendChild($this->cbc('ID', 'VAT', ['schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']));
        $cat->appendChild($scheme);
        $ac->appendChild($cat);

        $this->root->appendChild($ac);
    }

    /**
     * Takes a plain array of line items and does everything:
     * - computes each line's extension amount, VAT amount, and rounding total
     * - appends each <cac:InvoiceLine>
     * - groups lines by VAT category (taxCategory + vatPercent) and appends
     *   the correct <cac:TaxTotal> / <cac:TaxSubtotal> breakdown per group
     * - appends <cac:LegalMonetaryTotal> summed across all lines
     *
     * Each $item in $items may contain:
     *   'id'            (optional, auto-incremented from 1 if omitted)
     *   'quantity'      (float, required)
     *   'unitCode'      (string, e.g. 'PCE', default 'PCE')
     *   'unitPrice'     (float, required — price per unit before tax)
     *   'itemName'      (string, required)
     *   'vatPercent'    (float, default 15.00)
     *   'taxCategory'   (string, UN/ECE 5305 code: 'S' standard, 'Z' zero-rated,
     *                    'E' exempt, 'O' out of scope — default 'S')
     *   'lineDiscount'  (float, optional per-line discount before tax, default 0)
     *
     * Optional global params:
     *   $documentAllowanceAmount — a document-level discount already applied
     *                              elsewhere (e.g. via addDocumentAllowanceCharge),
     *                              subtracted from TaxExclusiveAmount for LegalMonetaryTotal
     *   $prepaidAmount           — amount already paid in advance
     */
    public function addInvoiceLinesWithTotals(
        array $items,
        float $documentAllowanceAmount = 0.0,
        float $prepaidAmount = 0.0,
        string $currency = 'SAR'
    ): void {
        $lineExtensionTotal = 0.0;
        $taxTotal = 0.0;
        // Grouped by "percent|category" => ['taxableAmount' => x, 'taxAmount' => y, 'percent' => p, 'category' => c]
        $groups = [];

        // UBL requires TaxTotal(s) and LegalMonetaryTotal to appear BEFORE
        // InvoiceLine elements. We build each line's DOMElement here but hold
        // it in memory, so totals can be computed and inserted first, then
        // the line elements appended afterward in the correct schema order.
        $lineElements = [];

        $nextId = 1;
        foreach ($items as $item) {
            $id = $item['id'] ?? $nextId;
            $nextId = is_numeric($id) ? ((int) $id) + 1 : $nextId + 1;

            $quantity    = (float) $item['quantity'];
            $unitCode    = $item['unitCode'] ?? 'PCE';
            $unitPrice   = (float) $item['unitPrice'];
            $itemName    = $item['itemName'];
            $vatPercent  = (float) ($item['vatPercent'] ?? 15.00);
            $taxCategory = $item['taxCategory'] ?? 'S';
            $lineDiscount = (float) ($item['lineDiscount'] ?? 0.0);

            $lineExtensionAmount = round(($quantity * $unitPrice) - $lineDiscount, 2);
            $lineTaxAmount       = round($lineExtensionAmount * $vatPercent / 100, 2);
            $roundingAmount      = round($lineExtensionAmount + $lineTaxAmount, 2);

            $lineExtensionTotal += $lineExtensionAmount;
            $taxTotal           += $lineTaxAmount;

            $groupKey = $vatPercent . '|' . $taxCategory;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'taxableAmount' => 0.0,
                    'taxAmount'     => 0.0,
                    'percent'       => $vatPercent,
                    'category'      => $taxCategory,
                ];
            }
            $groups[$groupKey]['taxableAmount'] += $lineExtensionAmount;
            $groups[$groupKey]['taxAmount']     += $lineTaxAmount;

            // --- append <cac:InvoiceLine> ---
            $il = $this->cac('InvoiceLine');
            $il->appendChild($this->cbc('ID', (string) $id));
            $il->appendChild($this->cbc(
                'InvoicedQuantity', number_format($quantity, 6, '.', ''), ['unitCode' => $unitCode]
            ));
            $il->appendChild($this->cbc(
                'LineExtensionAmount', number_format($lineExtensionAmount, 2, '.', ''), ['currencyID' => $currency]
            ));

            $lineTaxTotalEl = $this->cac('TaxTotal');
            $lineTaxTotalEl->appendChild($this->cbc(
                'TaxAmount', number_format($lineTaxAmount, 2, '.', ''), ['currencyID' => $currency]
            ));
            $lineTaxTotalEl->appendChild($this->cbc(
                'RoundingAmount', number_format($roundingAmount, 2, '.', ''), ['currencyID' => $currency]
            ));
            $il->appendChild($lineTaxTotalEl);

            $itemEl = $this->cac('Item');
            $itemEl->appendChild($this->cbc('Name', $itemName));
            $classified = $this->cac('ClassifiedTaxCategory');
            $classified->appendChild($this->cbc('ID', $taxCategory));
            $classified->appendChild($this->cbc('Percent', number_format($vatPercent, 2, '.', '')));
            $scheme = $this->cac('TaxScheme');
            $scheme->appendChild($this->cbc('ID', 'VAT'));
            $classified->appendChild($scheme);
            $itemEl->appendChild($classified);
            $il->appendChild($itemEl);

            $price = $this->cac('Price');
            $price->appendChild($this->cbc('PriceAmount', number_format($unitPrice, 2, '.', ''), ['currencyID' => $currency]));
            $il->appendChild($price);

            $lineElements[] = $il;
        }

        $lineExtensionTotal = round($lineExtensionTotal, 2);
        $taxTotal = round($taxTotal, 2);

        // --- Document-level TaxTotal (no breakdown) ---
        $t1 = $this->cac('TaxTotal');
        $t1->appendChild($this->cbc('TaxAmount', number_format($taxTotal, 2, '.', ''), ['currencyID' => $currency]));
        $this->root->appendChild($t1);

        // --- Second TaxTotal with one TaxSubtotal per VAT-rate/category group ---
        $t2 = $this->cac('TaxTotal');
        $t2->appendChild($this->cbc('TaxAmount', number_format($taxTotal, 2, '.', ''), ['currencyID' => $currency]));

        foreach ($groups as $g) {
            $sub = $this->cac('TaxSubtotal');
            $sub->appendChild($this->cbc('TaxableAmount', number_format($g['taxableAmount'], 2, '.', ''), ['currencyID' => $currency]));
            $sub->appendChild($this->cbc('TaxAmount', number_format($g['taxAmount'], 2, '.', ''), ['currencyID' => $currency]));

            $cat = $this->cac('TaxCategory');
            $cat->appendChild($this->cbc('ID', $g['category'], ['schemeID' => 'UN/ECE 5305', 'schemeAgencyID' => '6']));
            $cat->appendChild($this->cbc('Percent', number_format($g['percent'], 2, '.', '')));
            $scheme = $this->cac('TaxScheme');
            $scheme->appendChild($this->cbc('ID', 'VAT', ['schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']));
            $cat->appendChild($scheme);
            $sub->appendChild($cat);

            $t2->appendChild($sub);
        }
        $this->root->appendChild($t2);

        // --- LegalMonetaryTotal, all derived from the computed totals above ---
        $taxExclusive = round($lineExtensionTotal - $documentAllowanceAmount, 2);
        $taxInclusive = round($taxExclusive + $taxTotal, 2);
        $payable      = round($taxInclusive - $prepaidAmount, 2);

        $lmt = $this->cac('LegalMonetaryTotal');
        $fields = [
            'LineExtensionAmount'  => $lineExtensionTotal,
            'TaxExclusiveAmount'   => $taxExclusive,
            'TaxInclusiveAmount'   => $taxInclusive,
            'AllowanceTotalAmount' => $documentAllowanceAmount,
            'PrepaidAmount'        => $prepaidAmount,
            'PayableAmount'        => $payable,
        ];
        foreach ($fields as $name => $value) {
            $lmt->appendChild($this->cbc($name, number_format($value, 2, '.', ''), ['currencyID' => $currency]));
        }
        $this->root->appendChild($lmt);

        // Now that TaxTotal(s) and LegalMonetaryTotal are in place, append
        // the previously-built InvoiceLine elements in their original order.
        foreach ($lineElements as $il) {
            $this->root->appendChild($il);
        }
    }

    /**
     * PHP's DOMDocument re-declares xmlns:* on nearly every element created
     * via createElementNS, even when an ancestor already declares the same
     * prefix -> URI binding. This is harmless (still valid XML) but bloats
     * the output. Note: these auto-generated declarations are NOT exposed
     * via $element->attributes (DOMNamedNodeMap enumeration) — only
     * hasAttribute()/removeAttribute() can see them — so we check for each
     * known prefix explicitly, in document order, keeping only the first
     * (topmost) declaration and stripping the rest.
     */
    private function stripRedundantNamespaceDeclarations(): void
    {
        $prefixes = ['xmlns:cac', 'xmlns:cbc', 'xmlns:ext', 'xmlns:sig', 'xmlns:sac', 'xmlns:sbc', 'xmlns:ds', 'xmlns:xades'];
        $xpath = new DOMXPath($this->doc);
        foreach ($prefixes as $attrName) {
            $kept = false;
            foreach ($xpath->query('//*') as $el) {
                /** @var DOMElement $el */
                if ($el->hasAttribute($attrName)) {
                    if ($kept) {
                        $el->removeAttribute($attrName);
                    } else {
                        $kept = true; // first occurrence in document order stays
                    }
                }
            }
        }
    }

    public function toXml(): string
    {
        $this->stripRedundantNamespaceDeclarations();
        return $this->doc->saveXML();
    }

    public function save(string $path): void
    {
        $this->stripRedundantNamespaceDeclarations();
        $this->doc->save($path);
    }
}

// -----------------------------------------------------------------
// Example usage — reproduces the structure of the sample invoice
// -----------------------------------------------------------------

$invoice = new ZatcaInvoiceBuilder();

// NOTE: passing no real crypto values below produces a document that is
// structurally schema-valid (fixes the XSD_SCHEMA_ERROR you saw) but is
// NOT cryptographically signed. Replace with real values from your signing
// routine before submitting to ZATCA. See addUblExtensions() docblock above
// for the expected array keys.
$invoice->addUblExtensions();

$invoice->addHeader([
    'profileId'    => 'reporting:1.0',
    'id'           => 'SME00023',
    'uuid'         => '8d487816-70b8-4ade-a618-9d620b73814a',
    'issueDate'    => '2022-09-07',
    'issueTime'    => '12:21:28',
    'typeCode'     => '388',
    'typeCodeName' => '0100000',
    'currency'     => 'SAR',
    'taxCurrency'  => 'SAR',
]);

$invoice->addIcv(23);
$invoice->addPih('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ=='); // replace with real hash of previous invoice
$invoice->addQr('PENDING_REAL_QR_TLV_BASE64'); // replace with real generated QR TLV
$invoice->addSignatureReference();

$invoice->addSupplier([
    'crn'            => '1010010000',
    'street'         => 'الامير سلطان | Prince Sultan',
    'buildingNumber' => '2322',
    'district'       => 'المربع | Al-Murabba',
    'city'           => 'الرياض | Riyadh',
    'postalZone'     => '23333',
    'countryCode'    => 'SA',
    'vatNumber'      => '399999999900003',
    'name'           => 'شركة توريد التكنولوجيا بأقصى سرعة المحدودة | Maximum Speed Tech Supply LTD',
]);

$invoice->addCustomer([
    'street'         => 'صلاح الدين | Salah Al-Din',
    'buildingNumber' => '1111',
    'district'       => 'المروج | Al-Murooj',
    'city'           => 'الرياض | Riyadh',
    'postalZone'     => '12222',
    'countryCode'    => 'SA',
    'vatNumber'      => '399999999800003',
    'name'           => 'شركة نماذج فاتورة المحدودة | Fatoora Samples LTD',
]);

$invoice->addDelivery('2022-09-07');
$invoice->addPaymentMeans('10'); // 10 = cash

$invoice->addDocumentAllowanceCharge(false, 'discount', 0.00, 15);

// --- Line items go in as a plain array. Totals (line amounts, VAT per
// category, and the LegalMonetaryTotal) are all calculated automatically. ---
$lineItems = [
    [
        'quantity'   => 2.000000,
        'unitCode'   => 'PCE',
        'unitPrice'  => 2.00,
        'itemName'   => 'قلم رصاص', // pencil
        'vatPercent' => 15.00,
        // 'taxCategory' => 'S', // optional, defaults to 'S' (standard-rated)
    ],
    // Add as many items as you need, e.g.:
    // [
    //     'quantity'   => 1,
    //     'unitCode'   => 'PCE',
    //     'unitPrice'  => 10.00,
    //     'itemName'   => 'دفتر ملاحظات', // notebook
    //     'vatPercent' => 15.00,
    // ],
    // [
    //     'quantity'    => 3,
    //     'unitCode'    => 'PCE',
    //     'unitPrice'   => 5.00,
    //     'itemName'    => 'كتاب', // book, zero-rated example
    //     'vatPercent'  => 0.00,
    //     'taxCategory' => 'Z',
    // ],
];

$invoice->addInvoiceLinesWithTotals(
    $lineItems,
    documentAllowanceAmount: 0.00, // any document-level discount already applied
    prepaidAmount: 0.00            // any amount already paid in advance
);

echo $invoice->toXml();
$invoice->save(__DIR__ . '/standard_vishnu_invoice.xml');
