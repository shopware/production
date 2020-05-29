<?php


namespace Shopware\CI\Service;


use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

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

    /**
     * @var array
     */
    private $config;

    /**
     * @var Client
     */
    private $gitlabApiClient;

    public function __construct(VersionParser $versionParser, array $config, Client $gitlabApiClient)
    {
        $this->versionParser = $versionParser;
        $this->minimumStability = VersionParser::normalizeStability($config['stability']);

        if ($this->minimumStability === 'dev') {
            throw new \InvalidArgumentException('minimal stability dev is not supported. Use at least alpha');
        }

        $this->allowedStabilities = array_slice(
            self::$stabilities,
            0,
            1 + array_search($this->minimumStability, self::$stabilities, true)
        );
        $this->config = $config;
        $this->gitlabApiClient = $gitlabApiClient;
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

        if(!preg_match('/== 6\.(\d+)\.(\d+)\.(\d+)(-(rc|RC|beta|alpha|dev)(\d+))?/', $normalizedVersion, $matches)) {
            throw new \RuntimeException('Invalid version ' . $lastVersion);
        }

        $major = $matches[1];
        $minor = $matches[2];
        $patch = $matches[3];
        $stability = $matches[5] ?? null;

        if ($stability === null) {
            if ($major >= 3) {
                $patch++;
                return sprintf('v6.%d.%d.%d', $major, $minor, $patch);
            }
            $minor++;
            return sprintf('v6.%d.%d', $major, $minor);
        }

        $preReleaseVersionNumber = ($matches[6] ?? 0) + 1;

        if ($major >= 3) {
            return sprintf('v6.%d.%d.%d-%s%d', $major, $minor, $patch, $stability, $preReleaseVersionNumber);
        }
        return sprintf('v6.%d.%d-%s%d', $major, $minor, $stability, $preReleaseVersionNumber);
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

        $major = $matches[1];

        $suffix = '';
        if ($this->minimumStability !== 'stable') {
            $suffix = '-' . $this->minimumStability . '1';
        }

        // we use 4 digit version numbers starting with 6.3.0.0
        if ($major >= 3) {
            return sprintf('v6.%d.0.0%s', $major, $suffix);
        }

        return sprintf('v6.%d.0%s', $major, $suffix);
    }

    public function deleteTag(string $tag, array $repos): void
    {
        $pureTag = $tag;
        $ref = escapeshellarg("refs/tags/$tag");
        $tag = escapeshellarg($tag);
        $privateToken = $this->config['gitlabApiToken'];

        foreach ($repos as $repo => $repoData) {
            $path = escapeshellarg($repoData['path']);
            $githubUrl = $repoData['githubUrl'];

            $shellCode = <<<CODE
    git -C $path -d tag $tag || true
    git -C $path push origin :$ref
    curl -X DELETE -H "Private-Token: $privateToken" $githubUrl/git/refs/tags/$pureTag
CODE;

            echo 'exec: ' . $shellCode . PHP_EOL;

            system($shellCode, $retCode);

            if ($retCode !== 0) {
                echo 'Failed to delete tag for ' . $repoData['remoteUrl'] . '. Please delete by manual' . PHP_EOL;
            }
        }
    }

    public function tagAndPushRepos(string $tag, array $repos): void
    {
        $ref = escapeshellarg("refs/tags/$tag");
        $tag = escapeshellarg($tag);
        $commitMsg = escapeshellarg('Release ' . $tag);

        foreach ($repos as $repo => $repoData) {
            $path = escapeshellarg($repoData['path']);
            $remote = escapeshellarg($repoData['remoteUrl']);

            $shellCode = <<<CODE
    git -C $path tag $tag -a -m $commitMsg || true
    git -C $path remote add release  $remote
    git -C $path push release $ref
CODE;

            echo 'exec: ' . $shellCode . PHP_EOL;

            system($shellCode, $retCode);

            if ($retCode !== 0) {
                throw new \RuntimeException('Failed to push tag for ' . $repoData['remoteUrl'] . '. Please delete the tags that where already pushed');
            }
        }
    }

    public function tagAndPushPlatform(string $tag, string $commitRef, string $remote): void
    {
        $path = sys_get_temp_dir() . '/platform_' . bin2hex(random_bytes(16));
        mkdir($path);

        $path = escapeshellarg($path);

        $commitMsg = 'Release ' . $tag;
        $shellCode = <<<CODE
    git -C $path init --bare
    git -C $path remote add origin $remote
    git -C $path fetch --depth=1 origin $commitRef
    git -C $path reset --soft FETCH_HEAD
    git -C $path tag $tag -a -m "$commitMsg"
    git -C $path push origin refs/tags/$tag
CODE;

        system($shellCode, $retCode);

        if ($retCode !== 0) {
            throw new \RuntimeException('Failed tag platform and push it');
        }

        system('rm -Rf ' . $path);
    }

    public function createReleaseBranch(string $repository, string $tag, string $gitRemoteUrl): void
    {
        $repository = escapeshellarg($repository);
        $commitMsg = escapeshellarg('Release ' . $tag);
        $escapedTag = escapeshellarg($tag);
        $gitRemoteUrl = escapeshellarg($gitRemoteUrl);

        $shellCode = <<<CODE
            set -e
            git -C $repository add PLATFORM_COMMIT_SHA composer.json composer.lock public/recovery/install/data/version
            git -C $repository commit -m $commitMsg
            git -C $repository tag $escapedTag -a -m $commitMsg
            git -C $repository remote add release $gitRemoteUrl
            git -C $repository push release --tags
CODE;

        system($shellCode, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to create release branch');
        }
    }

    public function openMergeRequest(string $projectId, string $sourceBranch, string $targetBranch, string $title)
    {
        $requestOptions = [
            RequestOptions::JSON => [
                'id' => $projectId,
                'source_branch' => $sourceBranch,
                'target_branch' => $targetBranch,
                'title' => $title
            ]
        ];

        $this->gitlabApiClient->request('POST', 'projects/' . $projectId . '/merge_requests', $requestOptions);
    }
}
