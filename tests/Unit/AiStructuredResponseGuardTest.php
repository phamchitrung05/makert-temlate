<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Services\Ai\Providers\Adapters\AbstractStructuredAiProvider;
use Tests\TestCase;

/** Exercise response parsing with in-memory transports, never a live generation request. */
final class AiStructuredResponseGuardTest extends TestCase
{
    public function test_openai_success_captures_allowlisted_metadata_and_resets_it_before_next_call(): void
    {
        $provider = (new GuardedStructuredFixtureProvider([
            'id' => 'chatcmpl-test', 'model' => 'reported-model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'secret' => 'key'],
            'raw_secret' => 'key', 'choices' => [$this->choice()],
        ], 'openai'))->withOutputFields(['excerpt']);
        $this->assertSame(['excerpt' => 'Generated'], $provider->generate('Source', '<p>Source</p>'));
        $metadata = $provider->responseMetadata();
        $this->assertSame('chatcmpl-test', $metadata['response_id']);
        $this->assertSame('reported-model', $metadata['reported_model']);
        $this->assertSame('stop', $metadata['finish_reason']);
        $this->assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], $metadata['usage']);
        $this->assertSame(['excerpt'], $metadata['returned_fields']);
        $this->assertArrayNotHasKey('raw_secret', $metadata);

        $provider->response = ['choices' => [['message' => ['content' => '{"excerpt":"Other"}']]]];
        $exception = $this->failure($provider);
        $this->assertSame('AI_PROVIDER_INCOMPLETE', $exception->errorCode);
        $this->assertSame('envelope', $exception->diagnostics['stage']);
        $this->assertArrayNotHasKey('response_id', $exception->diagnostics);
        $this->assertArrayNotHasKey('usage', $provider->responseMetadata());
    }

    public function test_openai_rejects_missing_nonstop_tool_and_refusal_even_with_readable_json(): void
    {
        foreach ([
            [['message' => ['content' => '{"excerpt":"Generated"}']], 'AI_PROVIDER_INCOMPLETE'],
            [$this->choice('length'), 'AI_PROVIDER_INCOMPLETE'],
            [$this->choice('content_filter'), 'AI_PROVIDER_INCOMPLETE'],
            [$this->choice('tool_calls'), 'AI_PROVIDER_TOOL_OUTPUT'],
            [['finish_reason' => 'stop', 'message' => ['content' => '{"excerpt":"Generated"}', 'tool_calls' => [['id' => 'tool']]]], 'AI_PROVIDER_TOOL_OUTPUT'],
            [['finish_reason' => 'stop', 'message' => ['content' => '{"excerpt":"Generated"}', 'function_call' => ['name' => 'tool']]], 'AI_PROVIDER_TOOL_OUTPUT'],
            [['finish_reason' => 'stop', 'message' => ['refusal' => 'No', 'content' => '{"excerpt":"Generated"}']], 'AI_PROVIDER_REFUSAL'],
        ] as [$choice, $code]) {
            $exception = $this->failure((new GuardedStructuredFixtureProvider(['choices' => [$choice]], 'openai'))->withOutputFields(['excerpt']));
            $this->assertSame($code, $exception->errorCode);
            $this->assertFalse($exception->retryable);
        }
        $this->assertSame('AI_PROVIDER_INCOMPLETE', $this->failure((new GuardedStructuredFixtureProvider(['excerpt' => 'Plain'], 'openai'))->withOutputFields(['excerpt']))->errorCode);
    }

    public function test_only_json_objects_are_accepted_and_empty_objects_fail_required_output_gate(): void
    {
        foreach (['[]', '[{"excerpt":"Generated"}]', 'null', '42', '"text"', '```json {"excerpt":"Generated"} ```', '{"excerpt":'] as $json) {
            $choice = $this->choice();
            $choice['message']['content'] = $json;
            $this->assertSame('AI_PROVIDER_INVALID_JSON', $this->failure((new GuardedStructuredFixtureProvider(['choices' => [$choice]], 'openai'))->withOutputFields(['excerpt']))->errorCode);
        }
        $choice = $this->choice();
        $choice['message']['content'] = '{}';
        $exception = $this->failure((new GuardedStructuredFixtureProvider(['choices' => [$choice]], 'openai'))->withOutputFields(['excerpt']));
        $this->assertSame('AI_PROVIDER_MISSING_FIELDS', $exception->errorCode);
        $this->assertSame([['group' => 'excerpt', 'field' => 'excerpt', 'reason' => 'missing']], $exception->diagnostics['validation_errors']);
        $choice['message']['content'] = '   ';
        $this->assertSame('AI_PROVIDER_EMPTY_CONTENT', $this->failure((new GuardedStructuredFixtureProvider(['choices' => [$choice]], 'openai'))->withOutputFields(['excerpt']))->errorCode);
    }

    public function test_gemini_joins_text_parts_and_preserves_finish_and_usage_metadata(): void
    {
        $provider = (new GuardedStructuredFixtureProvider([
            'responseId' => 'gemini-response', 'modelVersion' => 'gemini-reported',
            'usageMetadata' => ['promptTokenCount' => 2, 'candidatesTokenCount' => 3, 'totalTokenCount' => 5, 'thoughtsTokenCount' => 1],
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                ['text' => 'ignored thought', 'thought' => true], ['text' => '{"excerpt":'], ['text' => '"Generated"}'],
            ]]]],
        ], 'gemini'))->withOutputFields(['excerpt']);

        $this->assertSame(['excerpt' => 'Generated'], $provider->generate('Source', '<p>Source</p>'));
        $this->assertSame('STOP', $provider->responseMetadata()['finish_reason']);
        $this->assertSame('gemini-reported', $provider->responseMetadata()['reported_model']);
        $this->assertSame(['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5, 'reasoning_tokens' => 1], $provider->responseMetadata()['usage']);
    }

    public function test_gemini_rejects_blocks_incomplete_and_function_parts(): void
    {
        foreach ([
            [['promptFeedback' => ['blockReason' => 'SAFETY']], 'AI_PROVIDER_REFUSAL'],
            [['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"excerpt":"Generated"}']]]]]], 'AI_PROVIDER_INCOMPLETE'],
            [['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"excerpt":"Generated"}'], ['functionCall' => ['name' => 'tool']]]]]]], 'AI_PROVIDER_TOOL_OUTPUT'],
            [['candidates' => [['content' => ['parts' => [['text' => '{"excerpt":"Generated"}']]]]]], 'AI_PROVIDER_INCOMPLETE'],
        ] as [$payload, $code]) {
            $exception = $this->failure((new GuardedStructuredFixtureProvider($payload, 'gemini'))->withOutputFields(['excerpt']));
            $this->assertSame($code, $exception->errorCode);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_http_json_accepts_pure_shapes_without_finish_marker_but_guards_known_envelopes(): void
    {
        foreach ([
            ['excerpt' => 'Generated'], '{"excerpt":"Generated"}',
            ['data' => ['excerpt' => 'Generated']], ['output' => '{"excerpt":"Generated"}'],
            '{"data":{"excerpt":"Generated"}}', '{"output":"{\\"excerpt\\":\\"Generated\\"}"}',
        ] as $payload) {
            $provider = (new GuardedStructuredFixtureProvider($payload))->withOutputFields(['excerpt']);
            $this->assertSame(['excerpt' => 'Generated'], $provider->generate('Source', '<p>Source</p>'));
            $this->assertArrayNotHasKey('finish_reason', $provider->responseMetadata());
        }
        $provider = (new GuardedStructuredFixtureProvider(['data' => ['choices' => [$this->choice('length')]]]))->withOutputFields(['excerpt']);
        $this->assertSame('AI_PROVIDER_INCOMPLETE', $this->failure($provider)->errorCode);
        $this->assertSame('AI_PROVIDER_MISSING_FIELDS', $this->failure((new GuardedStructuredFixtureProvider('{"data":{}}'))->withOutputFields(['excerpt']))->errorCode);
    }

    private function choice(string $finishReason = 'stop'): array
    {
        return ['finish_reason' => $finishReason, 'message' => ['content' => '{"excerpt":"Generated"}']];
    }

    private function failure(GuardedStructuredFixtureProvider $provider): AiImportException
    {
        try {
            $provider->generate('Source', '<p>Source</p>');
            $this->fail('Invalid response must fail.');
        } catch (AiImportException $exception) {
            return $exception;
        }
    }
}

final class GuardedStructuredFixtureProvider extends AbstractStructuredAiProvider
{
    public function __construct(public mixed $response, private readonly string $format = 'http-json') {}

    protected function responseFormat(): string
    {
        return $this->format;
    }

    public function configured(): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return 'fixture';
    }

    public function modelName(): string
    {
        return 'fixture-model';
    }

    protected function requestPayload(array $input): mixed
    {
        return $this->response;
    }
}
