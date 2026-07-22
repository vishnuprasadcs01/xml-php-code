<?php

declare(strict_types=1);

namespace Zatca;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Signs a ZATCA UBL invoice produced by ZatcaInvoiceBuilder, using the
 * certificate + private key obtained from the ZATCA onboarding flow
 * (CSR generation -> Compliance CSID -> Production CSID).
 *
 * IMPORTANT - please read before going to production:
 *
 * 1. Curve: ZATCA requires the EC key pair to use the secp256k1 curve.
 *    Your onboarding CSR/key must already be secp256k1 (the ZATCA SDK's
 *    CSR generation step enforces this). This class assumes that key.
 *
 * 2. Canonicalization: ZATCA's spec references "Canonical XML 1.1"
 *    (xml-c14n11). PHP's native DOMDocument::C14N() implements Canonical
 *    XML 1.0 / Exclusive C14N, not 1.1. For the structures used in these
 *    invoices the two rarely differ in practice, and this is the same
 *    approximation used by most open-source ZATCA integrations, but you
 *    should still byte-compare a signed sample against ZATCA's reference
 *    SDK output (or run it through the Fatoora compliance/sandbox API)
 *    before relying on it in production.
 *
 * 3. QR tag 9 (certificate signature): ZATCA's CSID certificate carries
 *    a non-standard X.509 extension containing a signature that ZATCA
 *    itself produced over your public key. The exact OID can vary by
 *    certificate template/version. extractCertificateSignature() below
 *    contains a best-effort lookup with a manual override -- inspect your
 *    actual certificate (openssl x509 -in cert.pem -text -noout) and
 *    confirm/adjust the OID before trusting tag 9 in production.
 *
 * 4. This signs the raw invoice hash directly with ECDSA and reuses that
 *    same signature as both the QR "tag 7" value and the ds:SignatureValue.
 *    This mirrors common reference implementations, but strict XML-DSig
 *    would instead sign over the canonicalized SignedInfo. Validate
 *    against ZATCA's compliance API, which will reject the invoice with a
 *    specific error if this assumption doesn't hold for your certificate.
 *
 * Always run new integrations against ZATCA's sandbox/compliance
 * endpoints and their validation SDK before issuing real invoices.
 */
class ZatcaSigner
{
    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * @param string $unsignedXml   Output of ZatcaInvoiceBuilder::build()
     * @param string $certPem       PEM certificate from onboarding (Compliance or Production CSID)
     * @param string $privateKeyPem PEM EC private key (secp256k1) matching the certificate
     * @param array{sellerName:string,vatNumber:string,timestamp:string,total:string,vatTotal:string} $qrFields
     *
     * @return array{xml:string, invoiceHash:string, qr:string}
     *   invoiceHash is base64-encoded SHA-256 hash of the invoice (store
     *   it -- you'll need it as the PIH for the *next* invoice in the chain).
     */
    public function sign(string $unsignedXml, string $certPem, string $privateKeyPem, array $qrFields): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!$doc->loadXML($unsignedXml)) {
            throw new RuntimeException('Unable to parse unsigned invoice XML.');
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Invalid private key: ' . openssl_error_string());
        }

        $certDer = $this->pemToDer($certPem);
        $certDigestB64 = base64_encode(hash('sha256', $certDer, true));
        $certBase64 = base64_encode($certDer);

        // 1) Compute the invoice hash over a copy of the document with the
        //    UBLExtensions node (which will hold the signature) removed,
        //    since the signature can't cover itself.
        [$invoiceHashBinary, $invoiceHashBase64] = $this->computeInvoiceHash($doc);

        // 2) Sign the invoice hash directly with the EC private key (see
        //    class docblock note #4 about XML-DSig strictness).
        $signatureBinary = $this->ecdsaSign($invoiceHashBinary, $privateKey);
        $signatureBase64 = base64_encode($signatureBinary);

        // 3) Build XAdES SignedProperties, hash it for the second Reference.
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');
        $issuerSerial = $this->getIssuerAndSerial($certPem);
        $signedPropertiesXml = $this->buildSignedPropertiesXml($signingTime, $certDigestB64, $issuerSerial);
        $spHashBase64 = base64_encode(hash('sha256', $this->canonicalizeFragment($signedPropertiesXml), true));

        // 4) Assemble the ds:Signature / XAdES block and inject it into
        //    the existing (empty) ext:UBLExtensions node.
        $signatureXml = $this->buildSignatureXml(
            $invoiceHashBase64,
            $spHashBase64,
            $signatureBase64,
            $certBase64,
            $signedPropertiesXml
        );
        $this->injectExtensions($doc, $signatureXml);

        // 5) Build the QR code payload (TLV, base64) and inject it into
        //    the AdditionalDocumentReference[ID='QR'] placeholder.
        $publicKeyRaw = $this->getPublicKeyRaw($privateKey);
        $certSignatureRaw = $this->extractCertificateSignature($certPem); // may be empty, see caveat above

        $qrBase64 = $this->buildQrBase64(
            $qrFields['sellerName'],
            $qrFields['vatNumber'],
            $qrFields['timestamp'],
            $qrFields['total'],
            $qrFields['vatTotal'],
            $invoiceHashBinary,
            $signatureBinary,
            $publicKeyRaw,
            $certSignatureRaw
        );
        $this->injectQr($doc, $qrBase64);

        return [
            'xml' => $doc->saveXML(),
            'invoiceHash' => $invoiceHashBase64,
            'qr' => $qrBase64,
        ];
    }

    /** @return array{0:string,1:string} [binary hash, base64 hash] */
    private function computeInvoiceHash(DOMDocument $doc): array
    {
        $clone = clone $doc;
        $xpath = new DOMXPath($clone);
        $xpath->registerNamespace('ext', self::NS_EXT);
        foreach ($xpath->query('//ext:UBLExtensions') as $node) {
            $node->parentNode->removeChild($node);
        }
        $canonical = $clone->documentElement->C14N();
        $binary = hash('sha256', $canonical, true);
        return [$binary, base64_encode($binary)];
    }

    private function canonicalizeFragment(string $xmlFragment): string
    {
        $tmp = new DOMDocument('1.0', 'UTF-8');
        $tmp->loadXML($xmlFragment);
        return $tmp->documentElement->C14N();
    }

    private function ecdsaSign(string $data, $privateKey): string
    {
        $signature = '';
        $ok = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('ECDSA signing failed: ' . openssl_error_string());
        }
        return $signature;
    }

    private function pemToDer(string $pem): string
    {
        $pem = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $pem);
        $pem = preg_replace('/\s+/', '', $pem ?? '');
        $der = base64_decode((string) $pem, true);
        if ($der === false) {
            throw new RuntimeException('Could not decode certificate PEM.');
        }
        return $der;
    }

    private function getIssuerAndSerial(string $certPem): array
    {
        $parsed = openssl_x509_parse($certPem);
        if ($parsed === false) {
            throw new RuntimeException('Could not parse certificate.');
        }
        $issuerParts = [];
        foreach ($parsed['issuer'] as $k => $v) {
            $issuerParts[] = $k . '=' . $v;
        }
        return [
            'issuerName' => implode(',', array_reverse($issuerParts)),
            'serial' => $parsed['serialNumber'] ?? (string) ($parsed['serialNumberHex'] ?? ''),
        ];
    }

    private function getPublicKeyRaw($privateKey): string
    {
        $details = openssl_pkey_get_details($privateKey);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Could not extract EC public key coordinates. Confirm the key is an EC key.');
        }
        // Uncompressed point format: 0x04 || X || Y
        return "\x04" . $details['ec']['x'] . $details['ec']['y'];
    }

    /**
     * Best-effort extraction of the ZATCA-issued "certificate signature"
     * custom extension. Returns raw bytes, or an empty string if not found
     * (in which case QR tag 9 will be omitted -- adjust for your cert).
     */
    private function extractCertificateSignature(string $certPem): string
    {
        $resource = openssl_x509_read($certPem);
        if ($resource === false) {
            return '';
        }
        // openssl_x509_parse() only decodes extensions it recognizes by
        // name; unknown/custom OIDs are not exposed via PHP's OpenSSL API.
        // A reliable extraction generally requires shelling out to the
        // `openssl asn1parse` / `openssl x509 -text` CLI (if available on
        // your server) and locating the vendor-specific extension OID
        // from your actual CSID certificate, then base64/hex-decoding its
        // value. Wire that lookup in here once you've identified the OID
        // for your certificate. Returning '' for now rather than guessing.
        return '';
    }

    private function buildSignedPropertiesXml(string $signingTime, string $certDigestB64, array $issuerSerial): string
    {
        $issuerName = htmlspecialchars($issuerSerial['issuerName'], ENT_XML1);
        $serial = htmlspecialchars($issuerSerial['serial'], ENT_XML1);

        return <<<XML
<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="xadesSignedProperties">
  <xades:SignedSignatureProperties>
    <xades:SigningTime>{$signingTime}</xades:SigningTime>
    <xades:SigningCertificate>
      <xades:Cert>
        <xades:CertDigest>
          <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
          <ds:DigestValue>{$certDigestB64}</ds:DigestValue>
        </xades:CertDigest>
        <xades:IssuerSerial>
          <ds:X509IssuerName>{$issuerName}</ds:X509IssuerName>
          <ds:X509SerialNumber>{$serial}</ds:X509SerialNumber>
        </xades:IssuerSerial>
      </xades:Cert>
    </xades:SigningCertificate>
  </xades:SignedSignatureProperties>
</xades:SignedProperties>
XML;
    }

    private function buildSignatureXml(
        string $invoiceHashB64,
        string $spHashB64,
        string $signatureB64,
        string $certB64,
        string $signedPropertiesXml
    ): string {
        // Strip the outer declaration-less SignedProperties tag's own
        // namespace decls duplication isn't an issue here since we embed
        // it as-is inside ds:Object below.
        return <<<XML
<sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2"
                            xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2"
                            xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2"
                            xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <sac:SignatureInformation>
    <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
    <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
      <ds:SignedInfo>
        <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
        <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
        <ds:Reference Id="invoiceSignedData" URI="">
          <ds:Transforms>
            <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
              <ds:XPath>not(//*[local-name()='UBLExtensions'])</ds:XPath>
            </ds:Transform>
            <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
          </ds:Transforms>
          <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
          <ds:DigestValue>{$invoiceHashB64}</ds:DigestValue>
        </ds:Reference>
        <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
          <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
          <ds:DigestValue>{$spHashB64}</ds:DigestValue>
        </ds:Reference>
      </ds:SignedInfo>
      <ds:SignatureValue>{$signatureB64}</ds:SignatureValue>
      <ds:KeyInfo>
        <ds:X509Data>
          <ds:X509Certificate>{$certB64}</ds:X509Certificate>
        </ds:X509Data>
      </ds:KeyInfo>
      <ds:Object>
        <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
          {$signedPropertiesXml}
        </xades:QualifyingProperties>
      </ds:Object>
    </ds:Signature>
  </sac:SignatureInformation>
</sig:UBLDocumentSignatures>
XML;
    }

    private function injectExtensions(DOMDocument $doc, string $signatureXml): void
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $nodes = $xpath->query('//ext:UBLExtensions');
        if ($nodes->length === 0) {
            throw new RuntimeException('ext:UBLExtensions placeholder not found in invoice.');
        }
        /** @var DOMElement $extensionsNode */
        $extensionsNode = $nodes->item(0);

        $fragmentDoc = new DOMDocument('1.0', 'UTF-8');
        $fragmentDoc->loadXML($signatureXml);

        $extensionEl = $doc->createElementNS(self::NS_EXT, 'ext:UBLExtension');
        $extensionUriEl = $doc->createElementNS(self::NS_EXT, 'ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $extensionContentEl = $doc->createElementNS(self::NS_EXT, 'ext:ExtensionContent');
        $imported = $doc->importNode($fragmentDoc->documentElement, true);
        $extensionContentEl->appendChild($imported);

        $extensionEl->appendChild($extensionUriEl);
        $extensionEl->appendChild($extensionContentEl);
        $extensionsNode->appendChild($extensionEl);
    }

    private function injectQr(DOMDocument $doc, string $qrBase64): void
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('cac', self::NS_CAC);
        $xpath->registerNamespace('cbc', self::NS_CBC);
        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject");
        if ($nodes->length === 0) {
            throw new RuntimeException('QR AdditionalDocumentReference placeholder not found.');
        }
        $nodes->item(0)->nodeValue = $qrBase64;
    }

    /**
     * Builds the ZATCA QR payload: 9 TLV fields, base64-encoded as a whole.
     * Fields 1-5 are stored as UTF-8 text bytes; fields 6-9 are stored as
     * raw binary bytes (per ZATCA's QR spec).
     */
    private function buildQrBase64(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        string $total,
        string $vatTotal,
        string $invoiceHashBinary,
        string $signatureBinary,
        string $publicKeyRaw,
        string $certSignatureRaw
    ): string {
        $tlv = '';
        $tlv .= $this->tlv(1, $sellerName);
        $tlv .= $this->tlv(2, $vatNumber);
        $tlv .= $this->tlv(3, $timestamp);
        $tlv .= $this->tlv(4, $total);
        $tlv .= $this->tlv(5, $vatTotal);
        $tlv .= $this->tlv(6, $invoiceHashBinary);
        $tlv .= $this->tlv(7, $signatureBinary);
        $tlv .= $this->tlv(8, $publicKeyRaw);
        if ($certSignatureRaw !== '') {
            $tlv .= $this->tlv(9, $certSignatureRaw);
        }
        return base64_encode($tlv);
    }

    private function tlv(int $tag, string $value): string
    {
        $len = strlen($value);
        if ($len > 255) {
            throw new RuntimeException("QR TLV value for tag {$tag} exceeds 255 bytes; single-byte length encoding won't fit.");
        }
        return chr($tag) . chr($len) . $value;
    }
}
