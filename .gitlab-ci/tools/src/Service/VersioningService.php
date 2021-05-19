<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Shopware\CI\Service\Exception\InvalidTagException;

class VersioningService
{
    /**
     * @var array
     */
    private static $stabilities = ['stable', 'RC', 'beta', 'alpha', 'dev'];

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @var array
     */
    private $allowedStabilities;

    /**
     * @var string
     */
    private $minimumStability;

    public function __construct(VersionParser $versionParser, string $stability)
    {
        $this->versionParser = $versionParser;
        $this->minimumStability = VersionParser::normalizeStability($stability);

        if ($this->minimumStability === 'dev') {
            throw new \InvalidArgumentException('minimal stability dev is not supported. Use at least alpha');
        }

        $sliceLength = 1 + (int) array_search($this->minimumStability, self::$stabilities, true);
        $this->allowedStabilities = \array_slice(self::$stabilities, 0, $sliceLength);
    }

    public static function parseTag(string $tag): array
    {
        if (!preg_match('/^v?6.(\d+).(\d+)(.(\d+))?(-(rc|RC|beta|alpha|dev)(\d+)?)?$/', trim($tag), $matches)) {
            throw new InvalidTagException($tag);
        }

        $major = 6;
        $minor = (int) $matches[1];
        $patch = (int) $matches[2];
        $build = (int) ($matches[4] ?? 0);

        $stability = 'stable';
        $preReleaseVersion = 0;
        if (isset($matches[6])) {
            $stability = VersionParser::normalizeStability($matches[6]);
            $preReleaseVersion = (int) ($matches[7] ?? 0);
        }

        return [
            'newPattern' => $minor >= 3,
            'major' => $major,
            'minor' => $minor,
            'patch' => $patch,
            'build' => $build,
            'stability' => $stability,
            'preReleaseVersion' => $preReleaseVersion,
        ];
    }

    public function getMatchingVersions(array $versions, string $constraint): array
    {
        $versions = Semver::satisfiedBy($versions, $constraint);

        $versions = array_filter($versions, function ($version) {
            return \in_array(VersionParser::parseStability($version), $this->allowedStabilities, true);
        });

        return Semver::sort($versions);
    }

    public function getNextTag(string $constraint, ?string $lastVersion = null, bool $isMinorRelease = false): string
    {
        if ($lastVersion === null) {
            return $this->getInitialMinorTag($constraint);
        }

        $stability = VersionParser::parseStability($lastVersion);
        if (!\in_array($stability, $this->allowedStabilities, true)) {
            return $this->getInitialMinorTag($constraint);
        }
        $normalizedVersion = (string) $this->versionParser->parseConstraints($lastVersion);

        $v = self::parseTag(ltrim($normalizedVersion, '= '));
        if ($v['stability'] === 'stable') {
            if (!$v['newPattern']) {
                return sprintf('v%d.%d.%d', $v['major'], $v['minor'], ($v['patch'] + 1));
            }

            if ($isMinorRelease) {
                ++$v['patch'];
                $v['build'] = 0;
            } else {
                ++$v['build'];
            }

            return sprintf('v%d.%d.%d.%d', $v['major'], $v['minor'], $v['patch'], $v['build']);
        }

        $preReleaseVersion = $v['preReleaseVersion'] + 1;
        if ($v['newPattern']) {
            return sprintf('v%d.%d.%d.%d-%s%d', $v['major'], $v['minor'], $v['patch'], $v['build'], $v['stability'], $preReleaseVersion);
        }

        return sprintf('v%d.%d.%d-%s%d', $v['major'], $v['minor'], $v['patch'], $v['stability'], $preReleaseVersion);
    }

    public function getBestMatchingBranch(string $tag, string $repositoryPath): string
    {
        if (!is_dir($repositoryPath)) {
            throw new \RuntimeException($repositoryPath . ' not found or readable');
        }

        $branches = $this->getBranchesOfTag($tag);
        if (Semver::satisfies($tag, '>= 6.3.0.0')) {
            $branches[] = 'trunk';
        }

        $matchingBranch = null;
        foreach ($branches as $branch) {
            if ($this->branchExists($branch, $repositoryPath)) {
                $matchingBranch = $branch;

                break;
            }
        }

        if ($matchingBranch === null) {
            throw new \RuntimeException('No matching branch found');
        }

        return $matchingBranch;
    }

    public static function getUpdateChannel(string $tag): int
    {
        $parsedVersion = self::parseTag($tag);

        switch ($parsedVersion['stability']) {
            case 'stable': return 100;
            case 'RC': return 80;
            case 'beta': return 60;
            case 'alpha': return 40;
            case 'dev':
            default:
                return 20;
        }
    }

    public static function getReleaseType(string $tag): string
    {
        $v = self::parseTag($tag);

        if ($v['patch'] === 0 && $v['build'] === 0) {
            return 'Major';
        }

        if ($v['build'] === 0) {
            return 'Minor';
        }

        return 'Patch';
    }

    public static function getMinorBranch(string $tag): string
    {
        $v = self::parseTag($tag);

        if ($v['newPattern']) {
            return $v['major'] . '.' . $v['minor'] . '.' . $v['patch']; // 6.3.0, 6.3.1
        }

        return $v['major'] . '.' . $v['minor']; // 6.1, 6.2
    }

    public static function getMajorBranch(string $tag): string
    {
        $v = self::parseTag($tag);

        return $v['major'] . '.' . $v['minor']; // 6.1, 6.2
    }

    private function getInitialMinorTag(string $constraint): string
    {
        $parsedConstraint = $this->versionParser->parseConstraints($constraint);
        if (!$parsedConstraint instanceof MultiConstraint) {
            throw new \RuntimeException('constrain should be a range like >= 6.1.0 && < 6.2.0');
        }

        /** @var Constraint $lowerBound */
        $lowerBound = $parsedConstraint->getConstraints()[0];

        $tag = preg_replace('/^>=/', '', (string) $lowerBound);
        $v = self::parseTag($tag);

        $suffix = '';
        if ($this->minimumStability !== 'stable') {
            $suffix = '-' . $this->minimumStability . '1';
        }

        if ($v['newPattern']) {
            return sprintf('v%d.%d.%d.0%s', $v['major'], $v['minor'], $v['patch'], $suffix);
        }

        return sprintf('v%d.%d.0%s', $v['major'], $v['minor'], $suffix);
    }

    private function getBranchesOfTag(string $tag): array
    {
        $v = self::parseTag($tag);

        return [
            $v['major'] . '.' . $v['minor'] . '.' . $v['patch'] . '.' . $v['build'],
            $v['major'] . '.' . $v['minor'] . '.' . $v['patch'],
            $v['major'] . '.' . $v['minor'],
        ];
    }

    private function branchExists(string $branch, string $repositoryPath, string $remote = 'origin'): bool
    {
        $cmd = sprintf(
            'git -C %s ls-remote --exit-code --heads %s %s >/dev/null',
            escapeshellarg($repositoryPath),
            escapeshellarg($remote),
            escapeshellarg($branch)
        );

        $returnCode = 0;
        system($cmd, $returnCode);

        return $returnCode === 0;
    }
}
