<?php

declare(strict_types=1);

namespace KhsCI\Service\Authorizations;

use Exception;
use KhsCI\KhsCI as PCIT;

/**
 * @see https://developer.github.com/v3/oauth_authorizations/
 */
class GitHubClient
{
    /**
     * @var PCIT
     */
    public $app;

    public function __construct(PCIT $app)
    {
        $this->app = $app;
    }

    /**
     * @param string $username
     * @param string $password
     *
     * @return mixed
     *
     * @throws Exception
     *
     * @see https://github.com/settings/tokens
     * @see https://developer.github.com/v3/oauth_authorizations/#list-your-authorizations
     */
    public function list(string $username, string $password)
    {
        $url = $this->app->api_url.'/authorizations';

        $this->app->curl->setHtpasswd($username, $password);

        return $this->app->curl->get($url);
    }
}
