<?php

namespace hexa_package_article_campaigns\Discovery;

use Illuminate\Support\Str;

/** Deterministic category expansion and relevance checks; no I/O or AI calls. */
class HomepageCategorySearchPolicy
{
    private const CATEGORY_ALIASES = [
        'pharmaceutical' => 'pharma',
        'pharmaceuticals' => 'pharma',
    ];

    private const VOCABULARY = [
        // CAMPAIGN-BUG-014: the first five terms drive queries; the rest let core business news
        // (a Fed rate decision) satisfy the headline check instead of wasting a paid draft.
        'business' => ['business', 'companies', 'economy', 'earnings', 'acquisition', 'interest rates', 'inflation', 'federal reserve', 'markets', 'revenue', 'profit'],
        'business and industry' => ['business', 'companies', 'industry', 'earnings', 'acquisition'],
        'science and innovation' => ['science', 'scientific research', 'research breakthrough', 'innovation', 'applied research'],
        'infrastructure' => ['infrastructure', 'infrastructure investment', 'public works', 'critical infrastructure', 'digital infrastructure'],
        'quantum computing' => ['quantum computing', 'quantum computer', 'qubits', 'quantum processor', 'quantum technology'],
        'entrepreneur' => ['entrepreneur', 'entrepreneurs', 'startup', 'founder', 'venture capital'],
        'entrepreneurs' => ['entrepreneur', 'entrepreneurs', 'startup', 'founder', 'venture capital'],
        'entrepreneurship' => ['entrepreneurship', 'startup', 'founder', 'small business', 'venture capital'],
        'startup' => ['startup', 'startups', 'founder', 'founders', 'venture capital', 'funding round', 'early stage company', 'new venture'],
        'celebrity' => ['celebrity', 'actor', 'actress', 'singer', 'Hollywood'],
        'fashion' => ['fashion', 'designer', 'apparel', 'runway', 'fashion week'],
        'lifestyle' => ['lifestyle', 'wellness', 'food', 'home design', 'culture'],
        'luxury' => ['luxury', 'luxury brands', 'yachts', 'luxury hotels', 'luxury cars'],
        'politics' => ['politics', 'election', 'government', 'legislation', 'Congress'],
        'real estate' => ['real estate', 'housing', 'property market', 'mortgage', 'commercial property'],
        'travel' => ['travel', 'tourism', 'airlines', 'hotels', 'destinations'],
        'technology' => ['technology', 'software', 'artificial intelligence', 'semiconductor', 'cybersecurity'],
        'tech' => ['technology', 'software', 'artificial intelligence', 'semiconductor', 'cybersecurity'],
        'ai' => ['artificial intelligence', 'machine learning', 'AI models', 'AI chips', 'AI regulation'],
        'robotics' => ['robotics', 'robots', 'industrial automation', 'autonomous robots', 'robot engineering'],
        'automation' => ['automation', 'automated systems', 'robotic systems', 'workflow automation', 'autonomous systems'],
        'medical' => ['medical research', 'healthcare', 'clinical research', 'patient care', 'public health'],
        'biotech' => ['biotechnology', 'biotech research', 'biopharmaceutical', 'cell therapy', 'gene therapy'],
        'pharma' => [
            'pharmaceutical', 'pharmaceuticals', 'pharma', 'drug development',
            'clinical trial', 'clinical trials', 'drug approval', 'pharmaceutical research',
            'biopharmaceutical', 'therapeutic', 'therapy', 'cell therapy', 'gene therapy',
            'medicine', 'medicines',
        ],
        'podcasts' => ['podcast interview', 'podcast episode', 'podcast conversation', 'audio interview'],
        'innovation' => ['innovation', 'applied research', 'research breakthrough', 'new technology', 'scientific discovery'],
        'personal tech' => ['personal technology', 'consumer technology', 'smartphone', 'personal computing', 'consumer electronics'],
        'smart home' => ['smart home', 'home automation', 'connected home', 'smart appliances', 'home security technology'],
        'wearable devices' => ['wearable devices', 'wearable technology', 'smartwatch', 'smart glasses', 'fitness tracker'],
        'health tech' => ['health technology', 'medical technology', 'digital health', 'medical devices', 'healthcare software'],
        'cybersecurity' => ['cybersecurity', 'cyber security', 'data breach', 'ransomware', 'network security'],
        'data privacy' => ['data privacy', 'data protection', 'privacy regulation', 'personal data', 'digital privacy'],
        'innovations' => ['innovation', 'emerging technology', 'applied research', 'new technology', 'research breakthrough'],
        'finance' => ['finance', 'markets', 'banking', 'investing', 'interest rates'],
        'consumer' => ['consumer products', 'retail', 'consumer spending', 'shopping', 'consumer technology'],
        'clean energy' => ['clean energy', 'renewable energy', 'solar power', 'wind power', 'energy storage'],
        'gadgets and apps' => ['gadgets', 'mobile apps', 'smartphone', 'consumer electronics', 'software'],
        'e-commerce' => ['ecommerce', 'online retail', 'online shopping', 'retail technology', 'digital commerce'],
        'leadership' => ['business leadership', 'management', 'executive', 'workplace', 'company strategy'],
        'reputation management' => ['reputation management', 'corporate reputation', 'public relations', 'brand reputation', 'crisis communications'],
        'legal' => ['law', 'regulation', 'lawsuit', 'legal services', 'court ruling'],
        'personal development' => ['career development', 'professional skills', 'learning', 'mentoring', 'productivity'],
        'diversity' => ['workplace diversity', 'inclusion', 'equal opportunity', 'accessibility', 'representation'],
        'women entrepreneurs' => ['women entrepreneurs', 'women founders', 'female founders', 'women owned business', 'women startup leaders'],
        'personal finance' => ['personal finance', 'saving', 'budgeting', 'retirement planning', 'consumer credit'],
        'podcasts and shows' => ['podcast', 'audio series', 'interview', 'business show', 'digital media'],
        'startup show podcast' => ['startup podcast', 'founder interview', 'entrepreneur interview', 'business podcast', 'startup show'],
        'banking and lending' => ['banking', 'lending', 'banks', 'credit', 'loans'],
        'wealth management and investing' => ['wealth management', 'investing', 'investment', 'asset management', 'portfolio management'],
        'digital payments' => ['digital payments', 'payment technology', 'mobile payments', 'payment processing', 'digital wallets'],
        'emerging tech' => ['emerging technology', 'artificial intelligence', 'software', 'automation', 'cybersecurity'],
        'fintech' => ['fintech', 'financial technology', 'digital banking', 'payment technology', 'digital finance'],
        'sports' => ['sports', 'football', 'basketball', 'tennis', 'athletics'],
        'music' => ['music', 'musicians', 'concert', 'album', 'recording industry'],
        'entertainment' => ['entertainment', 'film', 'television', 'streaming', 'box office'],
        'health' => ['health', 'medicine', 'clinical trial', 'public health', 'medical research'],
        'mental health' => ['mental health', 'psychiatry', 'psychological care', 'behavioral health', 'mental illness'],
        'aging' => ['healthy aging', 'aging research', 'biological age', 'age related disease', 'geroscience'],
        'longevity basics' => ['longevity', 'healthy aging', 'healthspan', 'aging research', 'geroscience'],
        'wellness' => ['wellness', 'preventive health', 'sleep health', 'nutrition', 'physical activity'],
        'cryptocurrency' => ['cryptocurrency', 'bitcoin', 'ethereum', 'crypto regulation', 'digital assets'],
        'crypto' => ['cryptocurrency', 'bitcoin', 'ethereum', 'crypto regulation', 'digital assets'],
        'bitcoin' => ['bitcoin', 'BTC', 'bitcoin mining', 'bitcoin ETF', 'bitcoin network'],
        'polygon' => ['Polygon blockchain', 'Polygon network', 'Polygon crypto', 'Polygon ecosystem', 'POL token'],
        'nft' => ['NFT', 'non fungible tokens', 'digital collectibles', 'NFT marketplace', 'tokenized art'],
        'blockchain' => ['blockchain', 'tokenization', 'decentralized finance', 'Web3', 'digital assets'],
        'public relations' => ['public relations', 'press release distribution', 'corporate communications', 'media relations', 'PR agencies'],
        'vehicle tech' => ['electric vehicle', 'autonomous vehicle', 'automotive', 'vehicle technology', 'vehicle safety'],
        'smart infrastructure' => ['smart infrastructure', 'smart cities', 'smart building', 'intelligent transport', 'building automation'],
        'travel tech' => ['travel technology', 'travel booking', 'airline', 'airport', 'tourism technology'],
        'green transit' => ['public transit', 'electric bus', 'sustainable transport', 'rail electrification', 'zero emission vehicle'],
        'transportation news' => ['transportation', 'transit', 'railway', 'airline', 'freight'],
    ];

    public function isKnownCategoryName(string $name): bool
    {
        return isset(self::VOCABULARY[$name]);
    }

    public function publicationFocus(string $identity): ?array
    {
        // Use the publication's own identity, not a passing story or a person's
        // name. Broad category names must not erase an explicit audience remit.
        $text = Str::lower(Str::ascii($identity));
        if (preg_match('/\b(?:medical tech(?:nology)?|healthcare technology|digital health|biotech(?:nology)?|pharmaceuticals?|life sciences)\b/', $text)
            && ! preg_match('/\b(?:general news|politics|sports|fashion|entertainment news)\b/', $text)) {
            return [
                'label' => 'medical technology, healthcare, biotechnology and pharmaceutical research',
                'query_prefix' => '(medical OR healthcare OR clinical OR biotech OR pharmaceutical)',
                'terms' => ['medical', 'medicine', 'healthcare', 'health care', 'health', 'clinical', 'patient', 'patients', 'hospital', 'hospitals', 'diagnostic', 'diagnostics', 'surgical', 'surgery', 'biotechnology', 'biotech', 'pharmaceutical', 'pharmaceuticals', 'pharma', 'drug', 'drugs', 'therapeutic', 'therapy'],
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match('/\bquantum computing\b/', $text)
            && preg_match('/\b(?:artificial intelligence|blockchain)\b/', $text)
            && ! preg_match('/\b(?:general news|politics|sports|fashion)\b/', $text)) {
            return [
                'label' => 'quantum computing, artificial intelligence, blockchain and computing infrastructure',
                'query_prefix' => '(quantum OR "artificial intelligence" OR blockchain OR computing)',
                'terms' => ['quantum', 'qubit', 'qubits', 'artificial intelligence', 'ai', 'machine learning', 'blockchain', 'computing', 'computer', 'computers', 'semiconductor', 'semiconductors', 'chip', 'chips', 'data center', 'data centers', 'cybersecurity', 'cyber security', 'cloud infrastructure'],
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match('/\b(?:transportation technology|transport technology|future of transport|future of mobility|mobility technology|autonomous vehicles|public transit)\b/', $text)
            && !preg_match('/\b(?:general news|politics|sports)\b/', $text)) {
            return [
                'label' => 'transportation technology, mobility, travel and smart infrastructure',
                'query_prefix' => '(transport OR mobility OR travel OR infrastructure)',
                'terms' => ['transport', 'transportation', 'transit', 'mobility', 'vehicle', 'vehicles', 'automotive', 'rail', 'railway', 'railways', 'bus', 'buses', 'aviation', 'airline', 'airlines', 'airport', 'airports', 'aircraft', 'logistics', 'freight', 'shipping', 'travel', 'tourism', 'infrastructure', 'smart building', 'smart buildings', 'building automation'],
                'homepage_evidence' => $identity,
            ];
        }
        // CRITICAL — see laravel-hexa-app-publish BUGLOG.md CAMPAIGN-BUG-006. A celebrity
        // wealth remit must be the article's subject: match the headline surface only,
        // because one incidental body word ("luxury", "real estate") let local news through.
        if (preg_match("/\\bcelebrit(?:y|ies)(?:'s)?[\\s-]+(?:wealth|finance|finances|fortunes?|net worth|money)\\b/", $text)
            && ! preg_match('/\b(?:general news|breaking news)\b/', $text)) {
            return [
                'label' => 'celebrity wealth, luxury and money',
                'query_prefix' => '(celebrity OR billionaire OR "net worth" OR luxury OR wealth)',
                'terms' => ['celebrity', 'celebrities', 'net worth', 'fortune', 'fortunes', 'wealth', 'wealthy', 'rich', 'richest', 'billionaire', 'billionaires', 'millionaire', 'millionaires', 'luxury', 'luxurious', 'mansion', 'mansions', 'yacht', 'yachts', 'private jet', 'hollywood', 'brand deal', 'brand deals', 'endorsement', 'endorsements', 'royal', 'royals'],
                'surface' => 'headline',
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match('/\b(?:high[ -]net[ -]worth|affluent (?:readers|audience|lifestyle)|luxury lifestyle)\b/', $text)
            && ! preg_match('/\b(?:general news|politics|sports|breaking news)\b/', $text)) {
            return [
                'label' => 'wealth, luxury lifestyles, entrepreneurship and business finance',
                'query_prefix' => '(wealth OR luxury OR business OR finance)',
                'terms' => ['wealth', 'wealthy', 'affluent', 'high net worth', 'family office', 'family offices', 'luxury', 'luxurious', 'millionaire', 'billionaire', 'entrepreneur', 'entrepreneurs', 'founder', 'venture capital', 'business', 'finance', 'financial', 'investing', 'investment', 'banking', 'real estate', 'executive'],
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match("/\\b(?:women|female)(?:'s)?[\\s-]+(?:leaders?|leadership|founders?|entrepreneurs?|executives?|business|health|sports|finance|technology)\\b/", $text)
            && ! preg_match('/\\b(?:men|male)\\b/', $text)) {
            return [
                'label' => 'women-focused coverage',
                'query_prefix' => 'women',
                'terms' => ['women', 'woman', 'female'],
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match('/\b(?:startup news|news (?:hub|source|outlet).{0,60}\bstartups?)\b/', $text)) {
            return [
                'label' => 'startups, entrepreneurship, business and innovation',
                'query_prefix' => '(startup OR entrepreneur OR business OR innovation)',
                'terms' => ['startup', 'startups', 'entrepreneur', 'entrepreneurs', 'entrepreneurship', 'business', 'businesses', 'company', 'companies', 'founder', 'founders', 'venture capital', 'innovation'],
                'homepage_evidence' => $identity,
            ];
        }
        // CRITICAL — see laravel-hexa-app-publish BUGLOG.md CAMPAIGN-BUG-006. An
        // entrepreneurship identity without a focus published geopolitics on "economic"
        // wording; the remit must appear in the headline or description.
        if (preg_match('/\bentrepreneur(?:ial|ship|s)?\b/', $text)
            && ! preg_match('/\b(?:general news|politics|sports|entertainment news|celebrity)\b/', $text)) {
            return [
                'label' => 'entrepreneurship, small business, founders and business strategy',
                'query_prefix' => '(entrepreneur OR startup OR founder OR "small business")',
                'terms' => ['entrepreneur', 'entrepreneurs', 'entrepreneurial', 'entrepreneurship', 'startup', 'startups', 'founder', 'founders', 'small business', 'small businesses', 'business owner', 'business owners', 'ceo', 'company', 'companies', 'brand', 'brands', 'business strategy', 'workplace', 'employees', 'funding', 'venture capital', 'franchise', 'bankruptcy', 'acquisition', 'merger'],
                'surface' => 'headline',
                'homepage_evidence' => $identity,
            ];
        }
        // Multiple explicit identity signals establish a specialist remit;
        // article-feed mentions and general-news identities do not.
        preg_match_all('/\b(?:cryptocurrency|crypto|blockchain|web3|digital assets|nfts)\b/', $text, $digitalSignals);
        if (count(array_unique($digitalSignals[0])) >= 2
            && ! preg_match('/\b(?:politics|sports|entertainment|fashion|travel|health|lifestyle|business news|technology news)\b/', $text)) {
            return [
                'label' => 'cryptocurrency, blockchain, Web3, digital assets and financial technology',
                'query_prefix' => '(cryptocurrency OR blockchain OR Web3 OR fintech)',
                'terms' => ['cryptocurrency', 'crypto', 'bitcoin', 'ethereum', 'blockchain', 'web3', 'digital assets', 'digital asset', 'tokenization', 'decentralized finance', 'nft', 'nfts', 'fintech', 'financial technology'],
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match('/\b(?:fintech|financial tech(?:nology)?|technology of finance)\b/', $text)
            && ! preg_match('/\b(?:politics|sports|entertainment|fashion|travel|health|lifestyle|general news)\b/', $text)) {
            return [
                'label' => 'financial technology, banking, payments and investing',
                'query_prefix' => '(fintech OR banking OR payments OR investing)',
                'terms' => ['fintech', 'financial technology', 'financial', 'finance', 'bank', 'banks', 'banking', 'lending', 'payment', 'payments', 'investing', 'investment', 'wealth', 'digital assets', 'blockchain'],
                'homepage_evidence' => $identity,
            ];
        }
        if (preg_match('/\b(?:smart technolog(?:y|ies)|technology (?:news|publication|journalism)|covering (?:technology|tech))\b/', $text)
            && !preg_match('/\b(?:general news|politics|sports|fashion|entertainment news)\b/', $text)) {
            return [
                'label' => 'smart technology, AI, connected devices, software and digital security',
                'query_prefix' => '(technology OR software OR digital OR AI)',
                'terms' => ['technology', 'technologies', 'tech', 'software', 'digital', 'artificial intelligence', 'ai', 'machine learning', 'robot', 'robots', 'robotics', 'semiconductor', 'semiconductors', 'cybersecurity', 'cyber security', 'data privacy', 'smart home', 'smartwatch', 'wearable', 'wearables', 'smartphone', 'computer', 'computing'],
                'homepage_evidence' => $identity,
            ];
        }
        return null;
    }

    public function matchesPublicationFocus(array $source, array $definition): bool
    {
        $terms = (array) data_get($definition, 'publication_focus.terms', []);
        if ($terms === []) {
            return true;
        }
        // Require explicit evidence in editorial metadata or the opening.
        // Names and a mention buried in a biography do not establish gender.
        // A focus marked `headline` must appear in the title or description, not the body.
        $headlineOnly = data_get($definition, 'publication_focus.surface') === 'headline';
        $surface = Str::lower(Str::ascii(strip_tags(implode(' ', [
            (string) ($source['title'] ?? ''),
            (string) ($source['description'] ?? $source['excerpt'] ?? $source['snippet'] ?? ''),
            $headlineOnly ? '' : mb_substr((string) ($source['text'] ?? $source['content'] ?? ''), 0, 1600),
        ]))));
        foreach ($terms as $term) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote(Str::lower(Str::ascii((string) $term)), '/').'(?![a-z0-9])/i', $surface)) {
                return true;
            }
        }
        return false;
    }

    public function terms(string $category): array
    {
        $key = Str::lower(trim($category));
        $key = preg_replace('/\s*&\s*/u', ' and ', $key) ?? $key;
        $key = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $key) ?? $key);
        foreach (array_values(array_unique([$key, Str::singular($key)])) as $candidate) {
            $candidate = self::CATEGORY_ALIASES[$candidate] ?? $candidate;
            if (isset(self::VOCABULARY[$candidate])) {
                return self::VOCABULARY[$candidate];
            }
        }
        $key = str_replace('-', ' ', $key);
        if (isset(self::VOCABULARY[$key])) {
            return self::VOCABULARY[$key];
        }
        foreach (self::VOCABULARY as $terms) {
            if (Str::lower($terms[0]) === $key) {
                return $terms;
            }
        }
        // Unknown compound labels can reuse known subjects without a site-specific alias.
        $expanded = [];
        foreach (self::VOCABULARY as $subject => $terms) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($subject, '/').'(?![a-z0-9])/i', $key)) {
                $expanded = array_merge($expanded, $terms);
            }
        }
        if ($expanded !== []) {
            return array_values(array_unique($expanded));
        }
        return [$category];
    }

    public function matches(array $source, array $terms): bool
    {
        $body = trim((string) ($source['text'] ?? $source['content'] ?? ''));
        if ($body !== '') {
            $body = Str::lower(Str::ascii(strip_tags($body)));
            $title = Str::lower(Str::ascii((string) ($source['title'] ?? '')));
            $mentions = 0;
            $titleMatch = false;
            foreach (array_unique($terms) as $term) {
                $term = Str::lower(Str::ascii((string) $term));
                if ($term === '') {
                    continue;
                }
                $pattern = '/(?<![a-z0-9])'.preg_quote($term, '/').'(?:s|es)?(?![a-z0-9])/i';
                $mentions += preg_match_all($pattern, $body);
                $titleMatch = $titleMatch || preg_match($pattern, $title) === 1;
            }
            // A passing reference in an unrelated article is not its subject.
            // Discovery metadata remains broad; full text must substantiate it.
            return $mentions >= ($titleMatch ? 2 : 3);
        }
        $text = Str::lower(Str::ascii(strip_tags(implode(' ', array_map(
            static fn (string $key): string => (string) ($source[$key] ?? ''),
            ['title', 'description', 'snippet', 'text', 'content']
        )))));
        foreach ($terms as $term) {
            $term = Str::lower(Str::ascii((string) $term));
            if ($term !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?:s|es)?(?![a-z0-9])/i', $text)) {
                return true;
            }
        }
        return false;
    }

    public function generic(string $name): bool
    {
        return in_array(Str::lower($name), ['news', 'breaking news', 'trending', 'features', 'industry updates', 'industry trends', 'industry commentary', 'resources', 'guides', 'tutorials', 'how to', 'expert roundups', 'press release', 'press releases', 'international', 'viral news', 'knowledge base', 'from the ground up'], true);
    }
}
