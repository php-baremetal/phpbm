<?php

declare(strict_types=1);

namespace App\Commands;

use App\Images\ImageException;
use App\Images\PngReader;
use App\Images\SliEncoder;
use App\PhpbmException;

final class ImageConvertCommand extends PhpbmCommand
{
    protected $signature = 'image:convert {input : PNG file} {output : .sli file to write}';

    protected $description = 'Turn a PNG into a .sli image for Slate';

    protected function perform(): int
    {
        $in = (string) $this->argument('input');
        $out = (string) $this->argument('output');
        try {
            $image = PngReader::read($in);
            $sli = SliEncoder::encode($image['w'], $image['h'], $image['rows']);
        } catch (ImageException $e) {
            throw new PhpbmException($e->getMessage());
        }

        $dir = dirname($out);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PhpbmException("cannot create {$dir}");
        }
        if (@file_put_contents($out, $sli) === false) {
            throw new PhpbmException("cannot write {$out}");
        }
        $this->info(sprintf('%s (%dx%d, %d bytes)', $out, $image['w'], $image['h'], strlen($sli)));

        return self::SUCCESS;
    }
}
