<?php

/**
 * First-party publication identity profiles. These patterns describe niches;
 * they never identify a site, campaign, account or persisted campaign title.
 */
return [
    [
        'match' => '/\b(?:law|legal)[\s-]+(?:news|publication|journal|journalism|report|reporting|media)\b/',
        'exclude' => '/\b(?:general news|sports|fashion|entertainment news)\b/',
        'label' => 'law, courts, regulation, litigation and legal practice',
        'query_prefix' => '(law OR legal OR regulation OR court OR lawsuit)',
        'terms' => ['law', 'legal', 'court', 'courts', 'lawsuit', 'lawsuits', 'litigation', 'regulation', 'regulatory', 'legislation', 'attorney', 'attorneys', 'lawyer', 'lawyers', 'judge', 'judges', 'ruling', 'rulings', 'compliance', 'antitrust', 'corporate law', 'commercial law', 'business law', 'legal practice'],
        'surface' => 'headline',
    ],
    [
        'match' => '/\b(?:medical tech(?:nology)?|healthcare technology|digital health|biotech(?:nology)?|pharmaceuticals?|life sciences)\b/',
        'exclude' => '/\b(?:general news|politics|sports|fashion|entertainment news)\b/',
        'label' => 'medical technology, healthcare, biotechnology and pharmaceutical research',
        'query_prefix' => '(medical OR healthcare OR clinical OR biotech OR pharmaceutical)',
        'terms' => ['medical', 'medicine', 'healthcare', 'health care', 'health', 'clinical', 'patient', 'patients', 'hospital', 'hospitals', 'diagnostic', 'diagnostics', 'surgical', 'surgery', 'biotechnology', 'biotech', 'pharmaceutical', 'pharmaceuticals', 'pharma', 'drug', 'drugs', 'therapeutic', 'therapy'],
    ],
    [
        'match' => '/\bquantum computing\b/',
        'require' => '/\b(?:artificial intelligence|blockchain)\b/',
        'exclude' => '/\b(?:general news|politics|sports|fashion)\b/',
        'label' => 'quantum computing, artificial intelligence, blockchain and computing infrastructure',
        'query_prefix' => '(quantum OR "artificial intelligence" OR blockchain OR computing)',
        'terms' => ['quantum', 'qubit', 'qubits', 'artificial intelligence', 'ai', 'machine learning', 'blockchain', 'computing', 'computer', 'computers', 'semiconductor', 'semiconductors', 'chip', 'chips', 'data center', 'data centers', 'cybersecurity', 'cyber security', 'cloud infrastructure'],
    ],
    [
        'match' => '/\b(?:transportation technology|transport technology|future of transport|future of mobility|mobility technology|autonomous vehicles|public transit)\b/',
        'exclude' => '/\b(?:general news|politics|sports)\b/',
        'label' => 'transportation technology, mobility, travel and smart infrastructure',
        'query_prefix' => '(transport OR mobility OR travel OR infrastructure)',
        'terms' => ['transport', 'transportation', 'transit', 'mobility', 'vehicle', 'vehicles', 'automotive', 'rail', 'railway', 'railways', 'bus', 'buses', 'aviation', 'airline', 'airlines', 'airport', 'airports', 'aircraft', 'logistics', 'freight', 'shipping', 'travel', 'tourism', 'infrastructure', 'smart building', 'smart buildings', 'building automation'],
    ],
    [
        'match' => "/\\bcelebrit(?:y|ies)(?:'s)?[\\s-]+(?:wealth|finance|finances|fortunes?|net worth|money)\\b/",
        'exclude' => '/\b(?:general news|breaking news)\b/',
        'label' => 'celebrity wealth, luxury and money',
        'query_prefix' => '(celebrity OR billionaire OR "net worth" OR luxury OR wealth)',
        'terms' => ['celebrity', 'celebrities', 'net worth', 'fortune', 'fortunes', 'wealth', 'wealthy', 'rich', 'richest', 'billionaire', 'billionaires', 'millionaire', 'millionaires', 'luxury', 'luxurious', 'mansion', 'mansions', 'yacht', 'yachts', 'private jet', 'hollywood', 'brand deal', 'brand deals', 'endorsement', 'endorsements', 'royal', 'royals'],
        'surface' => 'headline',
    ],
    [
        'match' => '/\b(?:high[ -]net[ -]worth|affluent (?:readers|audience|lifestyle)|luxury lifestyle)\b/',
        'exclude' => '/\b(?:general news|politics|sports|breaking news)\b/',
        'label' => 'wealth, luxury lifestyles, entrepreneurship and business finance',
        'query_prefix' => '(wealth OR luxury OR business OR finance)',
        'terms' => ['wealth', 'wealthy', 'affluent', 'high net worth', 'family office', 'family offices', 'luxury', 'luxurious', 'millionaire', 'billionaire', 'entrepreneur', 'entrepreneurs', 'founder', 'venture capital', 'business', 'finance', 'financial', 'investing', 'investment', 'banking', 'real estate', 'executive'],
    ],
    [
        'match' => "/\\b(?:women|female)(?:'s)?[\\s-]+(?:leaders?|leadership|founders?|entrepreneurs?|executives?|business|health|sports|finance|technology)\\b/",
        'exclude' => '/\b(?:men|male)\b/',
        'label' => 'women-focused coverage',
        'query_prefix' => 'women',
        'terms' => ['women', 'woman', 'female'],
    ],
    [
        'match' => '/\b(?:startup news|news (?:hub|source|outlet).{0,60}\bstartups?)\b/',
        'label' => 'startups, entrepreneurship, business and innovation',
        'query_prefix' => '(startup OR entrepreneur OR business OR innovation)',
        'terms' => ['startup', 'startups', 'entrepreneur', 'entrepreneurs', 'entrepreneurship', 'business', 'businesses', 'company', 'companies', 'founder', 'founders', 'venture capital', 'innovation'],
    ],
    [
        'match' => '/\bentrepreneur(?:ial|ship|s)?\b/',
        'exclude' => '/\b(?:general news|politics|sports|entertainment news|celebrity)\b/',
        'label' => 'entrepreneurship, small business, founders and business strategy',
        'query_prefix' => '(entrepreneur OR startup OR founder OR "small business")',
        'terms' => ['entrepreneur', 'entrepreneurs', 'entrepreneurial', 'entrepreneurship', 'startup', 'startups', 'founder', 'founders', 'small business', 'small businesses', 'business owner', 'business owners', 'ceo', 'company', 'companies', 'brand', 'brands', 'business strategy', 'workplace', 'employees', 'funding', 'venture capital', 'franchise', 'bankruptcy', 'acquisition', 'merger'],
        'surface' => 'headline',
    ],
    [
        'signals' => ['cryptocurrency', 'crypto', 'blockchain', 'web3', 'digital assets', 'nfts'],
        'minimum_signals' => 2,
        'exclude' => '/\b(?:politics|sports|entertainment|fashion|travel|health|lifestyle|business news|technology news)\b/',
        'label' => 'cryptocurrency, blockchain, Web3, digital assets and financial technology',
        'query_prefix' => '(cryptocurrency OR blockchain OR Web3 OR fintech)',
        'terms' => ['cryptocurrency', 'crypto', 'bitcoin', 'ethereum', 'blockchain', 'web3', 'digital assets', 'digital asset', 'tokenization', 'decentralized finance', 'nft', 'nfts', 'fintech', 'financial technology'],
    ],
    [
        'match' => '/\b(?:fintech|financial tech(?:nology)?|technology of finance)\b/',
        'exclude' => '/\b(?:politics|sports|entertainment|fashion|travel|health|lifestyle|general news)\b/',
        'label' => 'financial technology, banking, payments and investing',
        'query_prefix' => '(fintech OR banking OR payments OR investing)',
        'terms' => ['fintech', 'financial technology', 'financial', 'finance', 'bank', 'banks', 'banking', 'lending', 'payment', 'payments', 'investing', 'investment', 'wealth', 'digital assets', 'blockchain'],
    ],
    [
        'match' => '/\b(?:smart technolog(?:y|ies)|technology (?:news|publication|journalism)|covering (?:technology|tech))\b/',
        'exclude' => '/\b(?:general news|politics|sports|fashion|entertainment news)\b/',
        'label' => 'smart technology, AI, connected devices, software and digital security',
        'query_prefix' => '(technology OR software OR digital OR AI)',
        'terms' => ['technology', 'technologies', 'tech', 'software', 'digital', 'artificial intelligence', 'ai', 'machine learning', 'robot', 'robots', 'robotics', 'semiconductor', 'semiconductors', 'cybersecurity', 'cyber security', 'data privacy', 'smart home', 'smartwatch', 'wearable', 'wearables', 'smartphone', 'computer', 'computing'],
    ],
];
