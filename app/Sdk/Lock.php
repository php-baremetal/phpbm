<?php

declare(strict_types=1);

namespace App\Sdk;

use PhpBaremetal\Spec\SpecException;
use PhpBaremetal\Spec\Support\Json;
use PhpBaremetal\Spec\Support\Read;

final readonly class Lock
{
    public const FILE = 'phpbm.lock';

    public function __construct(
        public string $name,
        public string $constraint,
        public string $version,
        public string $sha256,
    ) {
    }

    public static function read(string $projectDir): ?self
    {
        $file = "{$projectDir}/" . self::FILE;
        if (!is_file($file)) {
            return null;
        }
        $sdk = Json::decodeFile($file)['sdk'] ?? null;
        if (!is_array($sdk)) {
            throw new SpecException(self::FILE . ": 'sdk' must be an object");
        }

        return new self(
            Read::string($sdk, 'name', self::FILE),
            Read::string($sdk, 'constraint', self::FILE),
            Read::string($sdk, 'version', self::FILE),
            Read::sha256($sdk, 'sha256', self::FILE),
        );
    }

    public function write(string $projectDir): void
    {
        Json::writeAtomic("{$projectDir}/" . self::FILE, Json::encode(['sdk' => [
            'name' => $this->name,
            'constraint' => $this->constraint,
            'version' => $this->version,
            'sha256' => $this->sha256,
        ]]));
    }

    public function withConstraint(string $constraint): self
    {
        return new self($this->name, $constraint, $this->version, $this->sha256);
    }
}
