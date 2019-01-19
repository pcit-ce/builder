<?php

declare(strict_types=1);

namespace PCIT\Builder\Events;

use Exception;
use PCIT\Builder\BuildData;
use PCIT\Builder\Client;
use PCIT\PCIT;
use PCIT\Support\Cache;
use PCIT\Support\CacheKey;
use PCIT\Support\CI;
use PCIT\Support\Git as GitSupport;

class Git
{
    private $git;

    private $build;

    private $client;

    public function __construct($git, BuildData $build, Client $client)
    {
        $this->git = $git;
        $this->build = $build;
        $this->client = $client;
    }

    private function parseGit()
    {
        $git_config = [];

        $git = $this->git;

        $depth = $git->depth ?? 25;
        $recursive = $git->recursive ?? false;
        $skip_verify = $git->skip_verify ?? false;
        $tags = $git->tags ?? false;
        $submodule_override = $git->submodule_override ?? null;
        $hosts = $git->hosts ?? [];
        // 防止用户传入 false
        if ($depth) {
            array_push($git_config, "PLUGIN_DEPTH=$depth");
        } else {
            array_push($git_config, 'PLUGIN_DEPTH=2');
        }

        $recursive && array_push($git_config, 'PLUGIN_RECURSIVE=true');

        $skip_verify && array_push($git_config, 'PLUGIN_SKIP_VERIFY=true');

        $tags && array_push($git_config, 'PLUGIN_TAGS=true');

        $submodule_override && array_push(
            $git_config, 'PLUGIN_SUBMODULE_OVERRIDE='.json_encode($submodule_override)
        );

        $git_image = $git->image ?? 'pcit/git';

        return [$git_config, $git_image, $hosts];
    }

    /**
     * @throws Exception
     *
     * @see https://github.com/drone-plugins/drone-git
     */
    public function handle(): void
    {
        $docker_container = app(PCIT::class)->docker->container;
        $git = $this->git;
        $client = $this->client;
        $build = $this->build;

        $git_image = 'pcit/git';
        $git_config = [];
        $hosts = [];
        $git_env = null;

        if ($git) {
            list($git_config, $git_image, $hosts) = self::parseGit();
        }

        $git_url = GitSupport::getUrl($build->git_type, $build->repo_full_name);

        switch ($build->event_type) {
            case CI::BUILD_EVENT_PUSH:
                $git_env = array_merge([
                    'DRONE_REMOTE_URL='.$git_url,
                    'DRONE_WORKSPACE='.$client->workdir,
                    'DRONE_BUILD_EVENT=push',
                    'DRONE_COMMIT_SHA='.$build->commit_id,
                    'DRONE_COMMIT_REF='.'refs/heads/'.$build->branch,
                ], $git_config);

                break;
            case CI::BUILD_EVENT_PR:
                $git_env = array_merge([
                    'DRONE_REMOTE_URL='.$git_url,
                    'DRONE_WORKSPACE='.$client->workdir,
                    'DRONE_BUILD_EVENT=pull_request',
                    'DRONE_COMMIT_SHA='.$build->commit_id,
                    'DRONE_COMMIT_REF=refs/pull/'.$client->build->pull_request_number.'/head',
                ], $git_config);

                break;
            case  CI::BUILD_EVENT_TAG:
                $git_env = array_merge([
                    'DRONE_REMOTE_URL='.$git_url,
                    'DRONE_WORKSPACE='.$client->workdir,
                    'DRONE_BUILD_EVENT=tag',
                    'DRONE_COMMIT_SHA='.$build->commit_id,
                    'DRONE_COMMIT_REF=refs/tags/'.$client->build->tag,
                ], $git_config);

                break;
        }

        $config = $docker_container
            ->setEnv($git_env)
            ->setLabels([
                'com.khs1994.ci.git' => (string) $client->job_id,
                'com.khs1994.ci' => (string) $client->job_id,
            ])
            ->setBinds(["pcit_$client->job_id:$client->workdir"])
            ->setExtraHosts($hosts)
            ->setImage($git_image)
            ->setCreateJson(null)
            ->getCreateJson();

        Cache::store()->set(CacheKey::cloneKey($client->job_id), $config);
    }
}
