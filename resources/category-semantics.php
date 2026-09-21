<?php

/**
 * Application-neutral structural semantics only. Publication subjects and
 * search terms come from the versioned manifest or explicit constructor input.
 */
return [
    'aliases' => [],
    'generic_sections' => [
        'news', 'breaking news', 'trending', 'features', 'industry updates',
        'industry trends', 'industry commentary', 'resources', 'guides',
        'tutorials', 'how to', 'expert roundups', 'international',
        'viral news', 'knowledge base',
        'from the ground up',
    ],
    'evergreen_sections' => ['knowledge base', 'resources', 'guides', 'tutorials', 'how to'],
    'source_format_sections' => [
        'press release' => 'press_release',
    ],
    'stop_words' => [
        'a', 'an', 'and', 'article', 'articles', 'at', 'by', 'for', 'from',
        'in', 'into', 'news', 'of', 'on', 'or', 'the', 'to', 'with',
    ],
    'vocabulary' => [],
];
