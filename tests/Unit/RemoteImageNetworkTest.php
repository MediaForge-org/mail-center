<?php

use App\Messages\InlineImages;
use App\Messages\RemoteImages\Destination;
use App\Messages\RemoteImages\Fetcher;
use App\Messages\RemoteImages\Raster;
use App\Messages\RemoteImages\Resolver;
use App\Messages\RemoteImages\Transport;
use Symfony\Component\Process\Process;

it('rejects unsafe URL grammar and schemes', function (string $url) {
    expect(fn () => (new Destination)->parse($url))->toThrow(RuntimeException::class);
})->with(['file:///etc/passwd', 'ftp://public.test/x', 'http://user:pass@public.test/x', 'http://public.test:8080/x', 'http://localhost/x', 'http://2130706433/x', 'http://127.1/x', "http://public.test/\r\nx", 'http://public.test\\@127.0.0.1/x', 'http://public.test/#x']);

it('rejects all nonpublic addresses including mixed DNS results', function (string $ip) {
    expect(fn () => (new Destination)->validateAddresses(['93.184.216.34', $ip]))->toThrow(RuntimeException::class);
})->with(['127.0.0.1', '::1', '10.1.2.3', '172.16.1.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '224.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', 'fc00::1', 'fe80::1', 'ff02::1', '::', '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::', '64:ff9b::7f00:1']);

it('pins one validated lookup and rechecks every redirect including rebinding', function (string $target, array $addresses) {
    $resolver = Mockery::mock(Resolver::class);
    $resolver->shouldReceive('addresses')->once()->with('public.test', Mockery::type('float'))->andReturn(['93.184.216.34']);
    $resolver->shouldReceive('addresses')->once()->with(parse_url($target, PHP_URL_HOST), Mockery::type('float'))->andReturn($addresses);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('get')->once()->with('https://public.test/a', 'public.test', 443, '93.184.216.34', Mockery::type('float'))
        ->andReturn(['status' => 302, 'headers' => ['location' => $target], 'body' => '']);
    $fetcher = new Fetcher(new Destination, $resolver, $transport, new Raster);
    expect(fn () => $fetcher->fetch('https://public.test/a'))->toThrow(RuntimeException::class);
})->with([
    ['http://127.0.0.1/x', ['127.0.0.1']], ['http://169.254.169.254/x', ['169.254.169.254']],
    ['https://public.test/b', ['10.0.0.1']],
]);

it('bounds redirect chains and refuses loops', function (bool $loop) {
    $resolver = Mockery::mock(Resolver::class);
    $resolver->shouldReceive('addresses')->times($loop ? 1 : 4)->andReturn(['93.184.216.34']);
    $transport = Mockery::mock(Transport::class);
    $n = 0;
    $transport->shouldReceive('get')->times($loop ? 1 : 4)->andReturnUsing(function () use (&$n, $loop) {
        return ['status' => 302, 'headers' => ['location' => $loop ? '/0' : '/'.++$n], 'body' => ''];
    });
    expect(fn () => (new Fetcher(new Destination, $resolver, $transport, new Raster))->fetch('https://public.test/0'))->toThrow(RuntimeException::class);
})->with([true, false]);

it('decodes safe raster formats and returns canonical bytes with no metadata', function (string $extension, string $mime) {
    $bytes = file_get_contents(__DIR__.'/../Fixtures/inline/pixel.'.$extension);
    $image = (new Raster)->validate($bytes, $mime);
    expect($image['mime'])->toBe('image/png');
    expect(getimagesizefromstring($image['bytes'])['mime'])->toBe('image/png');
})->with([['png', 'image/png'], ['jpg', 'image/jpeg'], ['gif', 'image/gif'], ['webp', 'image/webp']]);

it('rejects malicious, mismatched, oversized, huge and malformed raster content', function () {
    $png = file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png');
    foreach ([['<svg/>', 'image/png'], ['<html>active</html>', 'image/png'], [$png, 'image/jpeg'], [$png, 'image/svg+xml'],
        [str_repeat('x', InlineImages::MAX_BYTES + 1), 'image/png'], [substr_replace($png, pack('NN', 9000, 9000), 16, 8), 'image/png'],
        [substr_replace($png, pack('NN', 4097, 4097), 16, 8), 'image/png'],
        [$png.'<script>alert(1)</script>', 'image/png'], [substr($png, 0, 30), 'image/png']] as [$bytes, $type]) {
        expect(fn () => (new Raster)->validate($bytes, $type))->toThrow(RuntimeException::class);
    }
});

it('bounds actual cURL streaming and timeouts without trusting Content-Length', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $process = new Process([PHP_BINDIR.'/php', '-S', '127.0.0.1:'.$port, __DIR__.'/../Fixtures/remote-http.php']);
    $process->start();
    try {
        for ($i = 0; $i < 50; $i++) {
            $ready = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, .02);
            if ($ready) {
                fclose($ready);
                break;
            }
            usleep(20000);
        }
        $transport = new Transport;
        // Transport alone is tested against loopback; production Destination refuses this address.
        $response = $transport->get('http://fixture.test:'.$port.'/pixel', 'fixture.test', $port, '127.0.0.1', 1);
        expect($response['body'])->toBe(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png'));
        expect(fn () => $transport->get('http://fixture.test:'.$port.'/big', 'fixture.test', $port, '127.0.0.1', 1))->toThrow(RuntimeException::class);
        expect(fn () => $transport->get('http://fixture.test:'.$port.'/slow', 'fixture.test', $port, '127.0.0.1', .1))->toThrow(RuntimeException::class);
    } finally {
        $process->stop();
    }
});

it('handles relative redirects with new pinned DNS and validates the final payload', function () {
    $resolver = Mockery::mock(Resolver::class);
    $resolver->shouldReceive('addresses')->twice()->andReturn(['93.184.216.34'], ['93.184.216.35']);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('get')->once()->with('https://public.test/a', 'public.test', 443, '93.184.216.34', Mockery::type('float'))
        ->andReturn(['status' => 302, 'headers' => ['location' => '/b'], 'body' => '']);
    $transport->shouldReceive('get')->once()->with('https://public.test/b', 'public.test', 443, '93.184.216.35', Mockery::type('float'))
        ->andReturn(['status' => 200, 'headers' => ['content-type' => 'image/png', 'set-cookie' => 'tracking=1'], 'body' => file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png')]);
    $result = (new Fetcher(new Destination, $resolver, $transport, new Raster))->fetch('https://public.test/a');
    expect(array_keys($result))->toBe(['mime', 'bytes']);
});

it('fails closed for DNS failure without contacting a destination', function () {
    $resolver = Mockery::mock(Resolver::class);
    $resolver->shouldReceive('addresses')->once()->andReturn([]);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('get');
    expect(fn () => (new Fetcher(new Destination, $resolver, $transport, new Raster))->fetch('https://public.test/a'))->toThrow(RuntimeException::class);
});
