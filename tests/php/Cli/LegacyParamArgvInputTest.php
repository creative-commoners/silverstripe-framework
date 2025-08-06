<?php

namespace SilverStripe\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SilverStripe\Cli\LegacyParamArgvInput;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class LegacyParamArgvInputTest extends SapphireTest
{
    protected $usesDatabase = false;

    public static function provideHasParameterOption(): array
    {
        return [
            'sake flush=1' => [
                'argv' => [
                    'sake',
                    'flush=1'
                ],
                'checkFor' => '--flush',
                'expected' => true,
            ],
            'sake flush=0' => [
                'argv' => [
                    'sake',
                    'flush=0'
                ],
                'checkFor' => '--flush',
                'expected' => true,
            ],
            'sake flush=1 --' => [
                'argv' => [
                    'sake',
                    'flush=1',
                    '--'
                ],
                'checkFor' => '--flush',
                'expected' => true,
            ],
            'sake -- flush=1' => [
                'argv' => [
                    'sake',
                    '--',
                    'flush=1'
                ],
                'checkFor' => '--flush',
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideHasParameterOption')]
    public function testHasParameterOption(array $argv, string $checkFor, bool $expected): void
    {
        $input = new LegacyParamArgvInput($argv);
        $this->assertSame($expected, $input->hasParameterOption($checkFor));
    }

    public static function provideGetParameterOption(): array
    {
        $scenarios = static::provideHasParameterOption();
        $scenarios['sake flush=1']['expected'] = '1';
        $scenarios['sake flush=0']['expected'] = '0';
        $scenarios['sake flush=1 --']['expected'] = '1';
        $scenarios['sake -- flush=1']['expected'] = false;
        return $scenarios;
    }

    #[DataProvider('provideGetParameterOption')]
    public function testGetParameterOption(array $argv, string $checkFor, false|string $expected): void
    {
        $input = new LegacyParamArgvInput($argv);
        $this->assertSame($expected, $input->getParameterOption($checkFor));
    }

    public static function provideBind(): array
    {
        return [
            'sake flush=1 arg=value' => [
                'argv' => [
                    'sake',
                    'flush=1',
                    'arg=value',
                ],
                'options' => [
                    new InputOption('--flush', null, InputOption::VALUE_NONE),
                    new InputOption('--arg', null, InputOption::VALUE_REQUIRED),
                ],
                'expected' => [
                    'flush' => true,
                    'arg' => 'value',
                ],
            ],
            'sake flush=yes arg=abc' => [
                'argv' => [
                    'sake',
                    'flush=yes',
                    'arg=abc',
                ],
                'options' => [
                    new InputOption('flush', null, InputOption::VALUE_NONE),
                    new InputOption('arg', null, InputOption::VALUE_OPTIONAL),
                ],
                'expected' => [
                    'flush' => true,
                    'arg' => 'abc',
                ],
            ],
            'sake flush=0 arg=' => [
                'argv' => [
                    'sake',
                    'flush=0',
                    'arg=',
                ],
                'options' => [
                    new InputOption('flush', null, InputOption::VALUE_NONE),
                    new InputOption('arg', null, InputOption::VALUE_OPTIONAL),
                ],
                'expected' => [
                    'flush' => false,
                    'arg' => null,
                ],
            ],
            'sake flush=1 -- arg=abc' => [
                'argv' => [
                    'sake',
                    'flush=1',
                    '--',
                    'arg=abc',
                ],
                'options' => [
                    new InputOption('flush', null, InputOption::VALUE_NONE),
                    new InputOption('arg', null, InputOption::VALUE_OPTIONAL),
                    // Since arg=abc is now included as an argument, we need to allow an argument.
                    new InputArgument('needed-to-avoid-error', InputArgument::REQUIRED),
                ],
                'expected' => [
                    'flush' => true,
                    'arg' => null,
                ],
            ],
            'negatable options' => [
                'argv' => [
                    'sake',
                    'merge=0',
                    'make=false',
                    'flap=1',
                    'fly=true',
                    'def-true=foo',
                    'def-false=bar',
                    // def-abc intentionally not in argv
                ],
                'options' => [
                    new InputOption('merge', mode: InputOption::VALUE_NEGATABLE),
                    new InputOption('make', mode: InputOption::VALUE_NEGATABLE),
                    new InputOption('flap', mode: InputOption::VALUE_NEGATABLE),
                    new InputOption('fly', mode: InputOption::VALUE_NEGATABLE),
                    new InputOption('def-true', mode: InputOption::VALUE_NEGATABLE, default: true),
                    new InputOption('def-false', mode: InputOption::VALUE_NEGATABLE, default: false),
                    new InputOption('def-abc', mode: InputOption::VALUE_NEGATABLE, default: 'abc'),
                ],
                'expected' => [
                    'merge' => false,
                    'make' => false,
                    'flap' => true,
                    'fly' => true,
                    'def-true' => true,
                    'def-false' => false,
                    'def-abc' => 'abc',
                ],
            ],
            'prefixed negatable option' => [
                'argv' => [
                    'sake',
                    // putting "no-" in front of a legacy option should not behave like prefixing "--no-"
                    // in front of a regular option
                    'no-merge=1',
                ],
                'options' => [
                    new InputOption('merge', mode: InputOption::VALUE_NEGATABLE),
                ],
                'expected' => [],
                'expectedExceptionMessage' => 'No arguments expected, got "no-merge=1'
            ],
        ];
    }

    #[DataProvider('provideBind')]
    public function testBind(
        array $argv,
        array $options,
        array $expected,
        ?string $expectedExceptionMessage = null,
    ): void {
        if ($expectedExceptionMessage) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($expectedExceptionMessage);
        }
        $input = new LegacyParamArgvInput($argv);
        $definition = new InputDefinition($options);
        $input->bind($definition);
        foreach ($expected as $option => $value) {
            $actual = $input->getOption($option);
            $message = sprintf(
                'Option $%s should be %s, but was %s',
                $option,
                var_export($value, true),
                var_export($actual, true)
            );
            $this->assertSame($value, $actual, $message);
        }
    }
}
