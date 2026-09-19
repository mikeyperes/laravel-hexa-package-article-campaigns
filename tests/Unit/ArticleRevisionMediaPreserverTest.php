<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Refinement\ArticleRevisionMediaPreserver;
use PHPUnit\Framework\TestCase;

class ArticleRevisionMediaPreserverTest extends TestCase
{
    public function test_revision_keeps_exact_reviewed_figures_when_model_omits_them(): void
    {
        $original = '<p>Opening.</p>'
            .'<figure class="wp-block-image"><img src="first.jpg" alt="First"><figcaption>First.</figcaption></figure>'
            .'<h2>Middle</h2><p>Evidence.</p>'
            .'<figure><img src="second.jpg" alt="Second"></figure><p>Closing.</p>';
        $revision = '<p>Revised opening.</p><h2>Middle</h2><p>Revised evidence.</p><p>Revised closing.</p>';

        $result = (new ArticleRevisionMediaPreserver())->preserve($original, $revision);

        $this->assertSame(1, substr_count($result, 'src="first.jpg"'));
        $this->assertSame(1, substr_count($result, 'src="second.jpg"'));
        $this->assertStringContainsString('<figcaption>First.</figcaption>', $result);
        $this->assertStringContainsString('Revised evidence.', $result);
        $this->assertLessThan(strpos($result, 'src="second.jpg"'), strpos($result, 'src="first.jpg"'));
    }

    public function test_revision_replaces_model_media_with_reviewed_media(): void
    {
        $original = '<p>Opening.</p><figure><img src="reviewed.jpg" alt="Reviewed"></figure><p>Closing.</p>';
        $revision = '<p>Revised.</p><figure><img src="invented.jpg" alt="Invented"></figure><p>Closing.</p>';

        $result = (new ArticleRevisionMediaPreserver())->preserve($original, $revision);

        $this->assertStringContainsString('src="reviewed.jpg"', $result);
        $this->assertStringNotContainsString('invented.jpg', $result);
    }

    public function test_revision_cannot_add_media_when_reviewed_article_has_none(): void
    {
        $result = (new ArticleRevisionMediaPreserver())->preserve(
            '<p>Original.</p>',
            '<p>Revised.</p><img src="invented.jpg"><p>Closing.</p>',
        );

        $this->assertSame('<p>Revised.</p><p>Closing.</p>', $result);
    }
}
