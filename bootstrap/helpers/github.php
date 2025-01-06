<?php

use App\Models\GithubApp;
use App\Models\GitlabApp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function generateGithubToken(GithubApp $source, string $type)
{
    Log::debug('Generating GitHub token', [
        'app_id' => $source->app_id,
        'type' => $type,
        'api_url' => $source->api_url,
    ]);

    $response = Http::get("{$source->api_url}/zen");
    $serverTime = CarbonImmutable::now()->setTimezone('UTC');
    $githubTime = Carbon::parse($response->header('date'));
    $timeDiff = abs($serverTime->diffInSeconds($githubTime));

    Log::debug('Time synchronization check', [
        'server_time' => $serverTime->format('Y-m-d H:i:s'),
        'github_time' => $githubTime->format('Y-m-d H:i:s'),
        'difference_seconds' => $timeDiff,
    ]);

    if ($timeDiff > 50) {
        Log::error('System time out of sync with GitHub', [
            'time_difference' => $timeDiff,
            'server_time' => $serverTime->format('Y-m-d H:i:s'),
            'github_time' => $githubTime->format('Y-m-d H:i:s'),
        ]);
        throw new \Exception(
            'System time is out of sync with GitHub API time:<br>'.
            '- System time: '.$serverTime->format('Y-m-d H:i:s').' UTC<br>'.
            '- GitHub time: '.$githubTime->format('Y-m-d H:i:s').' UTC<br>'.
            '- Difference: '.$timeDiff.' seconds<br>'.
            'Please synchronize your system clock.'
        );
    }

    $signingKey = InMemory::plainText($source->privateKey->private_key);
    $algorithm = new Sha256;
    $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
    $now = CarbonImmutable::now()->setTimezone('UTC');
    $now = $now->setTime($now->format('H'), $now->format('i'), $now->format('s'));

    $jwt = $tokenBuilder
        ->issuedBy($source->app_id)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+8 minutes'))
        ->getToken($algorithm, $signingKey)
        ->toString();

    Log::debug('JWT token generated', [
        'token_type' => $type,
        'issued_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
        'expires_at' => $now->modify('+8 minutes')->format('Y-m-d H:i:s'),
    ]);

    return match ($type) {
        'jwt' => $jwt,
        'installation' => (function () use ($source, $jwt) {
            Log::debug('Requesting installation token', [
                'app_id' => $source->app_id,
                'installation_id' => $source->installation_id,
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer $jwt",
                'Accept' => 'application/vnd.github.machine-man-preview+json',
            ])->post("{$source->api_url}/app/installations/{$source->installation_id}/access_tokens");

            if (! $response->successful()) {
                $error = data_get($response->json(), 'message', 'no error message found');
                Log::error('Failed to get installation token', [
                    'status_code' => $response->status(),
                    'error_message' => $error,
                    'app_id' => $source->app_id,
                ]);
                throw new RuntimeException("Failed to get installation token for {$source->name} with error: ".$error);
            }

            Log::debug('Successfully obtained installation token', [
                'app_id' => $source->app_id,
            ]);

            return $response->json()['token'];
        })(),
        default => throw new \InvalidArgumentException("Unsupported token type: {$type}")
    };
}

function generateGithubInstallationToken(GithubApp $source)
{
    return generateGithubToken($source, 'installation');
}

function generateGithubJwt(GithubApp $source)
{
    return generateGithubToken($source, 'jwt');
}

function githubApi(GithubApp|GitlabApp|null $source, string $endpoint, string $method = 'get', ?array $data = null, bool $throwError = true)
{
    if (is_null($source)) {
        throw new \Exception('Source is required for API calls');
    }

    if ($source->getMorphClass() !== GithubApp::class) {
        throw new \InvalidArgumentException("Unsupported source type: {$source->getMorphClass()}");
    }

    if ($source->is_public) {
        $response = Http::GitHub($source->api_url)->$method($endpoint);
    } else {
        $token = generateGithubInstallationToken($source);
        if ($data && in_array(strtolower($method), ['post', 'patch', 'put'])) {
            $response = Http::GitHub($source->api_url, $token)->$method($endpoint, $data);
        } else {
            $response = Http::GitHub($source->api_url, $token)->$method($endpoint);
        }
    }

    if (! $response->successful() && $throwError) {
        $resetTime = Carbon::parse((int) $response->header('X-RateLimit-Reset'))->format('Y-m-d H:i:s');
        $errorMessage = data_get($response->json(), 'message', 'no error message found');
        $remainingCalls = $response->header('X-RateLimit-Remaining', '0');

        throw new \Exception(
            'GitHub API call failed:<br>'.
            "Error: {$errorMessage}<br>".
            'Rate Limit Status:<br>'.
            "- Remaining Calls: {$remainingCalls}<br>".
            "- Reset Time: {$resetTime} UTC"
        );
    }

    return [
        'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
        'rate_limit_reset' => $response->header('X-RateLimit-Reset'),
        'data' => collect($response->json()),
    ];
}

function getInstallationPath(GithubApp $source)
{
    $github = GithubApp::where('uuid', $source->uuid)->first();
    $name = str(Str::kebab($github->name));
    $installation_path = $github->html_url === 'https://github.com' ? 'apps' : 'github-apps';

    return "$github->html_url/$installation_path/$name/installations/new";
}

function getPermissionsPath(GithubApp $source)
{
    $github = GithubApp::where('uuid', $source->uuid)->first();
    $name = str(Str::kebab($github->name));

    return "$github->html_url/settings/apps/$name/permissions";
}
