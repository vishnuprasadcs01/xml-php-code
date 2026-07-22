# ZATCA Phase 2 invoice generation + signing (PHP)

Three files:

- **ZatcaInvoiceBuilder.php** — builds the unsigned UBL 2.1 invoice XML, supports any number of `addLine()` calls for multiple line items.
- **ZatcaSigner.php** — takes the unsigned XML plus your CSID certificate + private key, computes the invoice hash, builds the XAdES/XML-DSig signature, generates the QR code, and returns the final signed XML.
- **example_usage.php** — wires the two together with a 3-line-item sample invoice.

## Requirements

- PHP 8.1+
- `ext-dom`, `ext-openssl`, `ext-mbstring`
- An EC key pair on the **secp256k1** curve and its matching certificate, obtained from ZATCA's onboarding flow (CSR generation → Compliance CSID → Production CSID). Point `example_usage.php` at your actual PEM files.

## What's solid vs. what you must verify

This generates a structurally correct, schema-ordered UBL invoice and a complete signature + QR block, modeled closely on ZATCA's published CIUS and the pattern used by most open-source ZATCA integrations. That said, this is a compliance integration where the only real test of correctness is ZATCA's own validator, so before going live:

1. **Run every invoice through ZATCA's Compliance/sandbox API first.** It will tell you exactly which field, hash, or signature check failed — that feedback loop is the fastest way to close any gaps below.
2. **QR tag 9 (certificate signature)** — `extractCertificateSignature()` in `ZatcaSigner.php` currently returns an empty string. ZATCA's CSID certificate carries a vendor-specific X.509 extension holding a signature ZATCA produced over your public key, and PHP's OpenSSL bindings don't expose unrecognized extension OIDs directly. Run `openssl x509 -in your_cert.pem -text -noout` against your actual certificate, find that extension's OID, and wire up the extraction (you may need to shell out to `openssl asn1parse` to pull the raw bytes).
3. **Canonicalization** — ZATCA's spec calls for Canonical XML 1.1; PHP's built-in `DOMDocument::C14N()` implements 1.0 / Exclusive C14N. They agree for the XML shapes used here in the vast majority of cases, but it's worth byte-comparing one signed sample against ZATCA's own SDK output.
4. **Signature target** — this signs the raw invoice hash directly with ECDSA and reuses that signature for both the QR code and `ds:SignatureValue`, matching common reference implementations rather than signing the canonicalized `SignedInfo` per strict XML-DSig. ZATCA's compliance API will reject the invoice with a specific error if your certificate expects the stricter form, so this is easy to catch in testing.
5. **Scope** — covers standard tax invoices and simplified (B2C) invoices, document type 388, single VAT scheme, per-line tax categories. Credit notes (381), debit notes (383), exports, and multi-currency conversion rules aren't modeled — extend `ZatcaInvoiceBuilder` for those.

## The invoice hash chain (PIH/ICV)

ZATCA requires every invoice to reference the hash of the previous one (`previousInvoiceHash` → the PIH field) and a strictly sequential counter (`icv`). You need to persist the `invoiceHash` returned by `ZatcaSigner::sign()` and the latest counter value somewhere durable (database row per device/seller) and feed them into the next invoice. Skipping or reusing an ICV, or getting the PIH wrong, will fail clearance.

## Submitting to ZATCA

This code stops at "I have a signed XML string." Sending it to ZATCA (`Clearance API` for standard invoices — must succeed *before* you hand the invoice to the buyer — or `Reporting API` for simplified invoices, within 24 hours) is a separate HTTPS call (base64-encode the XML, POST it with your CSID as the auth credential, handle the cleared/reported response and any warnings). Happy to write that part too if useful — just say so.
