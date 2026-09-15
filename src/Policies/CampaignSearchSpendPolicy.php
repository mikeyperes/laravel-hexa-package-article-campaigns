<?php

namespace hexa_package_article_campaigns\Policies;

final class CampaignSearchSpendPolicy
{
    /**
     * @param array<string, float|int> $usage
     * @param array<string, bool|float|int> $controls
     * @return array{allowed:bool,reason_code:?string,message:string,scope:string,actual:float|int|null,limit:float|int|null}
     */
    public function decide(array $usage, array $controls): array
    {
        if (! (bool) ($controls['enabled'] ?? true)) {
            return $this->allowed();
        }

        $checks = [
            [
                'code' => 'pre_generation_failure_velocity',
                'scope' => 'campaign',
                'actual' => (int) ($usage['consecutive_pre_generation_failures'] ?? 0),
                'limit' => (int) ($controls['max_consecutive_pre_generation_failures'] ?? 2),
                'message' => 'Paid AI source access stopped after repeated campaign failures before article generation.',
            ],
            [
                'code' => 'repeated_paid_query',
                'scope' => 'article',
                'actual' => (int) ($usage['same_query_calls'] ?? 0),
                'limit' => (int) ($controls['max_same_query_calls_per_article'] ?? 1),
                'message' => 'Paid AI source access stopped before repeating the same request for this article attempt.',
            ],
            [
                'code' => 'article_search_call_amplification',
                'scope' => 'article',
                'actual' => (int) ($usage['article_calls'] ?? 0),
                'limit' => (int) ($controls['max_paid_search_calls_per_article'] ?? 3),
                'message' => 'Paid AI source access stopped because this article attempt reached its API-call limit.',
            ],
            [
                'code' => 'article_web_search_amplification',
                'scope' => 'article',
                'actual' => (int) ($usage['article_web_searches'] ?? 0),
                'limit' => (int) ($controls['max_web_searches_per_article'] ?? 8),
                'message' => 'Paid AI source access stopped because this article attempt reached its billable web-search limit.',
            ],
            [
                'code' => 'article_search_cost_limit',
                'scope' => 'article',
                'actual' => (float) ($usage['article_projected_cost_usd'] ?? 0.0),
                'limit' => (float) ($controls['max_paid_search_cost_per_article_usd'] ?? 0.35),
                'message' => 'Paid AI source access stopped before this article attempt could exceed its dollar budget.',
            ],
            [
                'code' => 'campaign_search_cost_velocity',
                'scope' => 'campaign',
                'actual' => (float) ($usage['campaign_projected_cost_24h_usd'] ?? 0.0),
                'limit' => (float) ($controls['max_paid_search_cost_per_campaign_24h_usd'] ?? 1.0),
                'message' => 'Paid AI source access stopped because this campaign reached its rolling 24-hour budget.',
            ],
            [
                'code' => 'global_search_cost_velocity',
                'scope' => 'global',
                'actual' => (float) ($usage['global_projected_cost_24h_usd'] ?? 0.0),
                'limit' => (float) ($controls['max_paid_search_cost_global_24h_usd'] ?? 3.0),
                'message' => 'Paid AI source access stopped because the global rolling 24-hour budget was reached.',
            ],
        ];

        foreach ($checks as $check) {
            if ($check['actual'] < $check['limit']) {
                continue;
            }

            return [
                'allowed' => false,
                'reason_code' => $check['code'],
                'message' => $check['message'],
                'scope' => $check['scope'],
                'actual' => $check['actual'],
                'limit' => $check['limit'],
            ];
        }

        return $this->allowed();
    }

    /** @return array{allowed:bool,reason_code:null,message:string,scope:string,actual:null,limit:null} */
    private function allowed(): array
    {
        return [
            'allowed' => true,
            'reason_code' => null,
            'message' => 'Paid AI source access is within configured spend controls.',
            'scope' => 'none',
            'actual' => null,
            'limit' => null,
        ];
    }
}
