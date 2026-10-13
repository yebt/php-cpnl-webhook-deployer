<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Infrastructure;

use Deployer\Pipeline\Application\PipelineParser;
use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\Pipeline;
use Deployer\Pipeline\Domain\PipelineFactory;
use Deployer\Pipeline\Domain\PipelineValidationError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlPipelineParser implements PipelineParser
{
    public function __construct(private readonly PipelineFactory $factory = new PipelineFactory())
    {
    }

    public function parse(string $source, BuiltinVars $builtins): Pipeline
    {
        try {
            // No custom tags, objects or constants: the YAML is plain data.
            $data = Yaml::parse($source);
        } catch (ParseException $e) {
            throw new PipelineValidationError(['Invalid YAML: ' . $e->getMessage()]);
        }

        return $this->factory->create($data, $builtins);
    }
}
