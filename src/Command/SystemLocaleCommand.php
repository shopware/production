<?php declare(strict_types=1);

namespace Shopware\Production\Command;

use Doctrine\DBAL\Connection;
use PDO;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemLocaleCommand extends Command
{
    public static $defaultName = 'system:locale-destructive';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var Connection
     */
    private $connection;

    private $activated = false;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    public function activateCommand(): void
    {
        $this->activated = true;
    }

    protected function configure(): void
    {
        $this->addArgument('locale', InputArgument::REQUIRED, 'ISO locale for the shop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);
        $output->section('Shop locale');

        if (!$this->activated) {
            $output->error('The command has not been activated by the runtime and therefore cannot be executed. It is intended to be used with system:install --locale');

            return 1;
        }

        $locale = $input->getArgument('locale');
        $this->setDefaultLanguage($locale);

        $output->success(sprintf('Successfully changed shop default locale to %s', $locale));

        return 0;
    }

    private function getLocaleId(string $iso): string
    {
        $stmt = $this->connection->prepare('SELECT locale.id FROM locale WHERE LOWER(locale.code) = LOWER(?)');
        $stmt->execute([$iso]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            throw new \RuntimeException('Locale with iso-code ' . $iso . ' not found');
        }

        return (string)$id;
    }

    private function swapDefaultLanguageId(string $newLanguageId): void
    {
        $this->connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        $stmt = $this->connection->prepare('
            UPDATE language
            SET id = :newId
            WHERE id = :oldId
        ');

        // assign new uuid to old DEFAULT
        $stmt->execute([
            'newId' => Uuid::randomBytes(),
            'oldId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
        ]);

        // change id to DEFAULT
        $stmt->execute([
            'newId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'oldId' => $newLanguageId,
        ]);

        $this->connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function getLanguageId(string $iso): ?string
    {
        $stmt = $this->connection->prepare('
            SELECT language.id
            FROM `language`
            INNER JOIN locale ON locale.id = language.translation_code_id
            WHERE LOWER(locale.code) = LOWER(?)'
        );
        $stmt->execute([$iso]);

        /** @var string|bool $column */
        $column = $stmt->fetchColumn();

        return $column ?: null;
    }

    private function setDefaultLanguage(string $locale): void
    {
        $currentLocaleStmt = $this->connection->prepare('
            SELECT locale.id, locale.code
            FROM language
            INNER JOIN locale ON translation_code_id = locale.id
            WHERE language.id = ?'
        );
        $currentLocaleStmt->execute([Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]);
        $currentLocale = $currentLocaleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentLocale) {
            throw new \RuntimeException('Default language locale not found');
        }

        $currentLocaleId = $currentLocale['id'];
        $newDefaultLocaleId = $this->getLocaleId($locale);

        // locales match -> do nothing.
        if ($currentLocaleId === $newDefaultLocaleId) {
            return;
        }

        $newDefaultLanguageId = $this->getLanguageId($locale);

        if ($locale === 'de-DE' && $currentLocale['code'] === 'en-GB') {
            $this->swapDefaultLanguageId($newDefaultLanguageId);
        } else {
            $this->changeDefaultLanguageData($newDefaultLanguageId, $currentLocale, $locale);
        }
    }

    private function changeDefaultLanguageData(string $newDefaultLanguageId, array $currentLocaleData, string $locale): void
    {
        $enGbLanguageId = $this->getLanguageId('en-GB');
        $currentLocaleId = $currentLocaleData['id'];
        $name = $locale;

        $newDefaultLocaleId = $this->getLocaleId($locale);

        if (!$newDefaultLanguageId && $enGbLanguageId) {
            $stmt = $this->connection->prepare('
                SELECT name FROM locale_translation
                WHERE language_id = :language_id
                AND locale_id = :locale_id
            ');
            $stmt->execute(['language_id' => $enGbLanguageId, 'locale_id' => $newDefaultLocaleId]);
            $name = $stmt->fetchColumn();
        }

        // swap locale.code
        $stmt = $this->connection->prepare('
            UPDATE locale SET code = :code WHERE id = :locale_id'
        );
        $stmt->execute(['code' => 'x-' . $locale . '_tmp', 'locale_id' => $currentLocaleId]);
        $stmt->execute(['code' => $currentLocaleData['code'], 'locale_id' => $newDefaultLocaleId]);
        $stmt->execute(['code' => $locale, 'locale_id' => $currentLocaleId]);

        // swap locale_translation.{name,territory}
        $setTrans = $this->connection->prepare('
                UPDATE locale_translation
                SET name = :name, territory = :territory
                WHERE locale_id = :locale_id AND language_id = :language_id'
        );

        $currentTrans = $this->getLocaleTranslations($currentLocaleId);
        $newDefTrans = $this->getLocaleTranslations($newDefaultLocaleId);

        foreach ($currentTrans as $trans) {
            $trans['locale_id'] = $newDefaultLocaleId;
            $setTrans->execute($trans);
        }
        foreach ($newDefTrans as $trans) {
            $trans['locale_id'] = $currentLocaleId;
            $setTrans->execute($trans);
        }

        $updLang = $this->connection->prepare('UPDATE language SET name = :name WHERE id = :language_id');

        // new default language does not exist -> just set to name
        if (!$newDefaultLanguageId) {
            $updLang->execute(['name' => $name, 'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]);

            return;
        }

        $langName = $this->connection->prepare('SELECT name FROM language WHERE id = :language_id');
        $langName->execute(['language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]);
        $current = $langName->fetchColumn();

        $langName->execute(['language_id' => $newDefaultLanguageId]);
        $new = $langName->fetchColumn();

        // swap name
        $updLang->execute(['name' => $new, 'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]);
        $updLang->execute(['name' => $current, 'language_id' => $newDefaultLanguageId]);
    }

    private function getLocaleTranslations(string $localeId): array
    {
        $stmt = $this->connection->prepare('
            SELECT locale_id, language_id, name, territory 
            FROM locale_translation 
            WHERE locale_id = :locale_id'
        );
        $stmt->execute(['locale_id' => $localeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
