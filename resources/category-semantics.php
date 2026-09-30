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
    // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-153. A podcast section names a
    // medium, not a subject: "podcast news" returns celebrity, sports and
    // politics episodes. Like a press release, it pairs with the
    // publication's own topical lanes.
    'source_format_sections' => [
        'press release' => 'press_release',
        'podcast' => 'podcast',
    ],
    // Format language is structural, not publication subject vocabulary.
    // The subject still comes exclusively from sibling manifest categories.
    'source_format_terms' => [
        'press_release' => [
            'press releases', 'press release', 'news releases', 'news release',
            'announces', 'announced', 'announcement', 'launches', 'unveils',
        ],
        'podcast' => [
            'podcast', 'podcasts', 'podcast episode', 'podcast interview',
        ],
    ],
    // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-159. Language-level equivalents
    // of a qualifying word in a child category label ("Women Entrepreneurs");
    // not publication vocabulary.
    'qualifier_synonyms' => [
        'women' => ['woman', 'female'],
        'woman' => ['women', 'female'],
        'men' => ['man', 'male'],
        'man' => ['men', 'male'],
    ],
    'stop_words' => [
        'a', 'an', 'and', 'article', 'articles', 'at', 'by', 'for', 'from',
        'in', 'into', 'news', 'of', 'on', 'or', 'the', 'to', 'with',
    ],
    'vocabulary' => [],
];
