<?php

class ZatcaInvoiceGenerator
{
    private DOMDocument $xml;
    private DOMElement $invoice;

    private string $cbc = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private string $cac = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private string $ext = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public function generate(array $invoiceData): string
    {
        $this->xml = new DOMDocument('1.0', 'UTF-8');
        $this->xml->formatOutput = true;

        // Root Invoice
        $this->invoice = $this->xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            'Invoice'
        );

        $this->invoice->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cac',
            $this->cac
        );

        $this->invoice->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cbc',
            $this->cbc
        );

        $this->invoice->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ext',
            $this->ext
        );

        $this->xml->appendChild($this->invoice);

        // =========================
        // BASIC INVOICE DETAILS
        // =========================

        $this->addCbc('ProfileID', 'reporting:1.0');
        $this->addCbc('ID', $invoiceData['invoice_number']);
        $this->addCbc('UUID', $invoiceData['uuid']);
        $this->addCbc('IssueDate', $invoiceData['issue_date']);
        $this->addCbc('IssueTime', $invoiceData['issue_time']);

        $invoiceType = $this->createElement('cbc', 'InvoiceTypeCode', '388');
        $invoiceType->setAttribute('name', '0100000');
        $this->invoice->appendChild($invoiceType);

        $this->addCbc('DocumentCurrencyCode', 'SAR');
        $this->addCbc('TaxCurrencyCode', 'SAR');

        // =========================
        // ADDITIONAL DOCUMENTS
        // =========================

        $this->addAdditionalDocumentReference('ICV', $invoiceData['icv']);

        $this->addPIH($invoiceData['pih']);

        $this->addQR($invoiceData['qr']);

        // =========================
        // SIGNATURE
        // =========================

        $signature = $this->createElement('cac', 'Signature');

        $signature->appendChild(
            $this->createElement(
                'cbc',
                'ID',
                'urn:oasis:names:specification:ubl:signature:Invoice'
            )
        );

        $signature->appendChild(
            $this->createElement(
                'cbc',
                'SignatureMethod',
                'urn:oasis:names:specification:ubl:dsig:enveloped:xades'
            )
        );

        $this->invoice->appendChild($signature);

        // =========================
        // SUPPLIER
        // =========================

        $this->addSupplier($invoiceData['supplier']);

        // =========================
        // CUSTOMER
        // =========================

        $this->addCustomer($invoiceData['customer']);

        // =========================
        // DELIVERY
        // =========================

        $delivery = $this->createElement('cac', 'Delivery');

        $delivery->appendChild(
            $this->createElement(
                'cbc',
                'ActualDeliveryDate',
                $invoiceData['issue_date']
            )
        );

        $this->invoice->appendChild($delivery);

        // =========================
        // PAYMENT
        // =========================

        $payment = $this->createElement('cac', 'PaymentMeans');

        $payment->appendChild(
            $this->createElement('cbc', 'PaymentMeansCode', '10')
        );

        $this->invoice->appendChild($payment);

        // =========================
        // CALCULATIONS
        // =========================

        $subTotal = 0;
        $taxTotal = 0;
        $discount = $invoiceData['discount'] ?? 0;

        foreach ($invoiceData['items'] as $item) {

            $qty       = (float)$item['qty'];
            $price     = (float)$item['price'];
            $vat       = (float)$item['vat_percent'];

            $lineTotal = $qty * $price;
            $lineVat   = ($lineTotal * $vat) / 100;

            $subTotal += $lineTotal;
            $taxTotal += $lineVat;
        }

        $taxExclusive = $subTotal - $discount;
        $taxInclusive = $taxExclusive + $taxTotal;

        // =========================
        // ALLOWANCE / DISCOUNT
        // =========================

        $allowance = $this->createElement('cac', 'AllowanceCharge');

        $allowance->appendChild(
            $this->createElement('cbc', 'ChargeIndicator', 'false')
        );

        $allowance->appendChild(
            $this->createElement('cbc', 'AllowanceChargeReason', 'discount')
        );

        $amount = $this->createElement(
            'cbc',
            'Amount',
            number_format($discount, 2, '.', '')
        );

        $amount->setAttribute('currencyID', 'SAR');

        $allowance->appendChild($amount);

        $this->invoice->appendChild($allowance);

        // =========================
        // TAX TOTAL
        // =========================

        $taxTotalNode = $this->createElement('cac', 'TaxTotal');

        $taxAmount = $this->createElement(
            'cbc',
            'TaxAmount',
            number_format($taxTotal, 2, '.', '')
        );

        $taxAmount->setAttribute('currencyID', 'SAR');

        $taxTotalNode->appendChild($taxAmount);

        $this->invoice->appendChild($taxTotalNode);

        // =========================
        // LEGAL MONETARY TOTAL
        // =========================

        $legal = $this->createElement('cac', 'LegalMonetaryTotal');

        $legal->appendChild(
            $this->currencyNode(
                'LineExtensionAmount',
                $subTotal
            )
        );

        $legal->appendChild(
            $this->currencyNode(
                'TaxExclusiveAmount',
                $taxExclusive
            )
        );

        $legal->appendChild(
            $this->currencyNode(
                'TaxInclusiveAmount',
                $taxInclusive
            )
        );

        $legal->appendChild(
            $this->currencyNode(
                'AllowanceTotalAmount',
                $discount
            )
        );

        $legal->appendChild(
            $this->currencyNode(
                'PayableAmount',
                $taxInclusive
            )
        );

        $this->invoice->appendChild($legal);

        // =========================
        // INVOICE LINES
        // =========================

        $lineId = 1;

        foreach ($invoiceData['items'] as $item) {

            $qty       = (float)$item['qty'];
            $price     = (float)$item['price'];
            $vat       = (float)$item['vat_percent'];

            $lineTotal = $qty * $price;
            $lineVat   = ($lineTotal * $vat) / 100;
            $lineGross = $lineTotal + $lineVat;

            $line = $this->createElement('cac', 'InvoiceLine');

            $line->appendChild(
                $this->createElement('cbc', 'ID', $lineId++)
            );

            $qtyNode = $this->createElement(
                'cbc',
                'InvoicedQuantity',
                number_format($qty, 6, '.', '')
            );

            $qtyNode->setAttribute('unitCode', 'PCE');

            $line->appendChild($qtyNode);

            $line->appendChild(
                $this->currencyNode(
                    'LineExtensionAmount',
                    $lineTotal
                )
            );

            // Tax Total
            $lineTax = $this->createElement('cac', 'TaxTotal');

            $lineTax->appendChild(
                $this->currencyNode(
                    'TaxAmount',
                    $lineVat
                )
            );

            $lineTax->appendChild(
                $this->currencyNode(
                    'RoundingAmount',
                    $lineGross
                )
            );

            $line->appendChild($lineTax);

            // Item
            $itemNode = $this->createElement('cac', 'Item');

            $itemNode->appendChild(
                $this->createElement(
                    'cbc',
                    'Name',
                    $item['name']
                )
            );

            $taxCategory = $this->createElement(
                'cac',
                'ClassifiedTaxCategory'
            );

            $taxCategory->appendChild(
                $this->createElement('cbc', 'ID', 'S')
            );

            $taxCategory->appendChild(
                $this->createElement(
                    'cbc',
                    'Percent',
                    number_format($vat, 2, '.', '')
                )
            );

            $taxScheme = $this->createElement('cac', 'TaxScheme');

            $taxScheme->appendChild(
                $this->createElement('cbc', 'ID', 'VAT')
            );

            $taxCategory->appendChild($taxScheme);

            $itemNode->appendChild($taxCategory);

            $line->appendChild($itemNode);

            // Price
            $priceNode = $this->createElement('cac', 'Price');

            $priceNode->appendChild(
                $this->currencyNode(
                    'PriceAmount',
                    $price
                )
            );

            $line->appendChild($priceNode);

            $this->invoice->appendChild($line);
        }

        return $this->xml->saveXML();
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    private function addCbc(string $name, string $value): void
    {
        $this->invoice->appendChild(
            $this->createElement('cbc', $name, $value)
        );
    }

    private function createElement(
        string $prefix,
        string $name,
        string $value = ''
    ): DOMElement {

        $ns = match ($prefix) {
            'cbc' => $this->cbc,
            'cac' => $this->cac,
            'ext' => $this->ext,
        };

        return $this->xml->createElementNS(
            $ns,
            $prefix . ':' . $name,
            $value
        );
    }

    private function currencyNode(
        string $name,
        float $amount
    ): DOMElement {

        $node = $this->createElement(
            'cbc',
            $name,
            number_format($amount, 2, '.', '')
        );

        $node->setAttribute('currencyID', 'SAR');

        return $node;
    }

    private function addAdditionalDocumentReference(
        string $id,
        string $uuid
    ): void {

        $doc = $this->createElement(
            'cac',
            'AdditionalDocumentReference'
        );

        $doc->appendChild(
            $this->createElement('cbc', 'ID', $id)
        );

        $doc->appendChild(
            $this->createElement('cbc', 'UUID', $uuid)
        );

        $this->invoice->appendChild($doc);
    }

    private function addPIH(string $hash): void
    {
        $doc = $this->createElement(
            'cac',
            'AdditionalDocumentReference'
        );

        $doc->appendChild(
            $this->createElement('cbc', 'ID', 'PIH')
        );

        $attachment = $this->createElement('cac', 'Attachment');

        $binary = $this->createElement(
            'cbc',
            'EmbeddedDocumentBinaryObject',
            $hash
        );

        $binary->setAttribute('mimeCode', 'text/plain');

        $attachment->appendChild($binary);

        $doc->appendChild($attachment);

        $this->invoice->appendChild($doc);
    }

    private function addQR(string $qr): void
    {
        $doc = $this->createElement(
            'cac',
            'AdditionalDocumentReference'
        );

        $doc->appendChild(
            $this->createElement('cbc', 'ID', 'QR')
        );

        $attachment = $this->createElement('cac', 'Attachment');

        $binary = $this->createElement(
            'cbc',
            'EmbeddedDocumentBinaryObject',
            $qr
        );

        $binary->setAttribute('mimeCode', 'text/plain');

        $attachment->appendChild($binary);

        $doc->appendChild($attachment);

        $this->invoice->appendChild($doc);
    }

    private function addSupplier(array $supplier): void
    {
        $supplierNode = $this->createElement(
            'cac',
            'AccountingSupplierParty'
        );

        $party = $this->createElement('cac', 'Party');

        $legal = $this->createElement(
            'cac',
            'PartyLegalEntity'
        );

        $legal->appendChild(
            $this->createElement(
                'cbc',
                'RegistrationName',
                $supplier['name']
            )
        );

        $party->appendChild($legal);

        $supplierNode->appendChild($party);

        $this->invoice->appendChild($supplierNode);
    }

    private function addCustomer(array $customer): void
    {
        $customerNode = $this->createElement(
            'cac',
            'AccountingCustomerParty'
        );

        $party = $this->createElement('cac', 'Party');

        $legal = $this->createElement(
            'cac',
            'PartyLegalEntity'
        );

        $legal->appendChild(
            $this->createElement(
                'cbc',
                'RegistrationName',
                $customer['name']
            )
        );

        $party->appendChild($legal);

        $customerNode->appendChild($party);

        $this->invoice->appendChild($customerNode);
    }
}

// ======================================================
// SAMPLE USAGE
// ======================================================

$invoiceData = [

    'invoice_number' => 'SME00023',

    'uuid' => '8d487816-70b8-4ade-a618-9d620b73814a',

    'issue_date' => '2022-09-07',

    'issue_time' => '12:21:28',

    'icv' => '23',

    'pih' => 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',

    'qr' => 'BASE64_QR_CODE_HERE',

    'discount' => 0,

    'supplier' => [
        'name' => 'Maximum Speed Tech Supply LTD'
    ],

    'customer' => [
        'name' => 'Fatoora Samples LTD'
    ],

    'items' => [

        [
            'name' => 'Pencil',
            'qty' => 2,
            'price' => 2,
            'vat_percent' => 15
        ],

        [
            'name' => 'Notebook',
            'qty' => 3,
            'price' => 10,
            'vat_percent' => 15
        ],

        [
            'name' => 'Eraser',
            'qty' => 5,
            'price' => 1.5,
            'vat_percent' => 15
        ]
    ]
];

$generator = new ZatcaInvoiceGenerator();

$xml = $generator->generate($invoiceData);



// Save XML
file_put_contents('invoice.xml', $xml);

// Output XML
header('Content-Type: text/plain');


echo $xml;