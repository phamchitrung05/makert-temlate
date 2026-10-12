<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo tiêu chuẩn SEO để AI sinh metadata nhất quán.
 * =====================================================================
 *
 * File này là policy snapshot cho task article.seo. Các ngưỡng ký tự là mức
 * khuyến nghị để model viết gọn, không phải giới hạn xếp hạng cứng của Google;
 * schema và validator vẫn là boundary kỹ thuật cuối cùng của ứng dụng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; file chỉ trả về mảng cấu hình SEO.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : cấu hình SEO của website và nguyên tắc Google Search hiện hành.
 * - OUTPUT: policy machine-readable cùng instructions cho AI tạo metadata.
 * - SIDE EFFECT: không gọi AI, không đọc hoặc ghi database.
 * - EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
return [
    'version' => 'google-seo.v1',

    'principles' => [
        'people_first' => true,
        'accurate_and_source_grounded' => true,
        'original_and_useful' => true,
        'match_search_intent' => true,
        'same_language_as_content' => true,
        'no_keyword_stuffing' => true,
        'no_fixed_keyword_density' => true,
        'no_meta_keywords_tag' => true,
    ],

    'focus_keyword' => [
        'required' => true,
        'min_words' => 2,
        'max_words' => 8,
        'must_describe_actual_topic' => true,
        'must_be_natural_phrase' => true,
        'avoid_disconnected_fragments' => true,
        'avoid_brand_only_phrase' => true,
        'use_exact_phrase_in_seo_title' => true,
        'use_exact_phrase_in_description' => true,
        'description_occurrences' => 1,
    ],

    'seo_title' => [
        'required' => true,
        'recommended_max_characters' => 60,
        'must_be_unique_and_descriptive' => true,
        'must_include_exact_focus_keyword' => true,
        'must_match_page_content' => true,
        'must_use_primary_content_language' => true,
        'avoid_keyword_stuffing' => true,
        'avoid_clickbait' => true,
        'avoid_trailing_ellipsis' => true,
        'avoid_repeated_boilerplate' => true,
        'brand_suffix' => null,
    ],

    'meta_description' => [
        'required' => true,
        'recommended_min_characters' => 120,
        'recommended_max_characters' => 160,
        'must_be_unique_and_accurate' => true,
        'must_include_exact_focus_keyword_once' => true,
        'must_summarize_user_benefit' => true,
        'must_not_repeat_title_verbatim' => true,
        'must_not_invent_offer_or_fact' => true,
    ],

    'slug' => [
        'required_when_destination_url_is_known' => true,
        'recommended_max_characters' => 75,
        'lowercase' => true,
        'separator' => '-',
        'use_descriptive_words' => true,
        'remove_diacritics' => true,
        'avoid_query_parameters' => true,
        'avoid_random_ids' => true,
        'avoid_dates_unless_part_of_search_intent' => true,
    ],

    'canonical' => [
        'return_only_when_destination_url_is_known' => true,
        'must_be_absolute_https_url' => true,
        'never_use_external_source_url_as_site_canonical' => true,
        'omit_when_unknown' => true,
    ],

    'robots' => [
        'return_only_when_site_policy_is_known' => true,
        'default_index' => true,
        'default_follow' => true,
        'never_override_explicit_noindex' => true,
    ],

    'open_graph' => [
        'title_should_match_seo_title' => true,
        'description_should_match_meta_description' => true,
        'omit_unknown_fields' => true,
    ],

    'content_usage' => [
        'put_focus_keyword_in_intro_when_natural' => true,
        'use_variants_and_related_terms_naturally' => true,
        'use_keyword_in_heading_only_when_helpful' => true,
        'do_not_force_exact_phrase_into_every_section' => true,
        'write_for_readers_before_search_engines' => true,
        'no_minimum_or_maximum_word_count_for_ranking' => true,
    ],

    'prohibited' => [
        'meta_keywords_field',
        'keyword_stuffing',
        'unrelated_keywords',
        'keyword_fragments_without_intent',
        'clickbait_or_misleading_claims',
        'copied_or_near_duplicate_content',
        'invented_prices_dates_reviews_or_promises',
        'external_source_as_canonical_for_rewritten_content',
    ],

    // Prompt system dùng trực tiếp cho task SEO; source/draft vẫn là dữ liệu tham khảo.
    'instructions' => <<<'SEO'
Follow the Google Search SEO policy in this configuration. Generate metadata for the finalized page, not a new article. Choose one natural primary focus keyword of 2–8 meaningful words that describes the actual search intent and topic. Do not assemble disconnected title fragments, use a brand-only phrase, or set a keyword density target.

The SEO title must be unique, accurate, concise, written in the content language, contain the exact focus keyword phrase, and stay within about 60 characters when possible. Never keyword-stuff, use clickbait, repeat boilerplate, or end with an ellipsis. The meta description must be unique and accurate, explain the page value, contain the exact focus keyword phrase once in natural wording, and stay within about 120–160 characters when possible. Do not invent facts, dates, prices, offers, reviews, or promises.

Use related terms and grammatical variants naturally in the article. Put the focus keyword in the introduction or a heading only when it reads naturally; never force it into every section and never rewrite awkward language just to repeat it. Google does not use the meta keywords tag and there is no magical word-count or keyword-density target.

Return canonical and robots fields only when the destination URL and site policy are known. Never copy an external source URL as the site's canonical URL for rewritten content. Keep Open Graph title and description aligned with the SEO title and meta description. Omit optional fields that are unknown. Return only fields allowed by the SEO schema.
SEO
];
