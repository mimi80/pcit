<?php

declare(strict_types=1);

namespace KhsCI\Service\Queue;

use App\Builds;
use Docker\Container\Container;
use Docker\Docker;
use Docker\Image\Image;
use Exception;
use KhsCI\CIException;
use KhsCI\Support\ArrayHelper;
use KhsCI\Support\Cache;
use KhsCI\Support\CI;
use KhsCI\Support\Date;
use KhsCI\Support\DB;
use KhsCI\Support\Env;
use KhsCI\Support\Git;
use KhsCI\Support\HTTP;
use KhsCI\Support\Log;

class Queue
{
    /**
     * @var
     */
    private static $gitType;

    /**
     * @var
     */
    private static $build_key_id;

    /**
     * 构建标识符.
     *
     * @var
     */
    private static $unique_id;

    /**
     * @var
     */
    private static $pull_id;

    /**
     * @var
     */
    private static $tag_name;

    private static $commit_id;

    private static $event_type;

    /**
     * @throws Exception
     */
    public function __invoke(): void
    {
        $sql = <<<'EOF'
SELECT 

id,git_type,rid,commit_id,commit_message,branch,event_type,pull_request_id,tag_name

FROM 

builds WHERE build_status=? AND event_type IN (?,?,?) ORDER BY id DESC;
EOF;

        $output = DB::select($sql, [
            CI::BUILD_STATUS_PENDING,
            CI::BUILD_EVENT_PUSH,
            CI::BUILD_EVENT_TAG,
            CI::BUILD_EVENT_PR,
        ]);

        self::$unique_id = session_create_id();

        foreach ($output as $k) {
            $build_key_id = $k['id'];

            Builds::updateStartAt((int) $build_key_id);

            $rid = $k['rid'];
            $commit_message = $k['commit_message'];
            $branch = $k['branch'];

            self::$commit_id = $k['commit_id'];
            self::$event_type = $k['event_type'];
            self::$pull_id = $k['pull_request_id'];
            self::$tag_name = $k['tag_name'];
            self::$gitType = $k['git_type'];

            self::$build_key_id = (int) $build_key_id;

            Log::connect()->debug('====== Start Build ======');

            Log::connect()->debug('Build Key id is '.self::$build_key_id);

            // commit 信息跳过构建
            self::skip($commit_message);

            // 是否启用构建
            self::getRepoBuildActivateStatus($rid);

            self::run($rid, $branch);

            // 暂时，一个队列只构建一个任务

            break;
        }
    }

    /**
     * 网页手动触发构建.
     *
     * @param string $build_key_id
     *
     * @throws Exception
     */
    public function trigger(string $build_key_id): void
    {
        $sql = <<<EOF
SELECT

git_type,rid,commit_id,branch,event_type,pull_request_id,tag_name

FROM builds

WHERE id=?
EOF;
        $output = DB::select($sql, [$build_key_id], true);

        if (!$output) {
            throw new Exception('Build Key Id Not Found', 404);
        }

        $sql = 'UPDATE builds SET build_status =? WHERE id=?';

        DB::update($sql, [CI::BUILD_STATUS_PENDING, $build_key_id]);

        foreach ($output[0] as $k => $v) {
            $rid = $k['rid'];
            $branch = $k['branch'];

            self::$commit_id = $k['commit_id'];
            self::$event_type = $k['event_type'];

            self::$gitType = $k['git_type'];
            self::$pull_id = $k['pull_request_id'];
            self::$tag_name = $k['tag_name'];

            self::run($rid, $branch);

            break;
        }
    }

    /**
     * 检查是否启用了构建.
     *
     * @param string $rid
     *
     * @throws Exception
     */
    private function getRepoBuildActivateStatus(string $rid): void
    {
        $gitType = self::$gitType;

        $sql = 'SELECT build_activate FROM repo WHERE rid=? AND git_type=?';

        $build_activate = DB::select($sql, [$rid, $gitType], true);

        if (0 === $build_activate) {
            throw new CIException(
                self::$unique_id,
                self::$commit_id,
                self::$event_type,
                CI::BUILD_STATUS_INACTIVE,
                (int) $build_activate
            );
        }
    }

    /**
     * 检查 commit 信息跳过构建.
     *
     * @param string $commit_message
     *
     * @throws Exception
     */
    private function skip(string $commit_message): void
    {
        $output = stripos($commit_message, '[skip ci]');
        $output2 = stripos($commit_message, '[ci skip]');

        if (false === $output && false === $output2) {
            return;
        }

        throw new CIException(
            self::$unique_id,
            self::$commit_id,
            self::$event_type,
            CI::BUILD_STATUS_SKIP,
            (int) self::$build_key_id
        );
    }

    /**
     * 解析 镜像名 中包含的 变量.
     *
     * @param string $image
     * @param array  $config
     *
     * @return array|mixed|string
     */
    private function getImage(string $image, array $config)
    {
        $arg = preg_match_all('/\${[0-9a-zA-Z_-]*\}/', $image, $output);

        if ($arg) {
            foreach ($output[0] as $k) {
                // ${XXX} -> md5('KHSCI')

                $var_secret = md5('KHSCI');

                $image = str_replace($k, $var_secret, $image);

                $array = explode('}', $k);

                $k = trim($array[0], '${');

                $var = '';

                if (in_array($k, array_keys($config), true)) {
                    $var = $config["$k"];
                }

                $image = str_replace($var_secret, $var, $image);
            }
        }

        return $image;
    }

    /**
     * 执行构建.
     *
     * @param        $rid
     * @param string $branch
     *
     * @throws Exception
     */
    private function run($rid, string $branch): void
    {
        $gitType = self::$gitType;
        $unique_id = self::$unique_id;
        $commit_id = self::$commit_id;
        $event_type = self::$event_type;

        Log::connect()->debug('Create Volume '.$unique_id);
        Log::connect()->debug('Create Network '.$unique_id);

        $sql = 'SELECT repo_full_name FROM repo WHERE git_type=? AND rid=?';

        $repo_full_name = DB::select($sql, [$gitType, $rid], true);

        $yaml_obj = (object) yaml_parse(HTTP::get(Git::getRawUrl(
            $gitType, $repo_full_name, $commit_id, '.drone.yml'))
        );

        // $yaml_obj = (object)yaml_parse(HTTP::get(Env::get('CI_HOST').'/.drone.yml'));

        $yaml_to_json = json_encode($yaml_obj);

        $sql = 'UPDATE builds SET config=? WHERE id=? ';

        DB::update($sql, [$yaml_to_json, self::$build_key_id]);

        // 解析 .drone.yml.

        // $git = $yaml_obj->git ?? null;

        $workspace = $yaml_obj->workspace ?? null;

        $pipeline = $yaml_obj->pipeline ?? null;

        $services = $yaml_obj->services ?? null;

        $matrix = $this->parseMatrix($yaml_obj->matrix);

        /**
         * 变量命名尽量与 docker container run 的参数保持一致.
         *
         * 项目根目录
         */
        $base_path = $workspace['base'] ?? null;

        $path = $workspace['path'] ?? $repo_full_name;

        if ('.' === $path) {
            $path = null;
        }

        // --workdir.
        $workdir = $base_path.'/'.$path;

        $docker = Docker::docker(Docker::createOptionArray(Env::get('CI_DOCKER_HOST')));
        $docker_container = $docker->container;
        $docker_image = $docker->image;
        $docker_network = $docker->network;

        $docker_image->pull('plugins/git');
        $docker_network->create($unique_id);

        $git_env = $this->getGitEnv($event_type, $repo_full_name, $workdir, $commit_id, $branch);
        $this->runGit('plugins/git', $git_env, $workdir, $unique_id, $docker_container);

        // 矩阵构建循环
        foreach ($matrix as $k => $config) {
            //启动服务
            $this->runService($services, $unique_id, $config, $docker);

            // 构建步骤
            $this->runPipeline($pipeline, $config, $workdir, $unique_id, $docker_container, $docker_image);

            // 清理
            self::systemDelete($unique_id);
        }

        // 后续根据 throw 出的异常执行对应的操作

        throw new CIException(
            self::$unique_id,
            self::$commit_id,
            self::$event_type,
            CI::BUILD_STATUS_PASSED,
            self::$build_key_id
        );
    }

    /**
     * @param array     $pipeline
     * @param array     $config
     * @param string    $work_dir
     * @param string    $unique_id
     * @param Container $docker_container
     * @param Image     $docker_image
     *
     * @throws Exception
     */
    private function runPipeline(array $pipeline,
                                 array $config,
                                 string $work_dir,
                                 string $unique_id,
                                 Container $docker_container,
                                 Image $docker_image): void
    {
        foreach ($pipeline as $setup => $array) {
            $image = $array['image'];
            $commands = $array['commands'] ?? null;
            $event = $array['when']['event'] ?? null;
            $env = $array['environment'] ?? null;

            if ($event) {
                if (is_string($event)) {
                    if (self::$event_type !== $event) {
                        continue;
                    }
                } elseif (!in_array(self::$event_type, $event, true)) {
                    Log::connect()->debug('Event '.$event.' not in '.implode(' | ', $event));

                    continue;
                }
            }

            $image = $this->getImage($image, $config);

            Log::connect()->debug('Run Container By Image '.$image);

            $docker_container
                ->setEnv(array_merge([
                    'CI_SCRIPT' => $this->parseCommand($setup, $image, $commands),
                ], self::parseEnv($env)))
                ->setHostConfig(["$unique_id:$work_dir", 'tmp:/tmp'], $unique_id)
                ->setEntrypoint(['/bin/sh', '-c'])
                ->setLabels(['com.khs1994.ci' => $unique_id])
                ->setWorkingDir($work_dir);

            $cmd = ['echo $CI_SCRIPT | base64 -d | /bin/sh -e'];

            // docker.khs1994.com:1000/username/image:1.14.0

            $image_array = explode(':', $image);

            // image not include :

            $tag = null;

            if (1 !== count($image_array)) {
                $tag = $image_array[count($image_array) - 1];
            }

            $docker_image->pull($image, $tag ?? 'latest');

            $container_id = $docker_container->start($docker_container->create($image, null, $cmd));

            Log::connect()->debug('Run Container '.$container_id);

            $this->docker_container_logs($docker_container, $container_id);
        }
    }

    /**
     * @param string $setup
     * @param        $image
     * @param        $commands
     *
     * @return string
     */
    private function parseCommand(string $setup, $image, $commands)
    {
        $content = '\n';

        $content .= 'echo;echo\n\n';

        $content .= 'echo Start Build in '.$setup.' "=>" '.$image;

        $content .= '\n\necho;echo\n\n';

        for ($i = 0; $i < count($commands); ++$i) {
            $command = addslashes($commands[$i]);

            $content .= 'echo $ '.str_replace('$', '\\\\$', $command).'\n\n';

            $content .= 'echo;echo\n\n';

            $content .= str_replace('$$', '$', $command).'\n\n';

            $content .= 'echo;echo\n\n';
        }

        return $ci_script = base64_encode(stripcslashes($content));
    }

    /**
     * @param Container $docker_container
     * @param string    $container_id
     *
     * @return array
     *
     * @throws Exception
     */
    private function docker_container_logs(Container $docker_container, string $container_id)
    {
        $redis = Cache::connect();

        if ('/bin/drone-git' === json_decode($docker_container->inspect($container_id))->Path) {
            Log::connect()->debug('Drop prev logs');

            $redis->hDel('build_log', self::$build_key_id);
        }

        $i = -1;

        $startedAt = null;
        $finishedAt = null;
        $until_time = 0;

        while (1) {
            $i = $i + 1;

            $image_status_obj = json_decode($docker_container->inspect($container_id))->State;
            $status = $image_status_obj->Status;
            $startedAt = Date::parse($image_status_obj->StartedAt);

            if ('running' === $status) {
                if (0 === $i) {
                    $since_time = $startedAt;
                    $until_time = $startedAt;
                } else {
                    $since_time = $until_time;
                    $until_time = $until_time + 1;
                }

                $image_log = $docker_container->logs(
                    $container_id, false, true, true,
                    $since_time, $until_time, true
                );

                echo $image_log;

                sleep(1);

                continue;
            } else {
                $image_log = $docker_container->logs(
                    $container_id, false, true, true, 0, 0, true
                );

                $prev_docker_log = $redis->hget('build_log', (string) self::$build_key_id);

                $redis->hset('build_log', (string) self::$build_key_id, $prev_docker_log.$image_log);

                /**
                 * 2018-05-01T05:16:37.6722812Z
                 * 0001-01-01T00:00:00Z.
                 */
                $startedAt = $image_status_obj->StartedAt;
                $finishedAt = $image_status_obj->FinishedAt;

                /**
                 * 将日志存入数据库.
                 */
                $exitCode = $image_status_obj->ExitCode;

                if (0 !== $exitCode) {
                    Log::connect()->debug('Build Error, ExitCode iss not 0');
                    throw new CIException(
                        self::$unique_id,
                        self::$commit_id,
                        self::$event_type,
                        CI::BUILD_STATUS_ERRORED,
                        self::$build_key_id
                    );
                }

                break;
            }
        }

        return [
            'start' => $startedAt,
            'stop' => $finishedAt,
        ];
    }

    /**
     * @param string $event_type
     * @param string $repo_full_name
     * @param string $workdir
     * @param string $commit_id
     * @param string $branch
     *
     * @return array
     *
     * @throws Exception
     *
     * @see https://github.com/drone-plugins/drone-git
     */
    private function getGitEnv(string $event_type,
                               string $repo_full_name,
                               string $workdir,
                               string $commit_id,
                               string $branch)
    {
        $git_url = Git::getUrl(self::$gitType, $repo_full_name);

        $git_env = null;

        switch ($event_type) {
            case CI::BUILD_EVENT_PUSH:
                $git_env = [
                    'DRONE_REMOTE_URL' => $git_url,
                    'DRONE_WORKSPACE' => $workdir,
                    'DRONE_BUILD_EVENT' => 'push',
                    'DRONE_COMMIT_SHA' => $commit_id,
                    'DRONE_COMMIT_REF' => 'refs/heads/'.$branch,
                ];

                break;
            case CI::BUILD_EVENT_PR:
                $git_env = [
                    'DRONE_REMOTE_URL' => $git_url,
                    'DRONE_WORKSPACE' => $workdir,
                    'DRONE_BUILD_EVENT' => 'pull_request',
                    'DRONE_COMMIT_SHA' => $commit_id,
                    'DRONE_COMMIT_REF' => 'refs/pull/'.self::$pull_id.'/head',
                ];

                break;
            case  CI::BUILD_EVENT_TAG:
                $git_env = [
                    'DRONE_REMOTE_URL' => $git_url,
                    'DRONE_WORKSPACE' => $workdir,
                    'DRONE_BUILD_EVENT' => 'tag',
                    'DRONE_COMMIT_SHA' => $commit_id,
                    'DRONE_COMMIT_REF' => 'refs/tags/'.self::$tag_name.'/head',
                ];

                break;
        }

        return $git_env;
    }

    /**
     * 运行 Git clone.
     *
     * @param string    $image
     * @param array     $env
     * @param           $work_dir
     * @param           $unique_id
     * @param Container $docker_container
     *
     * @throws Exception
     */
    private function runGit(string $image, array $env, $work_dir, $unique_id, Container $docker_container): void
    {
        $docker_container
            ->setEnv($env)
            ->setLabels(['com.khs1994.ci' => $unique_id])
            ->setHostConfig(["$unique_id:$work_dir"]);

        $container_id = $docker_container->start($docker_container->create($image));

        Log::connect()->debug('Run Container '.$container_id);

        $this->docker_container_logs($docker_container, $container_id);
    }

    /**
     * 解析矩阵.
     *
     * @param array $matrix
     *
     * @return array
     */
    private function parseMatrix(array $matrix)
    {
        return ArrayHelper::combination($matrix);
    }

    /**
     * @param array $env
     *
     * @return array
     */
    private function parseEnv(?array $env)
    {
        if (!$env) {
            return [];
        }

        $env_array = [];

        foreach ($env as $k) {
            $array = explode('=', $k);
            $env_array[$array[0]] = $array[1];
        }

        return $env_array;
    }

    /**
     * 运行服务.
     *
     * @param array  $service
     * @param string $unique_id
     * @param array  $config
     * @param Docker $docker
     *
     * @throws Exception
     */
    private function runService(array $service, string $unique_id, array $config, Docker $docker): void
    {
        foreach ($service as $service_name => $array) {
            $image = $array['image'];
            $env = $array['environment'] ?? null;
            $entrypoint = $array['entrypoint'] ?? null;
            $command = $array['command'] ?? null;

            $image = $this->getImage($image, $config);

            $docker_image = $docker->image;
            $docker_container = $docker->container;

            $tag = explode(':', $image)[1] ?? 'latest';

            $docker_image->pull($image, $tag);

            $container_id = $docker_container
                ->setEnv(self::parseEnv($env))
                ->setEntrypoint($entrypoint)
                ->setHostConfig(null, $unique_id)
                ->setLabels(['com.khs1994.ci' => $unique_id])
                ->create($image, $service_name, $command);

            $docker_container->start($container_id);
        }
    }

    /**
     * Remove all Docker Resource.
     *
     * @param string $unique_id
     *
     * @throws Exception
     */
    public static function systemDelete(string $unique_id): void
    {
        $docker = Docker::docker(Docker::createOptionArray(Env::get('CI_DOCKER_HOST')));

        $docker_container = $docker->container;

        // $docker_image = $docker->image;

        $docker_network = $docker->network;

        $docker_volume = $docker->volume;

        // clean container

        $output = $docker_container->list(true, null, false, [
            'label' => 'com.khs1994.ci='.self::$unique_id,
        ]);

        foreach (json_decode($output) as $k) {
            $id = $k->Id;

            if (!$id) {
                continue;
            }

            Log::connect()->debug('Delete Container '.$id);

            $docker_container->delete($id, true, true);
        }

        // don't clean image

        // clean volume

        $docker_volume->remove($unique_id);

        // clean network

        $docker_network->remove($unique_id);
    }
}