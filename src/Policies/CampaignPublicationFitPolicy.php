<?php

namespace hexa_package_article_campaigns\Policies;

/** Separates manifest-backed editorial fit from literal headline vocabulary. */
final class CampaignPublicationFitPolicy
{
    /**
     * @param  array<string, bool>  $evidence
     */
    public function requiresLiteralHeadlineIntent(array $evidence): bool
    {
        if (! ($evidence['homepage_pool'] ?? false)) {
            return true;
        }

        if (($evidence['generic_lane'] ?? false) || ($evidence['dominant_category_match'] ?? false)) {
            return false;
        }

        $manifestBackedFit = ($evidence['category_match'] ?? false)
            && ($evidence['source_policy_match'] ?? false)
            && ($evidence['article_body_match'] ?? false)
            && ($evidence['publication_focus_match'] ?? false);

        return ! $manifestBackedFit;
    }
}
