<?php

namespace App\Messages\RemoteImages;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class Fetcher
{
    public function __construct(private Destination $policy, private Resolver $resolver, private Transport $transport, private Raster $raster) {}

    public function fetch(string $url): array
    {
        $deadline = microtime(true) + 5;
        $visited = [];
        for ($hop = 0; $hop <= 3; $hop++) {
            if (isset($visited[$url]) || microtime(true) >= $deadline) {
                break;
            }
            $visited[$url] = true;
            $destination = $this->policy->parse($url);
            $ip = $this->policy->validateAddresses($this->resolver->addresses($destination['host'], $deadline - microtime(true)));
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $response = $this->transport->get($url, $destination['host'], $destination['port'], $ip, $remaining);
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                $location = $response['headers']['location'] ?? '';
                if ($location === '') {
                    break;
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            if ($response['status'] !== 200 || isset($response['headers']['content-encoding']) && strtolower($response['headers']['content-encoding']) !== 'identity') {
                break;
            }

            return $this->raster->validate($response['body'], $response['headers']['content-type'] ?? '');
        }
        throw new \RuntimeException('Remote image unavailable.');
    }
}
