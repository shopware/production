<?php

namespace Shopware\CI\Service\Xml;

use SimpleXMLElement;

class Release extends SimpleXMLElement
{
    /** @var Release[]|null */
    public $release;

    /** @var string */
    public $tag;

    /** @var string */
    public $version;

    /** @var string */
    public $version_text = '';

    /** @var string */
    public $minimum_version = '6.1.0';

    /** @var int */
    public $security_update = 0;

    /** @var string */
    public $public = '0';

    /** @var int */
    public $rc = 0;

    /** @var int */
    public $ea = 0;

    /** @var string */
    public $type;

    /** @var string */
    public $revision = '';

    /** @var string */
    public $release_date = '';

    /** @var string */
    public $github_repo;

    /** @var string */
    public $upgrade_md;

    /** @var string */
    public $download_link_install = '';

    /** @var string */
    public $download_link_update = '';

    /** @var string */
    public $sha1_install = '';

    /** @var string */
    public $sha1_update = '';

    /** @var string */
    public $sha256_install = '';

    /** @var string */
    public $sha256_update = '';

    /** @var array */
    public $locales = [];

    public function addRelease(string $version): Release
    {
        $release = new Release('<release/>');

        $existing = $this->getRelease($version);
        if ($existing !== null) {
            throw new \RuntimeException('Cannot add ' . $version . ', it already exists.');
        }

        $parsedVersion = self::parseVersion($version);
        foreach ($parsedVersion as $key => $value) {
            $release->$key = $value;
        }

        $dom = dom_import_simplexml($this);
        $dom->insertBefore(
            $dom->ownerDocument->importNode(dom_import_simplexml($release), true),
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
            'rc' =>  (int)($matches[6] ?? 0)
        ];
    }

    public function getRelease(string $version): ?Release
    {
        $parsed = self::parseVersion($version);

        $matching = null;
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
     * @return Release[]
     */
    public function getReleases(): iterable
    {
        foreach ($this->release as $r) {
            yield $r;
        }
    }

    public function getVersion(): string
    {
        return (string)$this->version;
    }

    public function getVersionText(): string
    {
        return (string)$this->version_text;
    }

    public function getMinimumVersion(): string
    {
        return (string)$this->minimum_version;
    }

    public function getSecurityUpdate(): string
    {
        return (string)$this->security_update;
    }

    public function isPublic(): bool
    {
        return (string)$this->public === '1';
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
        return (int)$this->rc;
    }

    public function getEa(): int
    {
        return (int)$this->ea;
    }

    public function getRevision(): string
    {
        return (string)$this->revision;
    }

    public function getReleaseDate(): string
    {
        return (string)$this->release_date;
    }

    public function getGithubRepo(): string
    {
        return (string)$this->github_repo;
    }

    public function getUpgradeMd(): string
    {
        return (string)$this->upgrade_md;
    }

    public function getDownloadLinkInstall(): string
    {
        return (string)$this->download_link_install;
    }

    public function getDownloadLinkUpdate(): string
    {
        return (string)$this->download_link_update;
    }

    public function getSha256Install(): string
    {
        return (string)$this->sha256_install;
    }

    public function getSha256Update(): string
    {
        return (string)$this->sha256_update;
    }

    public function getType(): string
    {
        return (string)$this->type;
    }

    public function getLocales(): array
    {
        return (array)$this->locales;
    }

    public function setLocale(string $lang, $data): void
    {
        if (isset($data['changelog'])) {
            $this->locales->$lang->changelog = '';
            $this->addCDataToNode($this->locales->$lang->changelog, PHP_EOL. $data['changelog'] . PHP_EOL);
        }
        if (isset($data['important_changes'])) {
            $this->locales->$lang->important_changes = '';
            $this->addCDataToNode($this->locales->$lang->important_changes, PHP_EOL. $data['important_changes'] . PHP_EOL);
        }
    }

    private function addCDataToNode(SimpleXMLElement $node, $value)
    {
        if ($domElement = dom_import_simplexml($node))
        {
            $domOwner = $domElement->ownerDocument;
            $domElement->appendChild($domOwner->createCDATASection((string)$value));
        }
    }

    public function setLocales(array $locales): void
    {
        foreach ($locales as $lang => $data) {
            $data['changelog'] = isset($locales[$lang]['changelog']) && is_array($locales[$lang]['changelog'])
                ? implode(PHP_EOL, $locales[$lang]['changelog'])
                : $locales[$lang]['changelog'] ?? '';
            if (isset($locales[$lang]['important_changes'])) {
                $data['important_changes'] = is_array($locales[$lang]['important_changes'])
                    ? implode(PHP_EOL, $locales[$lang]['important_changes'])
                    : $locales[$lang]['important_changes'] ?? '';
            }

            $this->setLocale($lang, $data);
        }
    }
}
