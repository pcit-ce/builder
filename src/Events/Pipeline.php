<?php

declare(strict_types=1);

namespace PCIT\Builder\Events;

use Exception;
use PCIT\Builder\BuildData;
use PCIT\Builder\CIDefault\Commands;
use PCIT\Builder\CIDefault\Image;
use PCIT\Builder\CIDefault\Status as CIDefaultStatus;
use PCIT\Builder\Client as Builder;
use PCIT\Builder\Conditional\Branch;
use PCIT\Builder\Conditional\Event;
use PCIT\Builder\Conditional\Matrix;
use PCIT\Builder\Conditional\Platform;
use PCIT\Builder\Conditional\Status;
use PCIT\Builder\Conditional\Tag;
use PCIT\Builder\Parse;
use PCIT\PCIT as PCIT;
use PCIT\Plugin\Application as Plugin;
use PCIT\Support\Cache;
use PCIT\Support\CacheKey;
use PCIT\Support\Log;

class Pipeline
{
    private $pipeline;

    private $matrix_config;

    private $build;

    private $client;

    private $cache;

    private $language;

    /**
     * Pipeline constructor.
     *
     * @param            $pipeline
     * @param BuildData  $build
     * @param Builder    $client
     * @param array|null $matrix_config
     *
     * @throws Exception
     */
    public function __construct($pipeline, ?BuildData $build, ?Builder $client, ?array $matrix_config)
    {
        $this->pipeline = $pipeline;
        $this->matrix_config = $matrix_config;
        $this->build = $build;
        $this->client = $client;
        $this->cache = Cache::store();
    }

    /**
     * @param $settings
     * @param $env
     *
     * @return array
     *
     * @throws Exception
     */
    public function handlePlugin($settings, $env): array
    {
        $text = json_encode($settings);

        // ${} 变量替换
        $result = Parse::text($text, $env);

        $settings = json_decode($result, true);

        $provider = $settings['provider'] ?? null;

        'docker' === $provider && $settings['host'] = env('CI_DOCKER_HOST');

        $provider && Log::connect()->emergency('Plugin image is '.$provider);

        $result = ['image' => null, 'env' => []];

        $adapter = '\PCIT\Plugin\Adapter\\'.strtoupper($provider);

        if (!class_exists($adapter)) {
            $env = [];

            foreach ($settings as $key => $value) {
                if ('provider' === $key) {
                    continue;
                }

                $value = \is_array($value) ? json_encode($value) : $value;
                $key = str_replace('-', '_', $key);
                $env[] = 'PCIT_'.strtoupper($key).'='.$value;
            }

            return $result = [
                'image' => $settings['provider'] ?? 'null',
                'env' => $env,
            ];
        }

        try {
            $result = (new Plugin(new $adapter($settings)))->deploy();
        } catch (\Throwable $e) {
            Log::connect()->emergency(
                'Plugin adapter error '.$e->getMessage());
        }

        $provider && Log::connect()->emergency(
            'Plugin provider result '.json_encode($result));

        return $result;
    }

    /**
     * @param $when
     *
     * @return bool
     *
     * @throws Exception
     */
    public function checkWhen($when): bool
    {
        if (!$when) {
            return false;
        }

        $when_platform = $when->platform ?? null;
        $when_event = $when->event ?? null; // tag pull_request
        $when_branch = $when->branch ?? null;
        $when_tag = $when->tag ?? null;
        $when_matrix = $when->matrix ?? null;

        if (!(new Platform($when_platform, 'linux/amd64'))->regHandle()) {
            Log::connect()->emergency('skip by platform check');

            return true;
        }

        if (!(new Event($when_event, $this->build->event_type))->handle()) {
            Log::connect()->emergency('skip by event check');

            return true;
        }

        if (!(new Branch($when_branch, $this->build->branch))->regHandle()) {
            Log::connect()->emergency('skip by branch check');

            return true;
        }

        if (!(new Tag($when_tag, $this->build->tag))->regHandle()) {
            Log::connect()->emergency('skip by tag check');

            return true;
        }

        if (!(new Matrix($when_matrix, $this->matrix_config))->handle()) {
            Log::connect()->emergency('skip by matrix check');

            return true;
        }

        return false;
    }

    /**
     * 整合 pipelineEnv systemEnv matrixEnv.
     *
     * @param array $pipelineEnv
     *
     * @return array
     */
    public function handleEnv(array $pipelineEnv): array
    {
        $preEnv = array_merge($pipelineEnv, $this->client->system_env);

        if (!$this->matrix_config) {
            return $preEnv;
        }

        $matrixEnv = [];

        foreach ($this->matrix_config as $k => $v) {
            $matrixEnv[] = $k.'='.$v;
        }

        return array_merge($preEnv, $matrixEnv);
    }

    public function handleCommands($pipeline, $pipelineContent): array
    {
        // 内容为字符串
        if (\is_string($pipelineContent)) {
            return [$pipelineContent];
        }

        // 判断内容是否为数组
        foreach (array_keys((array) $pipelineContent) as $key => $value) {
            if (\is_int($value)) {
                return $pipelineContent;
            }
        }

        $commands = $pipelineContent->commands
            ?? $pipelineContent->command
            ?? Commands::get($this->language, $pipeline);

        return \is_string($commands) ? [$commands] : $commands;
    }

    /**
     * @throws Exception
     */
    public function handle(): void
    {
        $docker_container = app(PCIT::class)->docker->container;

        $jobId = $this->client->job_id;
        $workdir = $this->client->workdir;
        $cache = $this->cache;
        $language = $this->client->language ?? 'php';

        $this->language = $language;

        foreach ($this->pipeline as $setup => $pipelineContent) {
            Log::debug(__FILE__, __LINE__, 'Handle pipeline', ['pipeline' => $setup], Log::EMERGENCY);

            $image = $pipelineContent->image ?? Image::get($language);
            $commands = $this->handleCommands($setup, $pipelineContent);
            $env = $pipelineContent->environment ?? [];
            $shell = $pipelineContent->shell ?? 'sh';
            $privileged = $pipelineContent->privileged ?? false;
            $pull = $pipelineContent->pull ?? false;
            $settings = $pipelineContent->settings ?? new \stdClass();
            $settings = (array) $settings;

            // 预处理 env
            $preEnv = $this->handleEnv($env);

            // 处理官方插件
            if ($settings) {
                ['image' => $preImage, 'env' => $deployEnv] = $this->handlePlugin($settings, $preEnv);

                $preEnv = array_merge($preEnv, $deployEnv);

                $image = ($settings['provider'] ?? null)
                    ? $preImage ?: $image : $image;
            }

            // 处理构建条件
            if ($this->checkWhen($pipelineContent->when ?? null)) {
                continue;
            }

            // 根据 pipeline 获取默认的构建条件
            $status = $pipelineContent->when->status ?? CIDefaultStatus::get($setup);
            $failure = (new Status())->handle($status, 'failure');
            $success = (new Status())->handle($status, 'success');
            $changed = (new Status())->handle($status, 'changed');

            $no_status = $status ? false : true;

            // 处理 image
            $image = Parse::text($image, $preEnv);
            // 处理 commands
            $ci_script = Parse::command($setup, $image, $commands);

            $env = array_merge(["CI_SCRIPT=$ci_script"], $preEnv);

            Log::debug(__FILE__, __LINE__, json_encode($env), [], Log::INFO);

            $cmd = $commands ? ['echo $CI_SCRIPT | base64 -d | '.$shell.' -e'] : null;
            $entrypoint = $commands ? ['/bin/sh', '-c'] : null;

            $container_config = $docker_container
                ->setEnv($env)
                ->setBinds(["pcit_$jobId:$workdir", 'pcit_cache:/tmp/pcit_cache'])
                ->setEntrypoint($entrypoint)
                ->setLabels([
                    'com.khs1994.ci.pipeline' => "$jobId",
                    'com.khs1994.ci.pipeline.name' => $setup,
                    'com.khs1994.ci.pipeline.status.no_status' => (string) $no_status,
                    'com.khs1994.ci.pipeline.status.failure' => (string) $failure,
                    'com.khs1994.ci.pipeline.status.success' => (string) $success,
                    'com.khs1994.ci.pipeline.status.changed' => (string) $changed,
                    'com.khs1994.ci' => (string) $jobId,
                ])
                ->setWorkingDir($workdir)
                ->setCmd($cmd)
                ->setImage($image)
                ->setNetworkingConfig([
                    'EndpointsConfig' => [
                        "pcit_$jobId" => [
                            'Aliases' => [
                                $setup,
                            ],
                        ],
                    ],
                ])
                ->setCreateJson(null)
                ->getCreateJson();

            $is_status = false;

            if ($failure) {
                $is_status = true;
                $cache->lpush(CacheKey::pipelineListKey($jobId, 'failure'), $setup);
                $cache->hset(CacheKey::pipelineHashKey($jobId, 'failure'), $setup, $container_config);
            }

            if ($success) {
                $is_status = true;
                $cache->lpush(CacheKey::pipelineListKey($jobId, 'success'), $setup);
                $cache->hset(CacheKey::pipelineHashKey($jobId, 'success'), $setup, $container_config);
            }

            if ($changed) {
                $is_status = true;
                $cache->lpush(CacheKey::pipelineListKey($jobId, 'changed'), $setup);
                $cache->hset(CacheKey::pipelineHashKey($jobId, 'changed'), $setup, $container_config);
            }

            if (true === $is_status) {
                continue;
            }

            $cache->lpush(CacheKey::pipelineListKey($jobId), $setup);
            $cache->hset(CacheKey::pipelineHashKey($jobId), $setup, $container_config);
        }
    }
}
