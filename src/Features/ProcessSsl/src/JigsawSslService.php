<?php namespace ProcessWire;

/**
 * SSL Manager — OpenSSL / TLS service.
 *
 * Pure logic: TLS certificate inspection, CSR generation/inspection,
 * self-signed certificate generation. No ProcessWire UI side effects;
 * every method returns structured data (failures carry an `error` key).
 */
class JigsawSslService {

    /** @var int */
    protected $connectTimeout;

    public function __construct($connectTimeout = 10) {
        $this->connectTimeout = (int) $connectTimeout ?: 10;
    }

    public static function available() {
        return function_exists('openssl_x509_parse');
    }

    /* ------------------------------------------------------------------ */
    /* Live certificate inspection                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Open a TLS connection and parse the peer certificate.
     *
     * @return array {validFrom,validTo,daysLeft,issuer,cn,san,fingerprint,
     *               chain,protocol,cipher,selfSigned,serial,sigType,checkedAt}
     *               or {error,checkedAt}
     */
    public function fetchCertInfo($host, $port = 443) {
        $port = (int) $port ?: 443;
        $now = time();

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert'       => true,
                'capture_peer_cert_chain' => true,
                'verify_peer'             => false,
                'verify_peer_name'        => false,
                'SNI_enabled'             => true,
                'peer_name'               => $host,
            ],
        ]);

        $errno = 0; $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno, $errstr, $this->connectTimeout,
            STREAM_CLIENT_CONNECT, $context
        );

        if(!$client) {
            return ['error' => trim($errstr) ?: ('Connection failed (' . $errno . ')'), 'checkedAt' => $now];
        }

        $params = stream_context_get_params($client);
        $meta = stream_get_meta_data($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if(!$cert) return ['error' => 'No certificate returned', 'checkedAt' => $now];

        $parsed = openssl_x509_parse($cert);
        if(!$parsed) return ['error' => 'Could not parse certificate', 'checkedAt' => $now];

        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
        $san = !empty($parsed['extensions']['subjectAltName']) ? $parsed['extensions']['subjectAltName'] : '';

        $chain = [];
        if(!empty($params['options']['ssl']['peer_certificate_chain'])) {
            foreach($params['options']['ssl']['peer_certificate_chain'] as $c) {
                $p = openssl_x509_parse($c);
                if($p) $chain[] = $p['subject']['CN'] ?? ($p['subject']['O'] ?? 'Unknown');
            }
        }

        $fingerprint = function_exists('openssl_x509_fingerprint')
            ? openssl_x509_fingerprint($cert, 'sha256') : '';

        $protocol = $cipher = '';
        if(!empty($meta['crypto'])) {
            $protocol = $meta['crypto']['protocol'] ?? '';
            $cipher   = $meta['crypto']['cipher_name'] ?? '';
        }

        $issuerCN  = $parsed['issuer']['CN'] ?? '';
        $subjectCN = $parsed['subject']['CN'] ?? '';

        return [
            'validFrom'   => (int) ($parsed['validFrom_time_t'] ?? 0),
            'validTo'     => $validTo,
            'daysLeft'    => (int) floor(($validTo - $now) / 86400),
            'issuer'      => $parsed['issuer']['O'] ?? ($issuerCN ?: 'Unknown'),
            'cn'          => $subjectCN,
            'san'         => $san,
            'fingerprint' => $fingerprint,
            'chain'       => $chain,
            'protocol'    => $protocol,
            'cipher'      => $cipher,
            'selfSigned'  => ($parsed['issuer'] == $parsed['subject']),
            'serial'      => $parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? ''),
            'sigType'     => $parsed['signatureTypeSN'] ?? '',
            'checkedAt'   => $now,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* CSR generation & inspection                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Build a CSR + private key.
     *
     * @param array $opts cn, bits, country, state, locality, org, ou, email, san (raw text)
     * @return array {csr,key,cn} or {error}
     */
    public function createCsr(array $opts) {
        if(!function_exists('openssl_csr_new')) {
            return ['error' => 'The PHP OpenSSL extension is not available on this server.'];
        }

        $cn = trim($opts['cn'] ?? '');
        $bits = (int) ($opts['bits'] ?? 2048) ?: 2048;

        $dn = array_filter([
            'commonName'             => $cn,
            'countryName'            => strtoupper(trim($opts['country'] ?? '')),
            'stateOrProvinceName'    => trim($opts['state'] ?? ''),
            'localityName'           => trim($opts['locality'] ?? ''),
            'organizationName'       => trim($opts['org'] ?? ''),
            'organizationalUnitName' => trim($opts['ou'] ?? ''),
            'emailAddress'           => trim($opts['email'] ?? ''),
        ], 'strlen');

        $sans = $this->collectSans($cn, $opts['san'] ?? '');

        $configFile = null;
        $configArgs = ['digest_alg' => 'sha256'];
        if(count($sans) > 1) {
            $configFile = $this->writeOpensslConfig($sans);
            $configArgs['config'] = $configFile;
            $configArgs['req_extensions'] = 'v3_req';
        }

        $privkey = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] + ($configFile ? ['config' => $configFile] : []));

        if(!$privkey) {
            if($configFile) @unlink($configFile);
            return ['error' => 'Could not generate a private key: ' . $this->opensslErrors()];
        }

        $csr = openssl_csr_new($dn, $privkey, $configArgs);
        if(!$csr) {
            if($configFile) @unlink($configFile);
            return ['error' => 'Could not generate the CSR: ' . $this->opensslErrors()];
        }

        $csrOut = $keyOut = '';
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($privkey, $keyOut, null, $configFile ? ['config' => $configFile] : []);

        if($configFile) @unlink($configFile);

        return ['csr' => $csrOut, 'key' => $keyOut, 'cn' => $cn];
    }

    /**
     * Parse a CSR and optionally verify a private key against it.
     *
     * @return array {error} OR {subject(short-name array),keyType,keyBits,
     *               match(null|true|false),matchError(bool)}
     */
    public function inspectCsr($csrPem, $keyPem = '') {
        if(!function_exists('openssl_csr_get_subject')) {
            return ['error' => 'The PHP OpenSSL extension is not available on this server.'];
        }

        $csrPem = trim($csrPem);
        $subject = @openssl_csr_get_subject($csrPem, true);
        if($subject === false) {
            return ['error' => 'Could not parse this CSR. Make sure you pasted the full PEM block.'];
        }

        $pub = @openssl_csr_get_public_key($csrPem);
        $details = $pub ? openssl_pkey_get_details($pub) : false;

        $result = [
            'subject' => $subject,
            'keyType' => $this->keyTypeName($details),
            'keyBits' => $details['bits'] ?? '?',
            'match'   => null,
            'matchError' => false,
        ];

        $keyPem = trim($keyPem);
        if($keyPem !== '') {
            $priv = @openssl_pkey_get_private($keyPem);
            if(!$priv) {
                $result['matchError'] = true;
            } else {
                $privDetails = openssl_pkey_get_details($priv);
                $result['match'] = $details && $privDetails
                    && isset($details['key'], $privDetails['key'])
                    && $details['key'] === $privDetails['key'];
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /* Certificate inspection & self-signed generation                     */
    /* ------------------------------------------------------------------ */

    /**
     * Parse a PEM certificate.
     *
     * @return array {error} OR parsed fields used for display.
     */
    public function inspectCertificate($pem) {
        if(!function_exists('openssl_x509_parse')) {
            return ['error' => 'The PHP OpenSSL extension is not available on this server.'];
        }

        $pem = trim($pem);
        $parsed = @openssl_x509_parse($pem);
        if(!$parsed) {
            return ['error' => 'Could not parse this certificate. Make sure you pasted the full PEM block.'];
        }

        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validTo   = (int) ($parsed['validTo_time_t'] ?? 0);

        return [
            'validFrom'   => $validFrom,
            'validTo'     => $validTo,
            'daysLeft'    => (int) floor(($validTo - time()) / 86400),
            'cn'          => $parsed['subject']['CN'] ?? '',
            'org'         => $parsed['subject']['O'] ?? '',
            'san'         => $parsed['extensions']['subjectAltName'] ?? '',
            'issuer'      => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? ''),
            'selfSigned'  => ($parsed['issuer'] == $parsed['subject']),
            'sigType'     => $parsed['signatureTypeSN'] ?? '',
            'serial'      => $parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? ''),
            'fingerprint' => function_exists('openssl_x509_fingerprint') ? openssl_x509_fingerprint($pem, 'sha256') : '',
        ];
    }

    /**
     * Build a self-signed certificate + key.
     *
     * @param array $opts cn, bits, days, san (raw text)
     * @return array {cert,key,cn} or {error}
     */
    public function createSelfSigned(array $opts) {
        if(!function_exists('openssl_csr_sign')) {
            return ['error' => 'The PHP OpenSSL extension is not available on this server.'];
        }

        $cn = trim($opts['cn'] ?? '');
        $bits = (int) ($opts['bits'] ?? 2048) ?: 2048;
        $days = (int) ($opts['days'] ?? 365) ?: 365;

        $sans = $this->collectSans($cn, $opts['san'] ?? '');

        $configFile = null;
        $configArgs = ['digest_alg' => 'sha256'];
        if(count($sans) > 1) {
            $configFile = $this->writeOpensslConfig($sans);
            $configArgs['config'] = $configFile;
            $configArgs['req_extensions'] = 'v3_req';
        }

        $privkey = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] + ($configFile ? ['config' => $configFile] : []));

        if(!$privkey) {
            if($configFile) @unlink($configFile);
            return ['error' => 'Could not generate a private key: ' . $this->opensslErrors()];
        }

        $csr = openssl_csr_new(['commonName' => $cn], $privkey, $configArgs);
        $cert = $csr ? openssl_csr_sign($csr, null, $privkey, $days, $configArgs, random_int(1, PHP_INT_MAX)) : false;

        if(!$cert) {
            if($configFile) @unlink($configFile);
            return ['error' => 'Could not create the certificate: ' . $this->opensslErrors()];
        }

        $certOut = $keyOut = '';
        openssl_x509_export($cert, $certOut);
        openssl_pkey_export($privkey, $keyOut, null, $configFile ? ['config' => $configFile] : []);

        if($configFile) @unlink($configFile);

        return ['cert' => $certOut, 'key' => $keyOut, 'cn' => $cn];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    protected function keyTypeName($details) {
        $map = [
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_DSA => 'DSA',
            OPENSSL_KEYTYPE_DH  => 'DH',
            OPENSSL_KEYTYPE_EC  => 'EC',
        ];
        return ($details && isset($map[$details['type']])) ? $map[$details['type']] : 'Unknown';
    }

    /**
     * Normalise the CN + extra SAN text into a unique host list (CN first).
     */
    protected function collectSans($cn, $sanText) {
        $lines = preg_split('/[\r\n,]+/', (string) $sanText);
        $sans = [];
        foreach(array_merge([$cn], (array) $lines) as $h) {
            $h = trim($h);
            if($h !== '' && !in_array($h, $sans, true)) $sans[] = $h;
        }
        return $sans;
    }

    protected function writeOpensslConfig(array $sans) {
        $alt = '';
        $i = 1;
        foreach($sans as $h) {
            $alt .= 'DNS.' . $i . ' = ' . $h . "\n";
            $i++;
        }

        $conf = "[req]\n"
              . "distinguished_name = req_distinguished_name\n"
              . "req_extensions = v3_req\n"
              . "[req_distinguished_name]\n"
              . "[v3_req]\n"
              . "basicConstraints = CA:FALSE\n"
              . "keyUsage = nonRepudiation, digitalSignature, keyEncipherment\n"
              . "subjectAltName = @alt_names\n"
              . "[alt_names]\n"
              . $alt;

        $file = tempnam(sys_get_temp_dir(), 'sslcsr_');
        file_put_contents($file, $conf);
        return $file;
    }

    protected function opensslErrors() {
        $msgs = [];
        while(($e = openssl_error_string()) !== false) $msgs[] = $e;
        return implode('; ', $msgs);
    }
}
