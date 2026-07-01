<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class OidcGenerateKeys extends Command
{
    protected $signature = 'oidc:generate-keys {--force : Overwrite existing keys}';

    protected $description = 'Generate RSA key pair for OIDC token signing';

    public function handle(): int
    {
        $dir = storage_path('oidc');
        $privatePath = $dir . '/private.pem';
        $publicPath = $dir . '/public.pem';
        $kidPath = $dir . '/kid.txt';

        if (file_exists($privatePath) && !$this->option('force')) {
            $this->info('OIDC keys already exist. Use --force to overwrite.');
            return self::SUCCESS;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (!$keyPair) {
            $this->error('Failed to generate RSA key pair.');
            return self::FAILURE;
        }

        openssl_pkey_export($keyPair, $privateKeyPem);
        $details = openssl_pkey_get_details($keyPair);
        $publicKeyPem = $details['key'];

        file_put_contents($privatePath, $privateKeyPem);
        chmod($privatePath, 0600);

        file_put_contents($publicPath, $publicKeyPem);
        chmod($publicPath, 0644);

        $kid = substr(md5($publicKeyPem), 0, 16);
        file_put_contents($kidPath, $kid);

        $this->info('OIDC keys generated successfully.');
        $this->info("  Private key: {$privatePath}");
        $this->info("  Public key:  {$publicPath}");
        $this->info("  Key ID:      {$kid}");

        return self::SUCCESS;
    }
}
