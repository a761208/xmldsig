<?php

namespace Odan\XmlDSig;

use DOMDocument;
use RuntimeException;

/**
 * Class SignedXml.
 */
final class SignedXml
{
    //
    // RSA (PKCS#1 v1.5) Identifier
    // https://www.w3.org/TR/xmldsig-core/#sec-PKCS1
    //
    const SHA1_URL = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    const SHA224_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha224';
    const SHA256_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    const SHA384_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384';
    const SHA512_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512';

    private $digestAlgorithm;
    private $digestAlgorithmName;
    private $digestAlgorithmUrl;
    private $privateKeyId;

    /**
     * Constructor.
     *
     * @param string $digestAlgorithm sha1, sha224, sha256, sha384, sha512
     */
    public function __construct(string $digestAlgorithm)
    {
        $this->setDisgestAlgorithm($digestAlgorithm);
    }

    /**
     * Set disgest algorithm.
     *
     * @param string $digestAlgorithm sha1, sha224, sha256, sha384, sha512
     */
    private function setDisgestAlgorithm(string $digestAlgorithm): void
    {
        switch ($digestAlgorithm) {
            case 'sha1':
                $this->digestAlgorithmUrl = self::SHA1_URL;
                $this->digestAlgorithm = OPENSSL_ALGO_SHA1;
                break;
            case 'sha224':
                $this->digestAlgorithmUrl = self::SHA224_URL;
                $this->digestAlgorithm = OPENSSL_ALGO_SHA224;
                break;
            case 'sha256':
                $this->digestAlgorithmUrl = self::SHA256_URL;
                $this->digestAlgorithm = OPENSSL_ALGO_SHA256;
                break;
            case 'sha384':
                $this->digestAlgorithmUrl = self::SHA384_URL;
                $this->digestAlgorithm = OPENSSL_ALGO_SHA384;
                break;
            case 'sha512':
                $this->digestAlgorithmUrl = self::SHA512_URL;
                $this->digestAlgorithm = OPENSSL_ALGO_SHA512;
                break;
            default:
                throw new RuntimeException("Cannot validate digest: Unsupported Algorithm <$digestAlgorithm>");
        }

        $this->digestAlgorithmName = $digestAlgorithm;
    }

    /**
     * Read and load the pfx file.
     *
     * @param string $filename PFX filename
     * @param string $password PFX password
     *
     * @return bool Success
     */
    public function loadPfx(string $filename, string $password): bool
    {
        if (!file_exists($filename)) {
            throw new RuntimeException(sprintf('File not found: %s', $filename));
        }

        $certStore = file_get_contents($filename);
        $status = openssl_pkcs12_read($certStore, $certInfo, $password);

        if (!$status) {
            throw new RuntimeException('Invalid PFX pasword');
        }

        // Read the private key
        $this->privateKeyId = openssl_get_privatekey($certInfo['pkey']);

        if (!$this->privateKeyId) {
            throw new RuntimeException('Invalid private key');
        }

        return true;
    }

    /**
     * Sign an XML file and save the signature in a new file.
     * This method does not save the public key within the XML file.
     *
     * @param string $filename Input file
     * @param string $outputFilename Output file
     *
     * @return bool Success
     */
    public function signXmlFile(string $filename, string $outputFilename): bool
    {
        if (!file_exists($filename)) {
            throw new RuntimeException(sprintf('File not found: %s', $filename));
        }

        if (!$this->privateKeyId) {
            throw new RuntimeException('No private key provided');
        }

        // Read the xml file content
        $xml = new DOMDocument();
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;
        $xml->load($filename);
        $data = $xml->saveXML();

        // Compute signature with SHA-512
        $status = openssl_sign($data, $signature, $this->privateKeyId, $this->digestAlgorithm);

        if (!$status) {
            throw new RuntimeException('Computing of the signature failed');
        }

        // Encode signature
        $signatureValue = base64_encode($signature);

        // Calulate and encode digest value
        $digestValue = base64_encode(hash($this->digestAlgorithmName, $data, true));

        $xml = $this->createSignedXml($data, $digestValue, $signatureValue);

        file_put_contents($outputFilename, $xml->saveXML());

        return true;
    }

    /**
     * Create the XML representation of the signature.
     *
     * @param string $data
     * @param string $digestValue
     * @param string $signatureValue
     *
     * @return DOMDocument
     */
    public function createSignedXml(string $data, string $digestValue, string $signatureValue): DOMDocument
    {
        $xml = new DOMDocument();
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;
        $xml->loadXML($data);

        $signatureElement = $xml->createElement('Signature');
        $signatureElement->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');

        $signedInfoElement = $xml->createElement('SignedInfo');
        $signatureElement->appendChild($signedInfoElement);

        $canonicalizationMethodElement = $xml->createElement('CanonicalizationMethod');
        $canonicalizationMethodElement->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfoElement->appendChild($canonicalizationMethodElement);

        $signatureMethodElement = $xml->createElement('SignatureMethod');
        $signatureMethodElement->setAttribute('Algorithm', $this->digestAlgorithmUrl);
        $signedInfoElement->appendChild($signatureMethodElement);

        $referenceElement = $xml->createElement('Reference');
        $referenceElement->setAttribute('URI', '');
        $signedInfoElement->appendChild($referenceElement);

        $transformsElement = $xml->createElement('Transforms');
        $referenceElement->appendChild($transformsElement);

        $transformElement = $xml->createElement('Transform');
        $transformElement->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transformsElement->appendChild($transformElement);

        $digestMethodElement = $xml->createElement('DigestMethod');
        $digestMethodElement->setAttribute('Algorithm', $this->digestAlgorithmUrl);
        $referenceElement->appendChild($digestMethodElement);

        $digestValueElement = $xml->createElement('DigestValue', $digestValue);
        $referenceElement->appendChild($digestValueElement);

        $signatureValueElement = $xml->createElement('SignatureValue', $signatureValue);
        $signatureElement->appendChild($signatureValueElement);

        // Append the element to the XML document.
        // We insert the new element as root (child of the document)
        $xml->documentElement->appendChild($signatureElement);

        return $xml;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        // Free the key from memory
        if ($this->privateKeyId) {
            openssl_free_key($this->privateKeyId);
        }
    }
}
