<?php declare(strict_types=1);

namespace Shopware\CI\Service\Xml;

use SimpleXMLElement;

class Release extends SimpleXMLElement
{
    /**
     * @var Release[]|null
     */
    public $releases;

    /**
     * @var Release[]|null
     */
    public $release;

    /**
     * @var string|Release
     */
    public $tag = '';

    /**
     * @var string|Release
     */
    public $version = '';

    /**
     * @var string|Release
     */
    public $version_text = '';

    /**
     * @var string|Release
     */
    public $minimum_version = '6.1.0';

    /**
     * @var int
     */
    public $security_update = 0;

    /**
     * @var string|Release
     */
    public $public = '0';

    /**
     * @var int|Release
     */
    public $rc = 0;

    /**
     * @var int|Release
     */
    public $ea = 0;

    /**
     * @var string|Release
     */
    public $type = '';

    /**
     * @var bool|Release
     */
    public $manual = false;

    /**
     * @var string|Release
     */
    public $revision = '';

    /**
     * @var string|Release
     */
    public $release_date = '';

    /**
     * @var string|Release
     */
    public $github_repo = '';

    /**
     * @var string|Release
     */
    public $upgrade_md = '';

    /**
     * @var string|Release
     */
    public $download_link_install = '';

    /**
     * @var string|Release
     */
    public $download_link_update = '';

    /**
     * @var string|Release
     */
    public $sha1_install = '';

    /**
     * @var string|Release
     */
    public $sha1_update = '';

    /**
     * @var string|Release
     */
    public $sha256_install = '';

    /**
     * @var string|Release
     */
    public $sha256_update = '';

    /**
     * @var object|null
     */
    public $locales;

    public function addRelease(string $version): ?Release
    {
        $release = new Release('<release/>');

        $existing = $this->getRelease($version);
        if ($existing !== null) {
            throw new \RuntimeException('Cannot add ' . $version . ', it already exists.');
        }

        $parsedVersion = self::parseVersion($version);
        foreach ($parsedVersion as $key => $value) {
            $release->$key = (string) $value;
        }

        $dom = dom_import_simplexml($this);
        $ownerDocument = $dom->ownerDocument;
        if ($ownerDocument === null) {
            throw new \RuntimeException('Release xml is invalid');
        }
        $dom->insertBefore(
            $ownerDocument->importNode(dom_import_simplexml($release), true),
            $dom->firstChild
        );

        return $this->getRelease($version);
    }

    public static function parseVersion(string $version): array
    {
        if (!preg_match('/^\s*v?(\d+\.\d+\.\d+(\.\d+)?)((-|\s*)(RC(\d+)))?\s*$/i', $version, $matches)) {
            throw new \RuntimeException('Failed to parse version string ' . $version);
        }

        return [
            'version' => $matches[1],
            'version_text' => $matches[5] ?? '',
            'rc' => (int) ($matches[6] ?? 0),
        ];
    }

    public function getRelease(string $version): ?Release
    {
        $parsed = self::parseVersion($version);

        /** @var Release|null $matching */
        $matching = null;
        if ($this->release === null) {
            return null;
        }

        foreach ($this->release as $r) {
            if ($r->getVersion() !== $parsed['version'] || $r->getRc() !== $parsed['rc']) {
                continue;
            }

            if ($matching !== null) {
                throw new \RuntimeException('Found multiple versions matching ' . $version . ': ' . print_r($matching, true));
            }

            $matching = $r;
        }

        return $matching;
    }

    /**
     * @return \Generator<Release>
     */
    public function getReleases(): \Generator
    {
        if ($this->release === null) {
            throw new \RuntimeException('Releases are not set');
        }

        yield from $this->release;
    }

    public function getTag(): string
    {
        return (string) $this->tag;
    }

    public function getVersion(): string
    {
        return (string) $this->version;
    }

    public function getVersionText(): string
    {
        return (string) $this->version_text;
    }

    public function getMinimumVersion(): string
    {
        return (string) $this->minimum_version;
    }

    public function getSecurityUpdate(): string
    {
        return (string) $this->security_update;
    }

    public function isSecurityUpdate(): bool
    {
        return (string) $this->security_update === '1';
    }

    public function isPublic(): bool
    {
        return (string) $this->public === '1';
    }

    public function makePublic(): void
    {
        $this->public = '1';
    }

    public function makePrivate(): void
    {
        $this->public = '0';
    }

    public function getRc(): int
    {
        return (int) $this->rc;
    }

    public function getEa(): int
    {
        return (int) $this->ea;
    }

    public function getRevision(): string
    {
        return (string) $this->revision;
    }

    public function getReleaseDate(): string
    {
        return (string) $this->release_date;
    }

    public function getGithubRepo(): string
    {
        return (string) $this->github_repo;
    }

    public function getUpgradeMd(): string
    {
        return (string) $this->upgrade_md;
    }

    public function getDownloadLinkInstall(): string
    {
        return (string) $this->download_link_install;
    }

    public function getDownloadLinkUpdate(): string
    {
        return (string) $this->download_link_update;
    }

    public function getSha1Install(): string
    {
        return (string) $this->sha1_install;
    }

    public function getSha1Update(): string
    {
        return (string) $this->sha1_update;
    }

    public function getSha256Install(): string
    {
        return (string) $this->sha256_install;
    }

    public function getSha256Update(): string
    {
        return (string) $this->sha256_update;
    }

    public function getType(): string
    {
        return (string) $this->type;
    }

    public function isManual(): bool
    {
        return (bool) $this->manual;
    }

    public function setLocale(string $lang, array $data): void
    {
        if ($this->locales === null) {
            $this->locales = new \stdClass();
        }

        if (isset($data['changelog'])) {
            $this->locales->$lang->changelog = '';

            /** @var SimpleXMLElement $changelog */
            $changelog = $this->locales->$lang->changelog;
            $this->addCDataToNode($changelog, \PHP_EOL . $data['changelog'] . \PHP_EOL);
        }
        if (isset($data['important_changes'])) {
            $this->locales->$lang->important_changes = '';
            /** @var SimpleXMLElement $importantChanges */
            $importantChanges = $this->locales->$lang->important_changes;
            $this->addCDataToNode($importantChanges, \PHP_EOL . $data['important_changes'] . \PHP_EOL);
        }
    }

    public function setLocales(array $locales): void
    {
        foreach ($locales as $lang => $data) {
            $data['changelog'] = isset($locales[$lang]['changelog']) && \is_array($locales[$lang]['changelog'])
                ? implode(\PHP_EOL, $locales[$lang]['changelog'])
                : $locales[$lang]['changelog'] ?? '';
            if (isset($locales[$lang]['important_changes'])) {
                $data['important_changes'] = \is_array($locales[$lang]['important_changes'])
                    ? implode(\PHP_EOL, $locales[$lang]['important_changes'])
                    : $locales[$lang]['important_changes'] ?? '';
            }

            $this->setLocale($lang, $data);
        }
    }

    private function addCDataToNode(SimpleXMLElement $node, string $value): void
    {
        $domElement = dom_import_simplexml($node);
        if ($domElement === false) {
            return;
        }

        $domOwner = $domElement->ownerDocument;
        if ($domOwner === null) {
            return;
        }

        $domElement->appendChild($domOwner->createCDATASection($value));
    }
}
