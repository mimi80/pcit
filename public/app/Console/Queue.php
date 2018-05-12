<?php

declare(strict_types=1);

namespace App\Console;

use App\Builds;
use Error;
use Exception;
use KhsCI\CIException;
use KhsCI\KhsCI;
use KhsCI\Support\Cache;
use KhsCI\Support\CI;
use KhsCI\Support\DB;
use KhsCI\Support\Env;
use KhsCI\Support\Log;

class Queue
{
    private static $commit_id;

    private static $unique_id;

    private static $event_type;

    private static $build_key_id;

    private static $git_type;

    /**
     * @throws Exception
     */
    public static function queue(): void
    {
        $khsci = new KhsCI();

        $queue = $khsci->queue;

        try {
            $queue();
        } catch (CIException $e) {
            self::$commit_id = $e->getCommitId();
            self::$unique_id = $e->getUniqueId();
            self::$event_type = $e->getEventType();
            self::$build_key_id = $e->getCode();
            self::$git_type = Builds::getGitTypeByBuildKeyId(self::$build_key_id);

            /**
             * $e->getCode() is build key id.
             */
            switch ($e->getMessage()) {
                case CI::BUILD_STATUS_SKIP:
                    self::setBuildStatusSkip();

                    break;
                case CI::BUILD_STATUS_INACTIVE:
                    self::setBuildStatusInactive();

                    break;
                case CI::BUILD_STATUS_FAILED:
                    self::setBuildStatusFailed();

                    break;
                case CI::BUILD_STATUS_PASSED:
                    self::setBuildStatusPassed();

                    break;
                default:
                    self::setBuildStatusErrored();
            }

            Log::connect()->debug($e->getCode().' '.$e->getMessage());
        } catch (Exception | Error $e) {
            throw new Exception($e->getMessage());
        } finally {
            $queue::systemDelete(self::$unique_id);
            Builds::updateStopAt(self::$build_key_id);
            Cache::connect()->set('khsci_up_status', 0);
        }
    }

    /**
     * @throws Exception
     */
    private static function setBuildStatusInactive(): void
    {
        $sql = 'UPDATE builds SET build_status =? WHERE id=?';

        DB::update($sql, [CI::BUILD_STATUS_INACTIVE, self::$build_key_id]);

        self::updateGitHubCommitStatus(
            CI::GITHUB_STATUS_FAILURE,
            'This Repo is Inactive'
        );

        if ('github_app' === self::$git_type) {
        }
    }

    /**
     * @throws Exception
     */
    private static function setBuildStatusSkip(): void
    {
        $sql = 'UPDATE builds SET build_status =? WHERE id=?';

        DB::update($sql, [CI::BUILD_STATUS_SKIP, self::$build_key_id]);

        self::updateGitHubCommitStatus(
            CI::GITHUB_STATUS_SUCCESS,
            'The '.Env::get('CI_NAME').' build is skip'
        );

        if ('github_app' === self::$git_type) {
        }
    }

    /**
     * @throws Exception
     */
    private static function setBuildStatusErrored(): void
    {
        $sql = 'UPDATE builds SET build_status =? WHERE id=?';

        // 更新数据库状态
        DB::update($sql, [CI::BUILD_STATUS_ERRORED, self::$build_key_id]);

        // 通知 GitHub commit Status
        self::updateGitHubCommitStatus(
            CI::GITHUB_STATUS_ERROR,
            'The '.Env::get('CI_NAME').' build could not complete due to an error'
        );

        // 微信通知

        // GitHub App checks API

        if ('github_app' === self::$git_type) {
        }
    }

    /**
     * @throws Exception
     */
    private static function setBuildStatusFailed(): void
    {
        $sql = 'UPDATE builds SET build_status =? WHERE id=?';

        DB::update($sql, [CI::BUILD_STATUS_FAILED, self::$build_key_id]);

        self::updateGitHubCommitStatus(
            CI::GITHUB_STATUS_FAILURE,
            'The '.Env::get('CI_NAME').' build is failed'
        );

        if ('github_app' === self::$git_type) {
        }
    }

    /**
     * @throws Exception
     */
    private static function setBuildStatusPassed(): void
    {
        $sql = 'UPDATE builds SET build_status =? WHERE id=?';

        DB::update($sql, [CI::BUILD_STATUS_PASSED, self::$build_key_id]);

        self::updateGitHubCommitStatus(
            CI::GITHUB_STATUS_SUCCESS,
            'The '.Env::get('CI_NAME').' build passed'
        );

        if ('github_app' === self::$git_type) {
        }
    }

    /**
     * @param string $state
     * @param string $description
     *
     * @throws Exception
     */
    private static function updateGitHubCommitStatus(string $state, string $description): void
    {
        $sql = <<<EOF
SELECT

repo_prefix,repo_name,repo_admin

FROM repo WHERE 

rid=( SELECT rid FROM builds WHERE id=? )
EOF;
        $output = DB::select($sql, [self::$build_key_id]);

        $repo_username = $output[0]['repo_prefix'];

        $repo_name = $repo = $output[0]['repo_name'];

        $sql = 'SELECT repo_admin FROM repo WHERE repo_full_name=? AND git_type=?';

        $admin = DB::select($sql, [$repo_username.'/'.$repo_name, 'github'], true);

        foreach (json_decode($admin) as $k) {
            $sql = 'SELECT access_token FROM user WHERE uid=? AND git_type=?';

            $output = DB::select($sql, [$k, 'github'], true);

            if ($output) {
                $accessToken = $output;
                break;
            }
        }

        $khsci = new KhsCI(['github_access_token' => $accessToken], 'github');

        $output = $khsci->repo_status->create(
            $repo_username,
            $repo_name,
            self::$commit_id,
            $state,
            Env::get('CI_HOST').'/github/'.$repo_username.'/'.$repo_name.'/builds/'.self::$build_key_id,
            'continuous-integration/'.Env::get('CI_NAME').'/'.self::$event_type,
            $description
        );

        Log::connect()->debug($output);
    }
}