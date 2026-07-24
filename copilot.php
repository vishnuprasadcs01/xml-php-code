<?php
/**
 * invoice_zatca_phase2.php
 *
 * Example Core PHP script to:
 *  - Build a sample invoice per ZATCA Phase 2 needs
 *  - Generate TLV-encoded QR payload and Base64 it
 *  - Canonicalize JSON, sign with private key from .p12
 *  - Save invoice JSON and a QR PNG image
 *
 * IMPORTANT:
 *  - Replace cert.p12 path and password with your real signing certificate and password.
 *  - Replace sample invoice data with real invoice details.
 *  - Secure your private keys and never commit them to source control.
 */

// -----------------------------
// Helper functions
// -----------------------------
function is_assoc(array $arr) {
    if (array() === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function sort_keys_recursive($data) {
    if (is_array($data)) {
        if (is_assoc($data)) {
            ksort($data);
            foreach ($data as $k => $v) {
                $data[$k] = sort_keys_recursive($v);
            }
        } else {
            // numeric indexed array - sort each element but keep order
            foreach ($data as $i => $v) {
                $data[$i] = sort_keys_recursive($v);
            }
        }
    }
    return $data;
}

/**
 * Canonicalize JSON by sorting keys recursively and encoding with stable options.
 */
function canonicalize_json($data) {
    $sorted = sort_keys_recursive($data);
    return json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * TLV encoding for ZATCA QR: tag (1 byte), length (1 byte), value (utf-8 bytes)
 * Note: This simple implementation assumes lengths < 256 (valid for these fields).
 */
function tlv_encode_field($tag, $value) {
    $value_utf8 = mb_convert_encoding((string)$value, 'UTF-8');
    $len = strlen($value_utf8);
    if ($len > 255) {
        throw new Exception("TLV field too long for simple TLV encoder (tag {$tag})");
    }
    return chr($tag) . chr($len) . $value_utf8;
}

/**
 * Build the ZATCA QR TLV bytes for the main 5 fields:
 * 1 - Seller Name
 * 2 - VAT Registration Number (TIN)
 * 3 - Time stamp (ISO 8601)
 * 4 - Invoice total (with tax) as string
 * 5 - VAT total as string
 */
function build_zatca_qr_tlv($sellerName, $sellerVat, $timestampISO, $invoiceTotal, $vatTotal) {
    $tlv = '';
    $tlv .= tlv_encode_field(1, $sellerName);
    $tlv .= tlv_encode_field(2, $sellerVat);
    $tlv .= tlv_encode_field(3, $timestampISO);
    $tlv .= tlv_encode_field(4, number_format((float)$invoiceTotal, 2, '.', ''));
    $tlv .= tlv_encode_field(5, number_format((float)$vatTotal, 2, '.', ''));
    return $tlv;
}

/**
 * Sign canonical JSON string using private key and return base64(signature).
 * Accepts private key string in PEM format.
 */
function sign_data_with_private_key($dataString, $privateKeyPem, &$signatureBinary = null) {
    $ok = openssl_sign($dataString, $signatureBinary, $privateKeyPem, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new Exception("openssl_sign failed: " . openssl_error_string());
    }
    return base64_encode($signatureBinary);
}

// -----------------------------
// Example invoice data (replace with real data)
// -----------------------------
$invoice = [
    "invoice_number" => "INV-2026-0001",
    "issue_date" => gmdate('Y-m-d\TH:i:s\Z'),
    "seller" => [
        "name" => "ACME Trading Co.",
        "vat_registration_number" => "310122345600003",
        "address" => [
            "line" => "King Fahd Road",
            "city" => "Riyadh",
            "country" => "SA"
        ],
        "tax_scheme" => "VAT"
    ],
    "buyer" => [
        "name" => "Buyer Company Ltd.",
        "vat_registration_number" => "310998877660001",
        "address" => [
            "line" => "Olaya St.",
            "city" => "Riyadh",
            "country" => "SA"
        ]
    ],
    "items" => [
        [
            "description" => "Item A",
            "quantity" => 2,
            "unit_price" => 150.00,
            "net_amount" => 300.00,
            "tax_rate" => 0.15,
            "tax_amount" => 45.00,
            "gross_amount" => 345.00
        ],
        [
            "description" => "Item B",
            "quantity" => 1,
            "unit_price" => 200.00,
            "net_amount" => 200.00,
            "tax_rate" => 0.15,
            "tax_amount" => 30.00,
            "gross_amount" => 230.00
        ]
    ],
    "legal_monetary_total" => [
        "line_extension_amount" => 500.00,
        "tax_exclusive_amount" => 500.00,
        "tax_amount" => 75.00,
        "tax_inclusive_amount" => 575.00,
        "payable_amount" => 575.00
    ],
    // any other ZATCA-specific fields can be added here
];

// -----------------------------
// Build TLV QR payload and base64 encode it
// -----------------------------
$sellerName = $invoice['seller']['name'];
$sellerVat = $invoice['seller']['vat_registration_number'];
$timestampISO = $invoice['issue_date']; // ensure ISO 8601 format as needed by ZATCA (UTC preferred)
$invoiceTotal = $invoice['legal_monetary_total']['tax_inclusive_amount'];
$vatTotal = $invoice['legal_monetary_total']['tax_amount'];

$tlvBytes = build_zatca_qr_tlv($sellerName, $sellerVat, $timestampISO, $invoiceTotal, $vatTotal);
$qrBase64 = base64_encode($tlvBytes);

// Add QR payload to invoice structure for record
$invoice['zatca_qr_tlv_base64'] = $qrBase64;

// -----------------------------
// Load signing certificate / private key from PKCS#12 (.p12) file
// -----------------------------
$pkcs12Path = __DIR__ . '/cert.p12'; // REPLACE with your real path
$pkcs12Password = 'p12password';     // REPLACE with your real password

if (!file_exists($pkcs12Path)) {
    // For demo: we won't stop execution -- just warn and skip signing
    error_log("Warning: PKCS#12 file not found at {$pkcs12Path}. Skipping signing (demo mode).");
    $privateKeyPem = null;
    $certificatePem = null;
} else {
    $pkcs12 = file_get_contents($pkcs12Path);
    $certs = [];
    if (!openssl_pkcs12_read($pkcs12, $certs, $pkcs12Password)) {
        throw new Exception("Unable to read PKCS#12 file with provided password. openssl error: " . openssl_error_string());
    }
    // $certs contains 'pkey' and 'cert' (and possibly 'extracerts')
    $privateKeyPem = $certs['pkey'];
    $certificatePem = $certs['cert'];
}

// -----------------------------
// Canonicalize JSON and sign
// -----------------------------
$canonicalJson = canonicalize_json($invoice);

if ($privateKeyPem) {
    $signatureBinary = null;
    $signatureBase64 = sign_data_with_private_key($canonicalJson, $privateKeyPem, $signatureBinary);

    // embed signature and certificate
    $invoice['signature'] = [
        'algorithm' => 'RSA-SHA256',
        'value' => $signatureBase64,
        // include certificate in PEM or base64-der form. We include PEM and also base64 of DER for convenience.
        'certificate_pem' => $certificatePem,
        'certificate_base64' => base64_encode(openssl_x509_read($certificatePem) ? $certificatePem : $certificatePem)
    ];
} else {
    $invoice['signature'] = [
        'algorithm' => null,
        'value' => null,
        'note' => 'Signing skipped in demo because no cert.p12 found. Replace cert.p12 and password to enable signing.'
    ];
}

// -----------------------------
// Save invoice JSON to disk
// -----------------------------
$outJsonPath = __DIR__ . '/invoice_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice['invoice_number']) . '.json';
file_put_contents($outJsonPath, json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "Saved invoice JSON to: {$outJsonPath}\n";

// -----------------------------
// Create QR image using Google Chart API (fallback, no external libs required).
// You can replace this with a server-side QR lib if you prefer (e.g., chillerlan/php-qrcode).
// -----------------------------
$qrData = $qrBase64;
$size = 300;
$qrUrl = 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size . '&cht=qr&chl=' . rawurlencode($qrData) . '&chld=L|1';
$qrImagePath = __DIR__ . '/invoice_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice['invoice_number']) . '_qr.png';

$qrContents = @file_get_contents($qrUrl);
if ($qrContents !== false) {
    file_put_contents($qrImagePath, $qrContents);
    echo "Saved QR PNG to: {$qrImagePath}\n";
} else {
    echo "Failed to fetch QR from Google Chart API. QR base64 data:\n";
    echo $qrData . "\n";
}

echo "Done.\n\n";
?>