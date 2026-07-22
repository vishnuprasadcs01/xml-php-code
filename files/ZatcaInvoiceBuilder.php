<?php

declare(strict_types=1);

namespace Zatca;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Builds a ZATCA Phase 2 (Integration Phase) UBL 2.1 invoice XML with
 * support for multiple invoice lines.
 *
 * Output of build() is the UNSIGNED invoice: it contains an empty
 * ext:UBLExtensions placeholder, a cac:Signature placeholder, and
 * AdditionalDocumentReference placeholders for ICV / PIH / QR. Pass this
 * string into ZatcaSigner::sign() to produce the final, signed XML.
 *
 * Scope note: this covers the common Standard & Simplified Tax Invoice
 * case (document type 388) with multiple lines, a single VAT scheme,
 * and per-line tax categories. Credit/debit notes, exports, multi-currency
 * conversion rules, and other edge cases are not modeled here and would
 * need extending.
 */
class ZatcaInvoiceBuilder
{
    public const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    public const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private DOMDocument $doc;
    private DOMElement $invoice;

    /** @var array<string,mixed> */
    private array $data;

    /** @var array<int,array<string,mixed>> */
    private array $lines = [];

    /**
     * @param array<string,mixed> $invoiceData {
     *   id: string,                 // e.g. "INV-0001"
     *   uuid: string,               // RFC4122 UUID for this invoice
     *   issueDate: string,          // 'YYYY-MM-DD'
     *   issueTime: string,          // 'HH:MM:SS'
     *   invoiceTypeCode: string,    // '388' invoice, '383' credit note, '381' debit note
     *   invoiceSubtype: string,     // 'standard' or 'simplified'
     *   currency: string,           // default 'SAR'
     *   icv: int,                   // Invoice Counter Value, strictly sequential
     *   previousInvoiceHash: string,// base64 hash of the prior invoice (chain)
     *   seller: array, buyer: array, payment: array, delivery: array
     * }
     */
    public function __construct(array $invoiceData)
    {
        $this->data = $invoiceData;
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->preserveWhiteSpace = false;
    }

    /**
     * Add one invoice line. Call multiple times for multiple line items.
     *
     * @param array<string,mixed> $line {
     *   id: string,            // line number, "1", "2", ...
     *   name: string,          // item/product/service description
     *   quantity: float,
     *   unitCode: string,      // UN/ECE Rec 20 code, e.g. 'PCE', 'KGM', 'HUR'
     *   unitPrice: float,      // price excluding VAT
     *   taxPercent: float,     // e.g. 15.00, or 0 for zero-rated/exempt
     *   taxCategory?: string,  // 'S' standard, 'Z' zero-rated, 'E' exempt, 'O' out of scope
     *   discount?: float       // monetary discount applied to the line, default 0
     * }
     */
    public function addLine(array $line): self
    {
        foreach (['id', 'name', 'quantity', 'unitCode', 'unitPrice', 'taxPercent'] as $required) {
            if (!array_key_exists($required, $line)) {
                throw new RuntimeException("Invoice line is missing required field '{$required}'");
            }
        }

        $line['discount'] = (float) ($line['discount'] ?? 0.0);
        $line['taxCategory'] = $line['taxCategory'] ?? ((float) $line['taxPercent'] === 0.0 ? 'Z' : 'S');

        $lineExtension = round(((float) $line['quantity'] * (float) $line['unitPrice']) - $line['discount'], 2);
        $taxAmount = round($lineExtension * ((float) $line['taxPercent'] / 100), 2);

        $line['lineExtensionAmount'] = $lineExtension;
        $line['taxAmount'] = $taxAmount;

        $this->lines[] = $line;

        return $this;
    }

    public function build(): string
    {
        if (empty($this->lines)) {
            throw new RuntimeException('At least one invoice line is required (call addLine()).');
        }

        $this->invoice = $this->doc->createElementNS(self::NS_INVOICE, 'Invoice');
        $this->invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $this->invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $this->invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::NS_EXT);
        $this->doc->appendChild($this->invoice);

        // Order below follows the UBL/ZATCA CIUS sequence. Do not reorder
        // these calls -- the schema is order-sensitive.
        $this->addExtensionsPlaceholder();
        $this->addHeader();
        $this->addAdditionalDocumentReferences();
        $this->addSignaturePlaceholder();
        $this->addSupplierParty();
        $this->addCustomerParty();
        $this->addDelivery();
        $this->addPaymentMeans();
        $this->addTaxTotal();
        $this->addLegalMonetaryTotal();
        $this->addInvoiceLines();

        return $this->doc->saveXML();
    }

    private function cbc(string $name, string $value, array $attrs = []): DOMElement
    {
        $el = $this->doc->createElementNS(self::NS_CBC, 'cbc:' . $name, $value);
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, (string) $v);
        }
        return $el;
    }

    private function cac(string $name): DOMElement
    {
        return $this->doc->createElementNS(self::NS_CAC, 'cac:' . $name);
    }

    private function addExtensionsPlaceholder(): void
    {
        // Left empty here. ZatcaSigner locates this node and fills it
        // with the ds:Signature / XAdES block after hashing & signing.
        $ext = $this->doc->createElementNS(self::NS_EXT, 'ext:UBLExtensions');
        $this->invoice->appendChild($ext);
    }

    private function addHeader(): void
    {
        $d = $this->data;
        $subtype = ($d['invoiceSubtype'] ?? 'standard') === 'simplified' ? '2' : '1';
        $flags = $d['invoiceTypeFlags'] ?? '00000'; // 3rd-party, nominal, export, summary, self-billed
        $typeName = '0' . $subtype . $flags;

        $this->invoice->appendChild($this->cbc('ProfileID', $d['profileId'] ?? 'reporting:1.0'));
        $this->invoice->appendChild($this->cbc('ID', (string) $d['id']));
        $this->invoice->appendChild($this->cbc('UUID', (string) $d['uuid']));
        $this->invoice->appendChild($this->cbc('IssueDate', (string) $d['issueDate']));
        $this->invoice->appendChild($this->cbc('IssueTime', (string) $d['issueTime']));
        $this->invoice->appendChild($this->cbc('InvoiceTypeCode', (string) ($d['invoiceTypeCode'] ?? '388'), ['name' => $typeName]));

        if (!empty($d['note'])) {
            $this->invoice->appendChild($this->cbc('Note', (string) $d['note']));
        }

        $currency = $d['currency'] ?? 'SAR';
        $this->invoice->appendChild($this->cbc('DocumentCurrencyCode', $currency));
        $this->invoice->appendChild($this->cbc('TaxCurrencyCode', $d['taxCurrency'] ?? $currency));
    }

    private function addAdditionalDocumentReferences(): void
    {
        // ICV - Invoice Counter Value: a strictly sequential integer you
        // must persist per device/seller; never reuse or skip values.
        $icv = $this->cac('AdditionalDocumentReference');
        $icv->appendChild($this->cbc('ID', 'ICV'));
        $icv->appendChild($this->cbc('UUID', (string) $this->data['icv']));
        $this->invoice->appendChild($icv);

        // PIH - Previous Invoice Hash: base64 SHA-256 hash of the
        // previous invoice in the chain. For the very first invoice ever
        // issued, ZATCA expects the hash of the single character "0".
        $pihValue = $this->data['previousInvoiceHash']
            ?? base64_encode(hash('sha256', '0', true));

        $pih = $this->cac('AdditionalDocumentReference');
        $pih->appendChild($this->cbc('ID', 'PIH'));
        $attachment = $this->cac('Attachment');
        $embedded = $this->cbc('EmbeddedDocumentBinaryObject', $pihValue, ['mimeCode' => 'text/plain']);
        $attachment->appendChild($embedded);
        $pih->appendChild($attachment);
        $this->invoice->appendChild($pih);

        // QR - placeholder; ZatcaSigner fills in the real TLV/base64 value
        // once the invoice hash, signature, and certificate are known.
        $qr = $this->cac('AdditionalDocumentReference');
        $qr->appendChild($this->cbc('ID', 'QR'));
        $qrAttachment = $this->cac('Attachment');
        $qrEmbedded = $this->cbc('EmbeddedDocumentBinaryObject', '', ['mimeCode' => 'text/plain']);
        $qrAttachment->appendChild($qrEmbedded);
        $qr->appendChild($qrAttachment);
        $this->invoice->appendChild($qr);
    }

    private function addSignaturePlaceholder(): void
    {
        $sig = $this->cac('Signature');
        $sig->appendChild($this->cbc('ID', 'urn:oasis:names:specification:ubl:signature:Invoice'));
        $sig->appendChild($this->cbc('SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));
        $this->invoice->appendChild($sig);
    }

    private function buildParty(array $party): DOMElement
    {
        $partyEl = $this->cac('Party');

        if (!empty($party['schemeId']) && !empty($party['schemeValue'])) {
            $pid = $this->cac('PartyIdentification');
            $pid->appendChild($this->cbc('ID', (string) $party['schemeValue'], ['schemeID' => (string) $party['schemeId']]));
            $partyEl->appendChild($pid);
        }

        $addr = $this->cac('PostalAddress');
        $addrFields = [
            'StreetName' => $party['street'] ?? '',
            'BuildingNumber' => $party['buildingNumber'] ?? '',
            'CitySubdivisionName' => $party['district'] ?? '',
            'CityName' => $party['city'] ?? '',
            'PostalZone' => $party['postalZone'] ?? '',
        ];
        foreach ($addrFields as $tag => $val) {
            if ($val !== '') {
                $addr->appendChild($this->cbc($tag, (string) $val));
            }
        }
        $country = $this->cac('Country');
        $country->appendChild($this->cbc('IdentificationCode', $party['countryCode'] ?? 'SA'));
        $addr->appendChild($country);
        $partyEl->appendChild($addr);

        if (!empty($party['vatNumber'])) {
            $taxScheme = $this->cac('PartyTaxScheme');
            $taxScheme->appendChild($this->cbc('CompanyID', (string) $party['vatNumber']));
            $scheme = $this->cac('TaxScheme');
            $scheme->appendChild($this->cbc('ID', 'VAT'));
            $taxScheme->appendChild($scheme);
            $partyEl->appendChild($taxScheme);
        }

        $legal = $this->cac('PartyLegalEntity');
        $legal->appendChild($this->cbc('RegistrationName', (string) ($party['legalName'] ?? $party['name'] ?? '')));
        $partyEl->appendChild($legal);

        return $partyEl;
    }

    private function addSupplierParty(): void
    {
        $supplier = $this->cac('AccountingSupplierParty');
        $supplier->appendChild($this->buildParty($this->data['seller']));
        $this->invoice->appendChild($supplier);
    }

    private function addCustomerParty(): void
    {
        $customer = $this->cac('AccountingCustomerParty');
        $customer->appendChild($this->buildParty($this->data['buyer'] ?? []));
        $this->invoice->appendChild($customer);
    }

    private function addDelivery(): void
    {
        $deliveryDate = $this->data['delivery']['actualDeliveryDate'] ?? null;
        if (empty($deliveryDate)) {
            return;
        }
        $del = $this->cac('Delivery');
        $del->appendChild($this->cbc('ActualDeliveryDate', (string) $deliveryDate));
        $this->invoice->appendChild($del);
    }

    private function addPaymentMeans(): void
    {
        $payment = $this->cac('PaymentMeans');
        $code = $this->data['payment']['meansCode'] ?? '10'; // 10 = cash, 30 = credit, 42 = bank transfer
        $payment->appendChild($this->cbc('PaymentMeansCode', (string) $code));
        if (!empty($this->data['payment']['instructionNote'])) {
            $payment->appendChild($this->cbc('InstructionNote', (string) $this->data['payment']['instructionNote']));
        }
        $this->invoice->appendChild($payment);
    }

    /** @return array<string,array{taxableAmount:float,taxAmount:float,percent:float,category:string}> */
    private function taxBreakdown(): array
    {
        $byCategory = [];
        foreach ($this->lines as $line) {
            $key = $line['taxCategory'] . '|' . $line['taxPercent'];
            if (!isset($byCategory[$key])) {
                $byCategory[$key] = [
                    'taxableAmount' => 0.0,
                    'taxAmount' => 0.0,
                    'percent' => (float) $line['taxPercent'],
                    'category' => $line['taxCategory'],
                ];
            }
            $byCategory[$key]['taxableAmount'] += $line['lineExtensionAmount'];
            $byCategory[$key]['taxAmount'] += $line['taxAmount'];
        }
        return $byCategory;
    }

    private function addTaxTotal(): void
    {
        $currency = $this->data['currency'] ?? 'SAR';
        $breakdown = $this->taxBreakdown();
        $totalTax = array_sum(array_column($breakdown, 'taxAmount'));

        $taxTotal = $this->cac('TaxTotal');
        $taxTotal->appendChild($this->cbc('TaxAmount', number_format($totalTax, 2, '.', ''), ['currencyID' => $currency]));

        foreach ($breakdown as $row) {
            $sub = $this->cac('TaxSubtotal');
            $sub->appendChild($this->cbc('TaxableAmount', number_format($row['taxableAmount'], 2, '.', ''), ['currencyID' => $currency]));
            $sub->appendChild($this->cbc('TaxAmount', number_format($row['taxAmount'], 2, '.', ''), ['currencyID' => $currency]));
            $cat = $this->cac('TaxCategory');
            $cat->appendChild($this->cbc('ID', $row['category']));
            $cat->appendChild($this->cbc('Percent', number_format($row['percent'], 2, '.', '')));
            $scheme = $this->cac('TaxScheme');
            $scheme->appendChild($this->cbc('ID', 'VAT'));
            $cat->appendChild($scheme);
            $sub->appendChild($cat);
            $taxTotal->appendChild($sub);
        }

        $this->invoice->appendChild($taxTotal);
    }

    private function addLegalMonetaryTotal(): void
    {
        $currency = $this->data['currency'] ?? 'SAR';
        $lineExtensionTotal = array_sum(array_column($this->lines, 'lineExtensionAmount'));
        $taxTotal = array_sum(array_column($this->lines, 'taxAmount'));
        $taxExclusive = $lineExtensionTotal;
        $taxInclusive = round($lineExtensionTotal + $taxTotal, 2);
        $prepaid = (float) ($this->data['prepaidAmount'] ?? 0.0);
        $payable = round($taxInclusive - $prepaid, 2);

        $totals = $this->cac('LegalMonetaryTotal');
        $totals->appendChild($this->cbc('LineExtensionAmount', number_format($lineExtensionTotal, 2, '.', ''), ['currencyID' => $currency]));
        $totals->appendChild($this->cbc('TaxExclusiveAmount', number_format($taxExclusive, 2, '.', ''), ['currencyID' => $currency]));
        $totals->appendChild($this->cbc('TaxInclusiveAmount', number_format($taxInclusive, 2, '.', ''), ['currencyID' => $currency]));
        $totals->appendChild($this->cbc('PrepaidAmount', number_format($prepaid, 2, '.', ''), ['currencyID' => $currency]));
        $totals->appendChild($this->cbc('PayableAmount', number_format($payable, 2, '.', ''), ['currencyID' => $currency]));
        $this->invoice->appendChild($totals);
    }

    private function addInvoiceLines(): void
    {
        $currency = $this->data['currency'] ?? 'SAR';

        foreach ($this->lines as $line) {
            $lineEl = $this->cac('InvoiceLine');
            $lineEl->appendChild($this->cbc('ID', (string) $line['id']));
            $lineEl->appendChild($this->cbc('InvoicedQuantity', (string) $line['quantity'], ['unitCode' => $line['unitCode']]));
            $lineEl->appendChild($this->cbc('LineExtensionAmount', number_format($line['lineExtensionAmount'], 2, '.', ''), ['currencyID' => $currency]));

            if ($line['discount'] > 0) {
                $allowance = $this->cac('AllowanceCharge');
                $allowance->appendChild($this->cbc('ChargeIndicator', 'false'));
                $allowance->appendChild($this->cbc('AllowanceChargeReason', 'Discount'));
                $allowance->appendChild($this->cbc('Amount', number_format($line['discount'], 2, '.', ''), ['currencyID' => $currency]));
                $lineEl->appendChild($allowance);
            }

            $lineTax = $this->cac('TaxTotal');
            $lineTax->appendChild($this->cbc('TaxAmount', number_format($line['taxAmount'], 2, '.', ''), ['currencyID' => $currency]));
            $lineTax->appendChild($this->cbc('RoundingAmount', number_format($line['lineExtensionAmount'] + $line['taxAmount'], 2, '.', ''), ['currencyID' => $currency]));
            $lineEl->appendChild($lineTax);

            $item = $this->cac('Item');
            $item->appendChild($this->cbc('Name', (string) $line['name']));
            $classified = $this->cac('ClassifiedTaxCategory');
            $classified->appendChild($this->cbc('ID', $line['taxCategory']));
            $classified->appendChild($this->cbc('Percent', number_format((float) $line['taxPercent'], 2, '.', '')));
            $scheme = $this->cac('TaxScheme');
            $scheme->appendChild($this->cbc('ID', 'VAT'));
            $classified->appendChild($scheme);
            $item->appendChild($classified);
            $lineEl->appendChild($item);

            $price = $this->cac('Price');
            $price->appendChild($this->cbc('PriceAmount', number_format((float) $line['unitPrice'], 2, '.', ''), ['currencyID' => $currency]));
            $lineEl->appendChild($price);

            $this->invoice->appendChild($lineEl);
        }
    }
}
