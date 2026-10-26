<?php

/**
 * Writes a throwaway certificate authority and a certificate for 127.0.0.1 and localhost, signed by it, for the
 * TLS-only Redis of the multi-process-redis tests: ca.crt, redis.crt and redis.key in the given directory.
 *
 * Run by tests/Integration/run.sh: php tests/Integration/redis-tls.php <directory>
 */

declare(strict_types=1);

$directory = $argv[1] ?? throw new InvalidArgumentException('usage: redis-tls.php <directory>');
if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
    throw new RuntimeException('Cannot create ' . $directory);
}

// openssl_csr_sign() reads extensions only from a config file.
$config = tempnam(sys_get_temp_dir(), 'redis-tls');
file_put_contents($config, <<<'CNF'
    [req]
    distinguished_name = dn
    [dn]
    [ca]
    basicConstraints = critical, CA:TRUE
    keyUsage = critical, keyCertSign, cRLSign
    [server]
    basicConstraints = CA:FALSE
    keyUsage = critical, digitalSignature, keyEncipherment
    extendedKeyUsage = serverAuth
    subjectAltName = IP:127.0.0.1, DNS:localhost
    CNF);

$newKey = static fn () => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
    ?: throw new RuntimeException('Cannot create a key: ' . openssl_error_string());
$sign = static function (
    array $subject,
    OpenSSLAsymmetricKey $key,
    string $section,
    ?OpenSSLCertificate $caCert,
    OpenSSLAsymmetricKey $caKey,
) use ($config): OpenSSLCertificate {
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $section];
    $csr = openssl_csr_new($subject, $key, $options);
    if (! $csr instanceof OpenSSLCertificateSigningRequest) {
        throw new RuntimeException('Cannot create a request: ' . openssl_error_string());
    }

    return openssl_csr_sign($csr, $caCert, $caKey, 2, $options, random_int(1, PHP_INT_MAX))
        ?: throw new RuntimeException('Cannot sign: ' . openssl_error_string());
};

$caKey = $newKey();
$caCert = $sign(['commonName' => 'socket.io-server test CA'], $caKey, 'ca', null, $caKey);
$redisKey = $newKey();
$redisCert = $sign(['commonName' => 'localhost'], $redisKey, 'server', $caCert, $caKey);
unlink($config);

openssl_x509_export_to_file($caCert, $directory . '/ca.crt');
openssl_x509_export_to_file($redisCert, $directory . '/redis.crt');
openssl_pkey_export_to_file($redisKey, $directory . '/redis.key');
// Redis runs as another user in its container; the key is a throwaway.
chmod($directory . '/redis.key', 0644);
