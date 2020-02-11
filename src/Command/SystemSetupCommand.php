<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Defuse\Crypto\Key;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemSetupCommand extends Command
{
    static public $defaultName = 'system:setup';

    /**
     * @var string
     */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force setup and recreate everything')
            ->addOption('no-check-db-connection', null, InputOption::VALUE_NONE, 'dont check db connection')
            ->addOption('database-url', null, InputOption::VALUE_OPTIONAL, 'Database dsn')
            ->addOption('generate-jwt-keys', null, InputOption::VALUE_NONE, 'Generate jwt private and public key')
            ->addOption('jwt-passphrase', null, InputOption::VALUE_OPTIONAL, 'JWT private key passphrase', 'shopware')
        ;
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force setup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = [
            'SHOPWARE_ES_HOSTS' => 'elasticsearch:9200',
            'SHOPWARE_ES_ENABLED' => '0',
            'SHOPWARE_ES_INDEXING_ENABLED' => '0',
            'SHOPWARE_ES_INDEX_PREFIX' => 'sw',
            'SHOPWARE_HTTP_CACHE_ENABLED' => '1',
            'SHOPWARE_HTTP_DEFAULT_TTL' => '7200',
            'SHOPWARE_CDN_STRATEGY_DEFAULT' => 'id'
        ];

        $io = new SymfonyStyle($input, $output);

        $io->title('Shopware setup process');
        $io->text('This tool will setup your instance.');

        if (!$input->getOption('force') && file_exists($this->projectDir . '/.env')) {
            $io->comment('Instance has already been set-up. To start over, please delete your .env file.');
            return 0;
        }

        $io->section('Application information');
        $env['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? $io->choice('Application environment', ['prod', 'dev'], 'prod');

        // TODO: optionally check http connection (create test file in public and request)
        $env['APP_URL'] = $_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? $io->ask('URL to your /public folder', 'http://shopware.local', static function ($value) {
            $value = trim($value);

            if ($value === '') {
                throw new \RuntimeException('Shop URL is required.');
            }

            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Invalid URL.');
            }

            return $value;
        });

        $io->section('Generate keys and secrets');

        $this->generateJwt($input, $io);

        $key = Key::createNewRandomKey();
        $env['APP_SECRET'] = $key->saveToAsciiSafeString();
        $env['INSTANCE_ID'] = $this->generateInstanceId();

        $io->section('Database information');

        /** @var \Throwable|null $exception */
        $exception = null;
        do {
            try {
                $exception = null;
                $env['DATABASE_URL'] = $this->getDsn($input, $io);
            } catch (\Throwable $e) {
                $exception = $e;
                $io->error($exception->getMessage());
            }
        } while ($exception && $io->confirm('Retry?', false));

        if ($exception) {
            throw $exception;
        }

        $this->createEnvFile($input, $io, $env);

        return 0;
    }

    private function getDsn(InputInterface $input, OutputInterface $io): string
    {
        $emptyValidation = static function ($value) {
            if (trim((string) $value) === '') {
                throw new \RuntimeException('This value is required.');
            }

            return $value;
        };

        $dsn = trim((string)($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL')));
        if (empty($dsn)) {
            $dsn = $input->getOption('database-url');
        }
        if ($dsn) {
            $params = parse_url($dsn);
            $dsnWithoutDb = sprintf(
                '%s://%s:%s@%s:%s',
                $params['scheme'],
                $params['user'],
                $params['pass'],
                $params['host'],
                $params['port']
            );
        } else {
            $dbUser = $io->ask('Database user', 'app', $emptyValidation);
            $dbPass = $io->askHidden('Database password');
            $dbHost = $io->ask('Database host', 'localhost', $emptyValidation);
            $dbPort = $io->ask('Database port', 3306, $emptyValidation);
            $dbName = $io->ask('Database name', 'shopware', $emptyValidation);

            $dsnWithoutDb = sprintf(
                'mysql://%s:%s@%s:%d',
                $dbUser,
                $dbPass,
                $dbHost,
                $dbPort
            );
            $dsn = $dsnWithoutDb . '/' . $dbName;
        }

        if (!$input->getOption('no-check-db-connection')) {
            $io->note('Checking database credentials');

            $connection = DriverManager::getConnection(['url' => $dsnWithoutDb, 'charset' => 'utf8mb4'], new Configuration());
            $connection->exec('SELECT 1');
        }

        return $dsn;
    }

    private function createEnvFile(InputInterface $input, SymfonyStyle $output, array $configuration): void
    {
        $output->note('Preparing .env');

        $envVars = '';
        $envFile = $this->projectDir . '/.env';


        foreach ($configuration as $key => $value) {
            $envVars .= $key . '="' . str_replace('"', '\\"', $value) . '"' . PHP_EOL;
        }

        $output->text($envFile);
        $output->writeln('');
        $output->writeln($envVars);

        if ($input->isInteractive() && !$output->confirm('Check if everything is ok. Write into "' . $envFile . '"?', false)) {
            throw new \RuntimeException('abort');
        }

        $output->note('Writing into ' . $envFile);

        file_put_contents($envFile, $envVars);
    }

    // TODO: refactor into separate command
    private function generateJwt(InputInterface $input, OutputStyle $io): int
    {
        $jwtDir = $this->projectDir . '/config/jwt';

        if (!file_exists($jwtDir) && !mkdir($jwtDir, 0700, true) && !is_dir($jwtDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $jwtDir));
        }

        // TODO: make it regenerate the public key if only private exists
        if (file_exists($jwtDir . '/private.pem') && !$input->getOption('force')) {
            $io->note('Private/Public key already exists. Skipping');
            return 0;
        }

        if (!$input->getOption('generate-jwt-keys') && !$input->hasOption('jwt-passphrase')) {
            return 0;
        }

        $passphrase = $input->getOption('jwt-passphrase');

        $io->confirm('Generate jwt keys?');

        $key = openssl_pkey_new([
            'digest_alg' => 'aes256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'encrypt_key' => $passphrase,
            'encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC
        ]);

        // export private key
        openssl_pkey_export_to_file($key, $jwtDir . '/private.pem', $passphrase);

        // export public key
        $keyData = openssl_pkey_get_details($key);
        file_put_contents($jwtDir . '/public.pem', $keyData['key']);

        chmod($jwtDir . '/private.pem', 0660);
        chmod($jwtDir . '/public.pem', 0660);

        return 0;
    }

    private function generateInstanceId(): string
    {
        $length = 32;
        $keySpace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $str = '';
        $max = mb_strlen($keySpace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keySpace[random_int(0, $max)];
        }

        return $str;
    }
}
