<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use Error;
use Exception;
use KhsCI\KhsCI;
use KhsCI\Service\OAuth\CodingClient;
use KhsCI\Service\OAuth\GiteeClient;
use KhsCI\Service\OAuth\GitHubClient;
use KhsCI\Support\Response;
use KhsCI\Support\Session;

class OAuthKernel
{
    /**
     * @var GitHubClient|GitHubClient|CodingClient|GiteeClient
     */
    protected static $oauth;

    /**
     * @var string
     */
    protected static $git_type;

    /**
     * enable state.
     *
     * @var bool
     */
    protected $state = false;

    /**
     * OAuthTrait constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $khsci = new KhsCI([], static::$git_type);

        static::$oauth = $khsci->oauth;
    }

    /**
     * 返回登录的 URL.
     */
    public function getLoginUrl(): void
    {
        $git_type = static::$git_type;

        /*
         * logout -> unset access_token
         *
         * OAuth login -> get access_token and expire from Session | expire one day
         */
        if (Session::get($git_type.'.access_token') and Session::get($git_type.'.expire') > time()) {
            $username_from_session = Session::get($git_type.'.username');

            Response::redirect(implode('/', ['/profile', $git_type, $username_from_session]));
        }

        $state = session_create_id();

        Session::put($git_type.'.state', $state);

        $url = static::$oauth->getLoginUrl($state);

        Response::redirect($url);

        exit;
    }

    /**
     * @throws Exception
     */
    public function getAccessToken(): void
    {
        if ($this->state ?? false) {
            $state = Session::pull(static::$git_type.'.state');
            $this->getAccessTokenCommon($state);
        }

        $this->getAccessTokenCommon(null);
    }

    /**
     * @param null|string $state
     *
     * @throws Exception
     */
    public function getAccessTokenCommon(?string $state): void
    {
        $code = $_GET['code'] ?? false;

        if (false === $code) {
            throw new Exception('code not found');
        }

        try {
            $access_token = static::$oauth->getAccessToken((string) $code, $state)
                ?? false;

            $git_type = static::$git_type;

            false !== $access_token && Session::put($git_type.'.access_token', $access_token);

            $khsci = new KhsCI([$git_type.'_access_token' => $access_token], $git_type);

            $userInfoArray = $khsci->user_basic_info->getUserInfo();
        } catch (Error $e) {
            throw new Exception($e->getMessage(), 500);
        }

        $uid = $userInfoArray['uid'];
        $name = $userInfoArray['name'];
        $pic = $userInfoArray['pic'];
        $email = $userInfoArray['email'];

        Session::put($git_type.'.uid', $uid);
        Session::put($git_type.'.username', $name);
        Session::put($git_type.'.pic', $pic);
        Session::put($git_type.'.email', $email);
        Session::put($git_type.'.expire', time() + 24 * 60 * 60);

        Response::redirect(getenv('CI_HOST').'/profile/'.$git_type.'/'.$name);

        exit;
    }
}
