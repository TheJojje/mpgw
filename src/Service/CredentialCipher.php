<?php

declare(strict_types=1);

namespace Mpgw\Service;

use RuntimeException;

/**
 * Krypterar/dekrypterar kansliga strangar (t.ex. IMAP-losenord) med
 * sodium_crypto_secretbox. Nyckeln kommer fran APP_SECRET_KEY (.env),
 * base64-kodad, 32 bytes rakt av - lagras ALDRIG i databasen.
 */
final class CredentialCipher
{
    private string $key;

    public function __construct(string $base64Key)
    {
        if ($base64Key === '') {
            throw new RuntimeException('APP_SECRET_KEY saknas - kan inte kryptera/dekryptera hemligheter.');
        }

        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'APP_SECRET_KEY har fel format. Generera med: '
                . 'php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"'
            );
        }

        $this->key = $key;
    }

    /**
     * @return array{ciphertext:string,nonce:string} rabinara strangar, redo att lagras i VARBINARY-kolumner
     */
    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return ['ciphertext' => $ciphertext, 'nonce' => $nonce];
    }

    /**
     * Krypterar flera falt som horr ihop (t.ex. headers+body pa samma
     * rad, alltid lasta/skrivna tillsammans) under EN gemensam nonce -
     * sparar en nonce-kolumn per extra falt utan att aterancvanda en
     * nonce over flera oberoende encrypt()-anrop.
     *
     * @param array<string,string> $plaintextsByKey
     * @return array{nonce:string,ciphertexts:array<string,string>}
     */
    public function encryptMany(array $plaintextsByKey): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertexts = [];
        foreach ($plaintextsByKey as $key => $plaintext) {
            $ciphertexts[$key] = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        }

        return ['nonce' => $nonce, 'ciphertexts' => $ciphertexts];
    }

    public function decrypt(string $ciphertext, string $nonce): string
    {
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Kunde inte dekryptera hemlighet - fel nyckel eller korrupt data.');
        }

        return $plaintext;
    }
}
