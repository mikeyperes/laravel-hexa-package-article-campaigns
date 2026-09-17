<?php

namespace hexa_package_article_campaigns\Orchestration;

use hexa_package_article_campaigns\Contracts\ArticleDeliveryPort;
use hexa_package_article_campaigns\Contracts\ArticleGenerationPort;
use hexa_package_article_campaigns\Contracts\CampaignWorkflowPort;
use hexa_package_article_campaigns\Contracts\SourceDiscoveryPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignStageResult;
use hexa_package_article_campaigns\Data\CampaignWorkflowState;
use hexa_package_article_campaigns\Data\PortCampaignWorkflowState;
use LogicException;

/** Compatibility adapter for consumers of the original three campaign ports. */
final class ThreePortCampaignWorkflowAdapter implements CampaignWorkflowPort
{
    public function __construct(
        private SourceDiscoveryPort $sources,
        private ArticleGenerationPort $generator,
        private ArticleDeliveryPort $delivery,
    ) {}

    public function prepare(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
    {
        return CampaignStageResult::continued($state);
    }

    public function discover(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
    {
        $runtime = $this->runtime($state);
        $runtime->sources = $this->sources->discover($context);
        $next = new CampaignWorkflowState($runtime);

        if ($runtime->sources->isEmpty()) {
            return CampaignStageResult::failed(
                $next,
                'source_pool_exhausted',
                'No eligible source was available. Generation was not called.',
            );
        }

        return CampaignStageResult::continued($next);
    }

    public function generate(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
    {
        $runtime = $this->runtime($state);
        $runtime->article = $this->generator->generate($context, $runtime->sources);
        $next = new CampaignWorkflowState($runtime);

        if (trim($runtime->article->title) === '' || trim($runtime->article->body) === '') {
            return CampaignStageResult::failed(
                $next,
                'generated_article_incomplete',
                'The generated article is incomplete.',
                ['article' => $runtime->article],
            );
        }

        return CampaignStageResult::continued($next, ['article' => $runtime->article]);
    }

    public function deliver(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
    {
        $runtime = $this->runtime($state);
        $runtime->delivery = $this->delivery->deliver($context, $runtime->article);
        $metadata = [
            'article' => $runtime->article,
            'delivery' => $runtime->delivery,
            'message' => $runtime->delivery->message,
        ];

        if (! $runtime->delivery->successful) {
            return CampaignStageResult::failed(
                new CampaignWorkflowState($runtime),
                'delivery_failed',
                (string) $runtime->delivery->message,
                $metadata,
            );
        }

        return CampaignStageResult::continued(new CampaignWorkflowState($runtime), $metadata);
    }

    private function runtime(CampaignWorkflowState $state): PortCampaignWorkflowState
    {
        if (! $state->payload instanceof PortCampaignWorkflowState) {
            throw new LogicException('Three-port campaign workflow requires PortCampaignWorkflowState.');
        }

        return $state->payload;
    }
}
