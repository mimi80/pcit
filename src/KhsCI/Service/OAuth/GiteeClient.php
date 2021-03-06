<?php

declare(strict_types=1);

namespace KhsCI\Service\OAuth;

use Curl\Curl;
use KhsCI\Support\Log;

class GiteeClient extends GitHubClient implements OAuthInterface
{
    const TYPE = 'gitee';

    const API_URL = 'https://gitee.com/api/v5';

    const URL = 'https://gitee.com/oauth/authorize?';

    const POST_URL = 'https://gitee.com/oauth/token?';

    public $clientId;

    public $clientSecret;

    public $callbackUrl;

    public $curl;

    public function __construct($config, Curl $curl)
    {
        $this->clientId = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->callbackUrl = $config['callback_url'];
        $this->curl = $curl;
    }

    public function getLoginUrl(?string $state)
    {
        $url = static::URL.http_build_query([
                'client_id' => $this->clientId,
                'redirect_uri' => $this->callbackUrl,
                'response_type' => 'code',
            ]);

        return $url;
    }

    public function getAccessToken(string $code, ?string $state, bool $raw = false)
    {
        $url = static::POST_URL.http_build_query([
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->callbackUrl,
                'grant_type' => 'authorization_code',
            ]);

        $json = $this->curl->post($url);

        Log::connect()->debug('Gitee AccessToken Raw '.$json);

        // {"access_token":"52b","token_type":"bearer","expires_in":86400,"refresh_token":"c31e9","scope":"user_info projects pull_requests issues notes keys hook groups gists","created_at":1523757514}

        if (true === $raw) {
            return $json;
        }

        $accessToken = json_decode($json)->access_token;

        return $accessToken;
    }
}
