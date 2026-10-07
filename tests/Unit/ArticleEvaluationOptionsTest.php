<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ArticleEvaluationOptionsTest extends TestCase
{
    private function command(array $options): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', base_path('scripts/ai-quality/evaluate.php'),
            '--manifest='.base_path('docs/qa/task2-quality/corpus-v2/manifest.json'), '--case=Q02,Q07,Q18', ...$options], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
        $process->setTimeout(15);
        $process->run();

        return $process;
    }

    public function test_preflight_accepts_zero_and_does_not_execute_model_calls(): void
    {
        $process = $this->command(['--temperature=0']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $output['temperature_override']);
        $this->assertSame('preflight', $output['mode']);
        $this->assertSame(['C'], $output['arms']);
        $this->assertSame(9, $output['planned_calls']);
    }

    public function test_invalid_temperature_and_historical_override_are_rejected_before_execution(): void
    {
        foreach ([['--temperature=NaN'], ['--temperature=-0.1'], ['--temperature=2.1'], ['--temperature=abc'], ['--temperature=0', '--export-only']] as $options) {
            $process = $this->command($options);
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('--temperature', $process->getErrorOutput());
            $this->assertSame('', $process->getOutput());
        }
    }
}
