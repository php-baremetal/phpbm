<?php

declare(strict_types=1);

use App\Board\BoardPackages;
use App\Support\Home;
use PhpBaremetal\Spec\Sdk\Output;

/** @var list<string> */
$GLOBALS['bpTmp'] = [];

function bpTmp(string $kind): string
{
    return $GLOBALS['bpTmp'][] = sys_get_temp_dir() . '/phpbm-' . $kind . '-' . bin2hex(random_bytes(4));
}

function bpRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $f) {
            bpRemove("$path/$f");
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
}

afterEach(function () {
    foreach ($GLOBALS['bpTmp'] as $p) {
        bpRemove($p);
        bpRemove($p . '-gone');
    }
    $GLOBALS['bpTmp'] = [];
});

function bpOutput(): object
{
    return new class implements Output {
        /** @var list<string> */
        public array $warnings = [];
        /** @var list<string> */
        public array $steps = [];

        public function step(string $message): void
        {
            $this->steps[] = $message;
        }

        public function line(string $message): void
        {
        }

        public function warning(string $message): void
        {
            $this->warnings[] = $message;
        }
    };
}

function bpRegistry(): string
{
    $dir = bpTmp('reg');
    $copy = static function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (array_diff(scandir($from), ['.', '..']) as $f) {
            is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    };
    $copy(__DIR__ . '/../../fixtures/registry', $dir);
    return $dir;
}

function bpHome(): Home
{
    return new Home(bpTmp('home'));
}

function bpBreak(string $registry): void
{
    rename($registry, $registry . '-gone');
}

/** A p2 that exists but cannot be read (not a 404): a directory in place of the file. */
function bpUnreadable(string $file): void
{
    unlink($file);
    mkdir($file);
}

it('indexes the packages of the registry, php-baremetal first', function () {
    $out = bpOutput();
    $index = (new BoardPackages(bpHome(), bpRegistry()))->index($out);

    expect($index)->toBe([
        'led:ws2812' => ['php-baremetal/led-ws2812', 'acme/led-ws2812-alt'],
        'display:st77916' => ['php-baremetal/display-st77916'],
        'i2c:touch' => ['php-baremetal/display-st77916'],
        'sensor:inherited' => ['acme/inherit'],
        'sensor:good' => ['acme/badver'],
    ]);
});

it('ignores newer prereleases', function () {
    $index = (new BoardPackages(bpHome(), bpRegistry()))->index(bpOutput());
    expect($index)->not->toHaveKey('led:other')->not->toHaveKey('display:other');
});

it('warns about and skips a malformed p2', function () {
    $out = bpOutput();
    (new BoardPackages(bpHome(), bpRegistry()))->index($out);
    expect($out->warnings)->toHaveCount(1)->and($out->warnings[0])->toContain('php-baremetal/broken');
});

it('reuses the cache within an hour without reading the registry', function () {
    $home = bpHome();
    $reg = bpRegistry();
    $first = (new BoardPackages($home, $reg))->index(bpOutput());
    bpBreak($reg);

    $out = bpOutput();
    $second = (new BoardPackages($home, $reg))->index($out);
    expect($second)->toBe($first)->and($out->warnings)->toBe([])->and(is_file($home->boardPackages()))->toBeTrue();
});

it('uses an expired cache with a warning when the registry is unreachable', function () {
    $home = bpHome();
    $reg = bpRegistry();
    $t = 1_800_000_000;
    $first = (new BoardPackages($home, $reg, fn () => $t))->index(bpOutput());
    bpBreak($reg);

    $out = bpOutput();
    $second = (new BoardPackages($home, $reg, fn () => $t + 7200))->index($out);
    expect($second)->toBe($first)
        ->and($out->warnings)->toBe(["cannot reach {$reg}, using the list of board packages from " . date('Y-m-d H:i', $t)]);
});

it('is unavailable (null) with a warning without cache and registry', function () {
    $out = bpOutput();
    $reg = bpTmp('nowhere');
    expect((new BoardPackages(bpHome(), $reg))->index($out))->toBeNull()
        ->and($out->warnings)->toBe(["cannot reach {$reg}: board features are not offered (run phpbm board later)"]);
});

it('rereads with refresh', function () {
    $home = bpHome();
    $reg = bpRegistry();
    (new BoardPackages($home, $reg))->index(bpOutput());
    file_put_contents($reg . '/list.json', '{"packageNames": ["acme/led-ws2812-alt"]}');

    expect((new BoardPackages($home, $reg))->index(bpOutput()))->toHaveKey('i2c:touch');
    expect((new BoardPackages($home, $reg))->index(bpOutput(), true))->toBe(['led:ws2812' => ['acme/led-ws2812-alt']]);
});

it('does not use the cache of another registry', function () {
    $home = bpHome();
    (new BoardPackages($home, bpRegistry()))->index(bpOutput());
    $out = bpOutput();
    $other = bpTmp('other');

    expect((new BoardPackages($home, $other))->index($out))->toBeNull()->and($out->warnings)->toHaveCount(1);
});

it('treats a corrupt cache as absent', function () {
    $home = bpHome();
    mkdir($home->cache(), 0777, true);
    file_put_contents($home->boardPackages(), '{nope');
    expect((new BoardPackages($home, bpRegistry()))->index(bpOutput()))->toHaveKey('led:ws2812');
});

it('uses the cache and leaves it untouched when a p2 is unreadable', function () {
    $home = bpHome();
    $reg = bpRegistry();
    $t = 1_800_000_000;
    $first = (new BoardPackages($home, $reg, fn () => $t))->index(bpOutput());
    $before = file_get_contents($home->boardPackages());
    bpUnreadable($reg . '/p2/php-baremetal/ext-i2c.json');

    $out = bpOutput();
    $second = (new BoardPackages($home, $reg, fn () => $t + 7200))->index($out);
    expect($second)->toBe($first)
        ->and($out->warnings)->toBe(["cannot reach {$reg}, using the list of board packages from " . date('Y-m-d H:i', $t)])
        ->and(file_get_contents($home->boardPackages()))->toBe($before);
});

it('does not cache a partial index when a p2 is unreadable and there is no cache', function () {
    $home = bpHome();
    $reg = bpRegistry();
    bpUnreadable($reg . '/p2/php-baremetal/ext-i2c.json');
    expect((new BoardPackages($home, $reg))->index(bpOutput()))->toBeNull()->and(is_file($home->boardPackages()))->toBeFalse();
});

it('skips only an invalid version entry', function () {
    expect((new BoardPackages(bpHome(), bpRegistry()))->index(bpOutput()))->toHaveKey('sensor:good')->not->toHaveKey('sensor:bad');
});

it('inherits board-blocks from the newer entry and honours __unset on the selected version', function () {
    $index = (new BoardPackages(bpHome(), bpRegistry()))->index(bpOutput());
    expect($index['sensor:inherited'])->toBe(['acme/inherit'])->and($index)->not->toHaveKey('sensor:unset');
});

it('ignores a cache from the future', function () {
    $home = bpHome();
    $reg = bpRegistry();
    (new BoardPackages($home, $reg, fn () => 2_000_000_000))->index(bpOutput());
    bpUnreadable($reg . '/p2/php-baremetal/ext-i2c.json');
    $out = bpOutput();
    (new BoardPackages($home, $reg, fn () => 1_900_000_000))->index($out);
    expect($out->warnings[0])->toContain('using the list');  // expired => refetch failed => stale fallback
});

it('treats a cache with a wrong index shape as absent', function () {
    $home = bpHome();
    $reg = bpRegistry();
    mkdir($home->cache(), 0777, true);
    file_put_contents($home->boardPackages(), json_encode(['registry' => $reg, 'time' => time(), 'index' => ['a:b' => 'x']]));
    expect((new BoardPackages($home, $reg))->index(bpOutput()))->toHaveKey('led:ws2812');
});

it('resolves relative and absolute urls against the registry', function () {
    expect(BoardPackages::resolveUrl('https://r.example/sub', '/p2/%package%.json'))->toBe('https://r.example/p2/%package%.json')
        ->and(BoardPackages::resolveUrl('https://r.example/sub', 'p2/%package%.json'))->toBe('https://r.example/sub/p2/%package%.json')
        ->and(BoardPackages::resolveUrl('https://r.example', 'https://cdn.example/x.json'))->toBe('https://cdn.example/x.json')
        ->and(BoardPackages::resolveUrl('/srv/reg', '/abs/list.json'))->toBe('/abs/list.json');
});

it('accepts absolute urls in packages.json', function () {
    $reg = bpRegistry();
    file_put_contents($reg . '/packages.json', json_encode(['list' => $reg . '/list.json', 'metadata-url' => $reg . '/p2/%package%.json']));
    expect((new BoardPackages(bpHome(), $reg))->index(bpOutput()))->toHaveKey('led:ws2812');
});

it('skips a package whose p2 does not exist (404) and caches the rest', function () {
    $home = bpHome();
    $reg = bpRegistry();
    unlink($reg . '/p2/acme/inherit.json');

    $out = bpOutput();
    $index = (new BoardPackages($home, $reg))->index($out);
    expect($index)->toHaveKey('led:ws2812')->not->toHaveKey('sensor:inherited')
        ->and($out->warnings)->toContain('skipping acme/inherit: no metadata (404)')
        ->and(is_file($home->boardPackages()))->toBeTrue();
});

it('returns and caches an empty index when no package declares blocks', function () {
    $home = bpHome();
    $reg = Tests\Support\Fixtures::registryWithoutBlocks();
    $out = bpOutput();
    expect((new BoardPackages($home, $reg))->index($out))->toBe([])
        ->and($out->warnings)->toBe([])
        ->and(is_file($home->boardPackages()))->toBeTrue();

    bpRemove($reg);
    expect((new BoardPackages($home, $reg))->index(bpOutput()))->toBe([]); // from the cache
});

it('prints one progress line when it reads the registry, none from the cache', function () {
    $home = bpHome();
    $reg = bpRegistry();
    $out = bpOutput();
    (new BoardPackages($home, $reg))->index($out);
    $cached = bpOutput();
    (new BoardPackages($home, $reg))->index($cached);
    expect($out->steps)->toBe(["reading board packages from {$reg}…"])->and($cached->steps)->toBe([]);
});

it('only indexes blocks of the kind:type grammar', function () {
    $reg = bpRegistry();
    file_put_contents($reg . '/p2/acme/inherit.json', json_encode(['packages' => ['acme/inherit' => [[
        'version' => '1.0.0',
        'extra' => ['baremetal' => ['board-blocks' => ['led:ok-1', 'led-x:ws2812', 'Led:ws2812', 'led:ws_2812', 'led:-x', "led:x\n", 'led:ws.2812']]],
    ]]]]));
    $index = (new BoardPackages(bpHome(), $reg))->index(bpOutput());
    expect($index['led:ok-1'])->toBe(['acme/inherit'])
        ->and(array_keys($index))->not->toContain('led-x:ws2812')->not->toContain('Led:ws2812')
        ->not->toContain('led:ws_2812')->not->toContain('led:-x')->not->toContain("led:x\n")->not->toContain('led:ws.2812');
});

it('skips a package whose p2 answers HTTP 404 and is unavailable on another HTTP error', function () {
    $root = bpTmp('http');
    mkdir($root . '/p2/acme', 0777, true);
    file_put_contents($root . '/packages.json', '{"metadata-url": "p2/%package%.json", "list": "list.json"}');
    file_put_contents($root . '/list.json', '{"packageNames": ["acme/led", "acme/gone"]}');
    file_put_contents($root . '/p2/acme/led.json', '{"packages": {"acme/led": [{"version": "1.0.0", "extra": {"baremetal": {"board-blocks": ["led:ws2812"]}}}]}}');
    file_put_contents($root . '/router.php', '<?php
$file = __DIR__ . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (str_contains($file, "fail")) { http_response_code(500); echo "{}"; return true; }
if (!is_file($file)) { http_response_code(404); echo "not found"; return true; }
header("Content-Type: application/json"); readfile($file); return true;');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, $root . '/router.php'], [1 => ['file', $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', $null, 'w']], $pipes);
    try {
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100_000);
        }
        $out = bpOutput();
        $index = (new BoardPackages(bpHome(), "http://127.0.0.1:{$port}"))->index($out);
        expect($index)->toBe(['led:ws2812' => ['acme/led']])->and($out->warnings)->toBe(['skipping acme/gone: no metadata (404)']);

        file_put_contents($root . '/list.json', '{"packageNames": ["acme/led", "acme/fail"]}');
        $out = bpOutput();
        expect((new BoardPackages(bpHome(), "http://127.0.0.1:{$port}"))->index($out))->toBeNull()
            ->and($out->warnings)->toBe(["cannot reach http://127.0.0.1:{$port}: board features are not offered (run phpbm board later)"]);
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
});
