<?php

namespace Tests;

use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use Illuminate\Http\Client\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dựng responses ba task từ context của HTTP/provider fake.
 * CÁC HÀM: output(), httpOutput(), httpContext().
 * INPUT/OUTPUT: task/context/fields fixture -> JSON task, không model/DB thật.
 * Facts chỉ dùng trong test; không ghi vào corpus hoặc bảng chấm người đọc.
 * =====================================================================
 */
final class ArticlePipelineFixture
{
    /** Input: context/task/fields. Output: schema hợp lệ, fields sai vẫn bị validator chặn. */
    public static function output(string $task, array $context, array $fields, array $schema = []): array
    {
        if ($task === 'article.analysis-plan') {
            $block = $context['source']['blocks'][0];

            return ['knowledge' => ['facts' => [['id' => 'F01', 'claim' => $block['text'], 'source_block_ids' => [$block['id']], 'evidence' => $block['text'], 'important' => false]], 'terms' => [], 'uncertainties' => []],
                'writing_plan' => ['article_type' => 'Giải thích', 'audience' => 'Người đọc', 'angle' => 'Nội dung nguồn', 'outline' => ['Nội dung'], 'coverage_fact_ids' => ['F01']]];
        }
        $key = $task === 'article.writer' ? 'draft' : 'final';
        $schema = $schema ?: (new ArticlePromptBuilder)->schema($task, (array) ($context['brief']['requested_fields'] ?? ['title', 'content']), [], $context);
        if (! array_key_exists('content_html', $fields) && array_key_exists('content', $fields)) {
            $fields['content_html'] = $fields['content'];
        }
        $fields = array_intersect_key($fields, $schema['properties'][$key]['properties']);

        return [$key => $fields, 'used_fact_ids' => ['F01']] + ($task === 'article.writer'
            ? ['used_asset_ids' => array_column($context['images'] ?? [], 'id')] : ['issues' => []]);
    }

    /** Input: request fake của OpenAI/Gemini/http-json. Output: envelope task và context. */
    public static function httpContext(Request $request): array
    {
        if (isset($request['messages'])) {
            return json_decode($request['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
        }
        if (isset($request['contents'])) {
            $lines = explode("\n", $request['contents'][0]['parts'][0]['text']);

            return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }

        return ['task' => $request['input']['task'] ?? null, 'input' => $request['input']['task_input'] ?? []];
    }

    /** Input: HTTP fake và fields. Output: task output hoặc fields riêng không đổi. */
    public static function httpOutput(Request $request, array $fields): array
    {
        $envelope = self::httpContext($request);

        return str_starts_with($envelope['task'] ?? '', 'article.') ? self::output($envelope['task'], $envelope['input'], $fields) : $fields;
    }
}
