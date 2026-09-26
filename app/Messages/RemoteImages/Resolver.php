<?php

namespace App\Messages\RemoteImages;

use Symfony\Component\Process\Process;

class Resolver
{
    /** A bounded subprocess prevents the system DNS resolver exceeding the overall deadline. */
    public function addresses(string $host, float $timeout): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $process = new Process([PHP_BINDIR.'/php', '-r', '$r=@dns_get_record($argv[1], DNS_A|DNS_AAAA); echo json_encode(array_merge(array_column($r?:[],"ip"),array_column($r?:[],"ipv6")));', $host]);
        $process->setTimeout(max(0.001, $timeout));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
