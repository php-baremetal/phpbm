<?php

declare(strict_types=1);

namespace App\Build;

use App\PhpbmException;
use App\Support\Archive;
use App\Support\Downloader;
use App\Support\Files;
use App\Support\Home;
use Illuminate\Filesystem\Filesystem;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpRelease;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\Sdk;

final readonly class SourceTree
{
    public function __construct(
        private Home $home,
        private Downloader $downloader,
        private Archive $archive,
        private Process $process,
    ) {
    }

    public function php(Sdk $sdk, PhpRelease $php, Output $out): string
    {
        $key = hash('sha256', implode("\0", [
            $php->version,
            $php->sha256,
            $sdk->descriptor->name,
            $sdk->descriptor->version,
            $sdk->patchesHash($php->minor),
        ]));

        return $this->tree("php-{$php->version}-" . substr($key, 0, 16), $php->url, $php->sha256, $sdk->patches($php->minor), "PHP {$php->version}", $out);
    }

    /**
     * Extracts and patches (sources/<name>/patches) the named external sources of the sdk.
     *
     * @param list<string> $names
     * @return array<string,string> name => directory
     */
    public function sources(Sdk $sdk, array $names, Output $out): array
    {
        $names = array_values(array_unique($names));
        sort($names);
        $dirs = [];
        foreach ($names as $name) {
            $source = $sdk->descriptor->sources[$name] ?? throw new PhpbmException("sdk {$sdk->descriptor->name} {$sdk->descriptor->version} declares no source '{$name}'");
            $key = hash('sha256', $source->sha256 . "\0" . $sdk->sourcePatchesHash($name));
            $dirs[$name] = $this->tree("{$name}-" . substr($key, 0, 16), $source->url, $source->sha256, $sdk->sourcePatches($name), $name, $out);
        }
        return $dirs;
    }

    /** @param list<string> $patches */
    private function tree(string $id, string $url, string $sha256, array $patches, string $label, Output $out): string
    {
        $dir = $this->home->trees() . "/{$id}";
        if (is_file("{$dir}.complete")) {
            return $dir;
        }

        $files = new Filesystem();
        $files->deleteDirectory($dir);
        $out->step("preparing {$label}");
        $download = $this->home->downloads() . '/' . substr($sha256, 0, 8) . '-' . basename((string) parse_url($url, PHP_URL_PATH));
        $this->downloader->fetch($url, $sha256, $download);

        $tmp = $this->home->trees() . '/.tmp-' . bin2hex(random_bytes(4));
        try {
            $root = $this->archive->extract($download, $tmp);
            foreach ($patches as $patch) {
                $result = $this->process->run(
                    ['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'apply', '--whitespace=nowarn', $patch],
                    $root,
                    ['GIT_CEILING_DIRECTORIES' => dirname($root)],
                );
                if (!$result->successful()) {
                    throw new PhpbmException(sprintf('patch %s does not apply to %s: %s', basename($patch), $label, trim($result->errorOutput)));
                }
            }
            if (!Files::rename($root, $dir)) {
                throw new PhpbmException("cannot move {$label} into {$dir}");
            }
            touch("{$dir}.complete");
        } finally {
            $files->deleteDirectory($tmp);
        }

        return $dir;
    }
}
