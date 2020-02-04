<?php


namespace Shopware\CI\Service;


use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

class TaggingService
{
    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @var array
     */
    private static $stabilities = array('stable', 'RC', 'beta', 'alpha', 'dev');

    /**
     * @var string
     */
    private $minimumStability;

    /**
     * @var string[]
     */
    private $allowedStabilities;

    public function __construct(VersionParser $versionParser, string $minimumStability)
    {
        $this->versionParser = $versionParser;
        $this->minimumStability = VersionParser::normalizeStability($minimumStability);

        if ($this->minimumStability === 'dev') {
            throw new \InvalidArgumentException('minimal stability dev is not supported. Use at least alpha');
        }

        $this->allowedStabilities = array_slice(
            self::$stabilities,
            0,
            1 + array_search($this->minimumStability, self::$stabilities, true)
        );
    }

    public function getMatchingVersions(array $versions, string $constraint): array
    {
        $versions = Semver::satisfiedBy($versions, $constraint);

        $versions = array_filter($versions, function ($version) {
            return in_array(VersionParser::parseStability($version), $this->allowedStabilities, true);
        });

        return Semver::sort($versions);
    }

    public function getNextTag(string $constraint, string $lastVersion = null): string
    {
        if ($lastVersion === null) {
            return $this->getInitialMinorTag($constraint);
        }

        $stability = VersionParser::parseStability($lastVersion);
        if (!in_array($stability, $this->allowedStabilities, true)) {
            return $this->getInitialMinorTag($constraint);
        }

        $normalizedVersion = (string)$this->versionParser->parseConstraints($lastVersion);

        if(!preg_match('/== 6\.(\d+)\.(\d+)\.\d+(-(rc|RC|beta|alpha|dev)(\d+))?/', $normalizedVersion, $matches)) {
            throw new \RuntimeException('Invalid version ' . $lastVersion);
        }

        $minor = $matches[1];
        $patch = $matches[2];

        if (!isset($matches[4])) {
            $patch++;
            return sprintf('v6.%d.%d', $minor, $patch);
        }

        $stability = $matches[4];
        $preReleaseVersionNumber = ($matches[5] ?? 0) + 1;

        return sprintf('v6.%d.%d-%s%d', $minor, $patch, $stability, $preReleaseVersionNumber);
    }

    private function getInitialMinorTag(string $constraint): string
    {
        $parsedConstraint = $this->versionParser->parseConstraints($constraint);
        if (!$parsedConstraint instanceof MultiConstraint) {
            throw new \RuntimeException('constrain should be a range like >= 6.1.0 && < 6.2.0');
        }

        /** @var Constraint $lowerBound */
        $lowerBound = $parsedConstraint->getConstraints()[0];

        if(!preg_match('/>= 6\.(\d+)\.\d+\.\d+-(rc|RC|beta|alpha|dev)/', $lowerBound, $matches)) {
            throw new \RuntimeException('No initial tag found!');
        }

        $minor = $matches[1];

        $suffix = '';
        if ($this->minimumStability !== 'stable') {
            $suffix = '-' . $this->minimumStability . '1';
        }

        return sprintf('v6.%d.0%s', $minor, $suffix);
    }
}