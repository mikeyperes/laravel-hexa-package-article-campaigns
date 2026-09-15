<?php

namespace hexa_package_article_campaigns\Orchestration;

use hexa_package_article_campaigns\Contracts\ArticleDeliveryPort;
use hexa_package_article_campaigns\Contracts\ArticleGenerationPort;
use hexa_package_article_campaigns\Contracts\SourceDiscoveryPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignRunResult;
use hexa_package_article_campaigns\State\CampaignRunStateMachine;

/** Application-neutral sequencing; adapters own persistence and providers. */
final class CampaignOrchestrator
{
    public function __construct(
        private SourceDiscoveryPort $sources,
        private ArticleGenerationPort $generator,
        private ArticleDeliveryPort $delivery,
        private CampaignRunStateMachine $states,
    ) {}

    public function run(CampaignRunContext $context): CampaignRunResult
    {
        $state = $this->states->transition(CampaignRunStateMachine::CREATED, CampaignRunStateMachine::SOURCING);
        $sources = $this->sources->discover($context);
        if ($sources->isEmpty()) {
            return new CampaignRunResult(
                successful: false,
                state: $this->states->transition($state, CampaignRunStateMachine::FAILED),
                failureCode: 'source_pool_exhausted',
                message: 'No eligible source was available. Generation was not called.',
            );
        }

        $state = $this->states->transition($state, CampaignRunStateMachine::GENERATING);
        $article = $this->generator->generate($context, $sources);
        if (trim($article->title) === '' || trim($article->body) === '') {
            return new CampaignRunResult(
                successful: false,
                state: $this->states->transition($state, CampaignRunStateMachine::FAILED),
                failureCode: 'generated_article_incomplete',
                message: 'The generated article is incomplete.',
                article: $article,
            );
        }

        $state = $this->states->transition($state, CampaignRunStateMachine::DELIVERING);
        $delivery = $this->delivery->deliver($context, $article);
        if (! $delivery->successful) {
            return new CampaignRunResult(
                successful: false,
                state: $this->states->transition($state, CampaignRunStateMachine::FAILED),
                failureCode: 'delivery_failed',
                message: $delivery->message,
                article: $article,
                delivery: $delivery,
            );
        }

        return new CampaignRunResult(
            successful: true,
            state: $this->states->transition($state, CampaignRunStateMachine::COMPLETED),
            message: $delivery->message,
            article: $article,
            delivery: $delivery,
        );
    }
}
