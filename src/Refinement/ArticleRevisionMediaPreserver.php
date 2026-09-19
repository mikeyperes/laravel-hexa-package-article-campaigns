<?php

namespace hexa_package_article_campaigns\Refinement;

/**
 * Keeps reviewed inline media outside the generative revision boundary.
 */
final class ArticleRevisionMediaPreserver
{
    /** CRITICAL — see BUGLOG.md CAMPAIGN-BUG-023. */
    private const MEDIA_PATTERN = '~<figure\b[^>]*>(?:(?!</figure>)[\s\S])*?<img\b[^>]*>(?:(?!</figure>)[\s\S])*?</figure>|<img\b[^>]*>|\[photo\b[^\]]*\]~i';

    private const BLOCK_END_PATTERN = '~</(?:p|h[1-6]|blockquote|ul|ol|table)>~i';

    /**
     * Replace every model-returned image with the exact media from the
     * reviewed article, distributed at the same approximate body positions.
     */
    public function preserve(string $originalHtml, string $revisedHtml): string
    {
        $originalMedia = $this->mediaPlacements($originalHtml);
        $cleanedRevision = preg_replace(self::MEDIA_PATTERN, '', $revisedHtml);

        if (! is_string($cleanedRevision)) {
            return $revisedHtml;
        }

        if ($originalMedia === []) {
            return $cleanedRevision;
        }

        preg_match_all(self::BLOCK_END_PATTERN, $cleanedRevision, $candidateBlocks, PREG_OFFSET_CAPTURE);
        $candidateBlockCount = count($candidateBlocks[0] ?? []);
        $grouped = [];

        foreach ($originalMedia as $media) {
            $target = $media['total_blocks'] > 0
                ? (int) round(($media['blocks_before'] / $media['total_blocks']) * $candidateBlockCount)
                : $candidateBlockCount;
            $target = max(0, min($candidateBlockCount, $target));
            $grouped[$target][] = $media['html'];
        }

        $output = $this->mediaGroup($grouped[0] ?? []);
        $cursor = 0;

        foreach (($candidateBlocks[0] ?? []) as $index => $match) {
            $end = $match[1] + strlen($match[0]);
            $output .= substr($cleanedRevision, $cursor, $end - $cursor);
            $output .= $this->mediaGroup($grouped[$index + 1] ?? []);
            $cursor = $end;
        }

        return $output.substr($cleanedRevision, $cursor);
    }

    /**
     * @return array<int, array{html: string, blocks_before: int, total_blocks: int}>
     */
    private function mediaPlacements(string $html): array
    {
        preg_match_all(self::BLOCK_END_PATTERN, $html, $blocks);
        $totalBlocks = count($blocks[0] ?? []);
        preg_match_all(self::MEDIA_PATTERN, $html, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static function (array $match) use ($html, $totalBlocks): array {
            preg_match_all(self::BLOCK_END_PATTERN, substr($html, 0, $match[1]), $before);

            return [
                'html' => $match[0],
                'blocks_before' => count($before[0] ?? []),
                'total_blocks' => $totalBlocks,
            ];
        }, $matches[0] ?? []);
    }

    /**
     * @param  array<int, string>  $media
     */
    private function mediaGroup(array $media): string
    {
        return $media === [] ? '' : "\n".implode("\n", $media)."\n";
    }
}
