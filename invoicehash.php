<?php
/**
 * Invoice XML Processor
 * Extracts invoice hash, UUID, and generates base64 encoding
 * User inputs XML string and calculates values based on that
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

class InvoiceProcessor {
    private $xmlString;
    private $xml;

    /**
     * Constructor
     * @param string $xmlString XML content as string
     */
    public function __construct($xmlString) {
        $this->xmlString = $xmlString;
        
        libxml_use_internal_errors(true);
        $this->xml = simplexml_load_string($xmlString);
        
        if ($this->xml === false) {
            $errors = libxml_get_errors();
            $errorMsg = "Failed to parse XML:\n";
            foreach ($errors as $error) {
                $errorMsg .= "Line {$error->line}: {$error->message}\n";
            }
            libxml_clear_errors();
            throw new Exception($errorMsg);
        }
    }

    /**
     * Get the UUID from the invoice
     * @return string UUID value
     */
    public function getUUID() {
        try {
            $namespaces = $this->xml->getNamespaces(true);
            $cbc = $this->xml->children($namespaces['cbc']);
            $uuid = (string)$cbc->UUID;
            
            if (empty($uuid)) {
                throw new Exception("UUID not found in XML");
            }
            
            return $uuid;
        } catch (Exception $e) {
            error_log("Error getting UUID: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the invoice hash (from PIH - Previous Invoice Hash)
     * @return string Base64 decoded or raw hash value
     */
    public function getInvoiceHash() {
        try {
            $namespaces = $this->xml->getNamespaces(true);
            $cac = $this->xml->children($namespaces['cac']);
            
            if (empty($cac->AdditionalDocumentReference)) {
                throw new Exception("AdditionalDocumentReference not found");
            }
            
            // Find AdditionalDocumentReference with ID="PIH"
            foreach ($cac->AdditionalDocumentReference as $docRef) {
                $cbc = $docRef->children($namespaces['cbc']);
                if ((string)$cbc->ID === 'PIH') {
                    $cacAttach = $docRef->children($namespaces['cac']);
                    $cbcAttach = $cacAttach->Attachment->children($namespaces['cbc']);
                    $hash = (string)$cbcAttach->EmbeddedDocumentBinaryObject;
                    
                    if (empty($hash)) {
                        throw new Exception("PIH hash value is empty");
                    }
                    
                    return $hash;
                }
            }
            
            throw new Exception("PIH AdditionalDocumentReference not found");
        } catch (Exception $e) {
            error_log("Error getting invoice hash: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the Digital Signature Value
     * @return string Signature value
     */
    public function getSignatureValue() {
        try {
            $namespaces = $this->xml->getNamespaces(true);
            $ext = $this->xml->children($namespaces['ext']);
            
            if (empty($ext->UBLExtensions)) {
                throw new Exception("UBLExtensions not found");
            }
            
            // Navigate through extension structure
            $docSigs = $ext->UBLExtensions->UBLExtension->ExtensionContent->children($namespaces['cac']);
            
            if (empty($docSigs->UBLDocumentSignatures)) {
                throw new Exception("UBLDocumentSignatures not found");
            }
            
            foreach ($docSigs->UBLDocumentSignatures->SignatureInformation as $sigInfo) {
                $dsNamespace = 'http://www.w3.org/2000/09/xmldsig#';
                $signature = $sigInfo->children($dsNamespace);
                
                if (isset($signature->Signature)) {
                    $sig = $signature->Signature->children($dsNamespace);
                    $sigValue = (string)$sig->SignatureValue;
                    
                    if (empty($sigValue)) {
                        throw new Exception("Signature value is empty");
                    }
                    
                    return $sigValue;
                }
            }
            
            throw new Exception("Signature not found in XML");
        } catch (Exception $e) {
            error_log("Error getting signature: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the Invoice ID
     * @return string Invoice ID
     */
    public function getInvoiceID() {
        try {
            $namespaces = $this->xml->getNamespaces(true);
            $cbc = $this->xml->children($namespaces['cbc']);
            $id = (string)$cbc->ID;
            
            if (empty($id)) {
                throw new Exception("Invoice ID not found");
            }
            
            return $id;
        } catch (Exception $e) {
            error_log("Error getting invoice ID: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate Base64 encoded string of the XML
     * @return string Base64 encoded XML
     */
    public function getBase64EncodedXML() {
        try {
            $encoded = base64_encode($this->xmlString);
            
            if ($encoded === false) {
                throw new Exception("Failed to base64 encode XML");
            }
            
            return $encoded;
        } catch (Exception $e) {
            error_log("Error encoding to base64: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all invoice details in array format
     * @return array Invoice details
     */
    public function getAllDetails() {
        $details = array();
        
        try {
            $details['invoice_id'] = $this->getInvoiceID();
        } catch (Exception $e) {
            $details['invoice_id_error'] = $e->getMessage();
        }
        
        try {
            $details['uuid'] = $this->getUUID();
        } catch (Exception $e) {
            $details['uuid_error'] = $e->getMessage();
        }
        
        try {
            $details['invoice_hash_pih'] = $this->getInvoiceHash();
        } catch (Exception $e) {
            $details['invoice_hash_pih_error'] = $e->getMessage();
        }
        
        try {
            $details['signature_value'] = $this->getSignatureValue();
        } catch (Exception $e) {
            $details['signature_value_error'] = $e->getMessage();
        }
        
        try {
            $details['base64_encoded_xml'] = $this->getBase64EncodedXML();
        } catch (Exception $e) {
            $details['base64_encoded_xml_error'] = $e->getMessage();
        }
        
        $details['xml_size_bytes'] = strlen($this->xmlString);
        
        return $details;
    }
}

// Process user input
$xmlInput = '';
$allDetails = null;
$hasErrors = false;
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xml_input'])) {
    $xmlInput = trim($_POST['xml_input']);
    
    if (empty($xmlInput)) {
        $errorMessage = "Please enter XML content.";
    } else {
        try {
            $processor = new InvoiceProcessor($xmlInput);
            $allDetails = $processor->getAllDetails();
            
            // Check for errors in details
            foreach ($allDetails as $key => $value) {
                if (strpos($key, 'error') !== false) {
                    $hasErrors = true;
                    break;
                }
            }
            
            if (!$hasErrors) {
                $successMessage = "✓ All invoice data extracted successfully!";
            } else {
                $successMessage = "⚠ Invoice data extracted with some warnings.";
            }
        } catch (Exception $e) {
            $errorMessage = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice XML Processor - User Input</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section h2 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #333;
            font-size: 1.1em;
        }
        
        .form-group textarea {
            width: 100%;
            min-height: 300px;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            resize: vertical;
            transition: border-color 0.3s;
        }
        
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        .form-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        button {
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-submit {
            background-color: #28a745;
            color: white;
            flex: 1;
        }
        
        .btn-submit:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-reset {
            background-color: #6c757d;
            color: white;
            flex: 1;
        }
        
        .btn-reset:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-clear {
            background-color: #dc3545;
            color: white;
            padding: 8px 15px;
            font-size: 0.9em;
        }
        
        .btn-clear:hover {
            background-color: #c82333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        table thead {
            background-color: #f8f9fa;
        }
        
        table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            background-color: #667eea;
            color: white;
            border-bottom: 2px solid #667eea;
        }
        
        table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            word-break: break-word;
        }
        
        table tbody tr:hover {
            background-color: #f8f9fa;
            transition: background-color 0.3s ease;
        }
        
        table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .label {
            font-weight: 600;
            color: #667eea;
            width: 30%;
        }
        
        .value {
            color: #555;
        }
        
        .success {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            color: #155724;
        }
        
        .error {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            color: #721c24;
        }
        
        .info-box {
            background-color: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            color: #0c5460;
        }
        
        .code-block {
            background-color: #f4f4f4;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            word-break: break-all;
        }
        
        .truncated {
            color: #999;
            font-style: italic;
        }
        
        .status-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            text-align: center;
            line-height: 20px;
            margin-right: 10px;
            font-weight: bold;
            color: white;
        }
        
        .status-success {
            background-color: #28a745;
        }
        
        .status-error {
            background-color: #dc3545;
        }
        
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            border-top: 1px solid #e9ecef;
        }
        
        .form-helper {
            background-color: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            color: #0c3975;
            font-size: 0.95em;
        }
        
        .results-section {
            display: none;
        }
        
        .results-section.show {
            display: block;
        }

        .character-count {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }

        .toggle-btn {
            background-color: #667eea;
            color: white;
            padding: 10px 20px;
            margin-bottom: 10px;
        }

        .toggle-btn:hover {
            background-color: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📄 Invoice XML Processor</h1>
            <p>Extract and Process Invoice Data from User Input</p>
        </div>
        
        <div class="content">
            <!-- Input Section -->
            <div class="section">
                <h2>📝 Input XML Data</h2>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="xml_input">Enter Invoice XML:</label>
                        <textarea 
                            id="xml_input" 
                            name="xml_input" 
                            placeholder="Paste your XML content here..." 
                            onchange="updateCharCount()"
                            oninput="updateCharCount()"
                        ><?php echo htmlspecialchars($xmlInput); ?></textarea>
                        <div class="character-count">
                            Characters: <span id="char-count">0</span>
                        </div>
                    </div>
                    
                    <div class="form-helper">
                        <strong>ℹ️ Instructions:</strong> Paste your complete Invoice XML string in the textarea above. The processor will extract:
                        <ul style="margin-left: 20px; margin-top: 10px;">
                            <li>Invoice ID</li>
                            <li>UUID (Universally Unique Identifier)</li>
                            <li>Invoice Hash (PIH - Previous Invoice Hash)</li>
                            <li>Digital Signature Value</li>
                            <li>Base64 Encoded XML representation</li>
                        </ul>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="submit" class="btn-submit">🔍 Process XML</button>
                        <button type="reset" class="btn-reset" onclick="resetForm()">↻ Clear Form</button>
                    </div>
                </form>
            </div>
            
            <!-- Messages Section -->
            <?php if ($errorMessage): ?>
                <div class="error">
                    <span class="status-icon status-error">✗</span>
                    <strong>ERROR:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($successMessage && $allDetails): ?>
                <div class="success">
                    <span class="status-icon status-success">✓</span>
                    <strong><?php echo $successMessage; ?></strong>
                </div>
            <?php endif; ?>
            
            <!-- Results Section -->
            <?php if ($allDetails): ?>
            <div class="results-section show">
                
                <!-- Main Invoice Details Table -->
                <div class="section">
                    <h2>📋 Invoice Details</h2>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 30%;">Field</th>
                                <th style="width: 70%;">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="label">Invoice ID</td>
                                <td class="value">
                                    <?php if (isset($allDetails['invoice_id_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error: <?php echo htmlspecialchars($allDetails['invoice_id_error']); ?>
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span> <?php echo htmlspecialchars($allDetails['invoice_id']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="label">UUID</td>
                                <td class="value">
                                    <?php if (isset($allDetails['uuid_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error: <?php echo htmlspecialchars($allDetails['uuid_error']); ?>
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span>
                                        <div class="code-block"><?php echo htmlspecialchars($allDetails['uuid']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="label">XML Size</td>
                                <td class="value">
                                    <span class="status-icon status-success">✓</span> 
                                    <?php echo number_format($allDetails['xml_size_bytes']); ?> bytes 
                                    (<?php echo round($allDetails['xml_size_bytes'] / 1024, 2); ?> KB)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Invoice Hash Section -->
                <div class="section">
                    <h2>🔐 Invoice Hash (PIH)</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="label">Previous Invoice Hash</td>
                                <td class="value">
                                    <?php if (isset($allDetails['invoice_hash_pih_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error: <?php echo htmlspecialchars($allDetails['invoice_hash_pih_error']); ?>
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span>
                                        <div class="code-block"><?php echo htmlspecialchars($allDetails['invoice_hash_pih']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Digital Signature Section -->
                <div class="section">
                    <h2>🔏 Digital Signature</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="label">Signature Value</td>
                                <td class="value">
                                    <?php if (isset($allDetails['signature_value_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error: <?php echo htmlspecialchars($allDetails['signature_value_error']); ?>
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span>
                                        <div class="code-block">
                                            <?php echo htmlspecialchars($allDetails['signature_value']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Base64 Encoded XML Section -->
                <div class="section">
                    <h2>📝 Base64 Encoded XML</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="label">Encoded Size</td>
                                <td class="value">
                                    <?php if (isset($allDetails['base64_encoded_xml_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span>
                                        <?php echo number_format(strlen($allDetails['base64_encoded_xml'])); ?> bytes 
                                        (<?php echo round(strlen($allDetails['base64_encoded_xml']) / 1024, 2); ?> KB)
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="label">First 500 Characters</td>
                                <td class="value">
                                    <?php if (isset($allDetails['base64_encoded_xml_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error: <?php echo htmlspecialchars($allDetails['base64_encoded_xml_error']); ?>
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span>
                                        <div class="code-block">
                                            <?php echo htmlspecialchars(substr($allDetails['base64_encoded_xml'], 0, 500)); ?>
                                            <span class="truncated">... (truncated)</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="label">Complete Base64</td>
                                <td class="value">
                                    <?php if (isset($allDetails['base64_encoded_xml_error'])): ?>
                                        <span class="status-icon status-error">✗</span> Error
                                    <?php else: ?>
                                        <span class="status-icon status-success">✓</span>
                                        <button type="button" class="toggle-btn" onclick="toggleBase64()" id="toggle-btn">
                                            ▼ Show Complete Base64
                                        </button>
                                        <div id="base64-full" style="display: none;">
                                            <div class="code-block" style="max-height: 400px; overflow-y: auto;">
                                                <?php echo htmlspecialchars($allDetails['base64_encoded_xml']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- JSON Output -->
                <div class="section">
                    <h2>📊 JSON Output</h2>
                    <button type="button" class="toggle-btn" onclick="toggleJSON()" id="toggle-json-btn">
                        ▼ Show JSON Output
                    </button>
                    <div id="json-output" style="display: none;">
                        <div class="code-block" style="max-height: 400px; overflow-y: auto;">
                            <pre><?php echo json_encode($allDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></pre>
                        </div>
                    </div>
                </div>
                
            </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>Invoice XML Processor | Generated on <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
    
    <script>
        function updateCharCount() {
            const textarea = document.getElementById('xml_input');
            const charCount = document.getElementById('char-count');
            charCount.textContent = textarea.value.length;
        }
        
        function resetForm() {
            document.getElementById('xml_input').value = '';
            updateCharCount();
        }
        
        function toggleBase64() {
            const element = document.getElementById('base64-full');
            const btn = document.getElementById('toggle-btn');
            if (element.style.display === 'none') {
                element.style.display = 'block';
                btn.textContent = '▲ Hide Complete Base64';
            } else {
                element.style.display = 'none';
                btn.textContent = '▼ Show Complete Base64';
            }
        }
        
        function toggleJSON() {
            const element = document.getElementById('json-output');
            const btn = document.getElementById('toggle-json-btn');
            if (element.style.display === 'none') {
                element.style.display = 'block';
                btn.textContent = '▲ Hide JSON Output';
            } else {
                element.style.display = 'none';
                btn.textContent = '▼ Show JSON Output';
            }
        }
        
        // Initialize character count on page load
        window.addEventListener('load', function() {
            updateCharCount();
        });
    </script>
</body>
</html>
