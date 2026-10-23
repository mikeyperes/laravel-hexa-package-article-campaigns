<?php

namespace hexa_package_article_campaigns\Data;

/** One validated classification of one source against one manifest. */
final readonly class SourceClassification
{
    public function __construct(
        /** Exact manifest category name, or null when no category fits. */
        public ?string $category,
        public bool $fitsPublication,
        public string $subject,
        public string $reason,
        public string $model = '',
        /** One SourceClassificationPrompt::REJECT_CODES value when it does not fit, otherwise ''. */
        public string $rejectCode = '',
    ) {}

    public function accepts(string $category): bool
    {
        return $this->fitsPublication
            && $this->category !== null
            && strcasecmp($this->category, trim($category)) === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'fits_publication' => $this->fitsPublication,
            'subject' => $this->subject,
            'reason' => $this->reason,
            'model' => $this->model,
            'reject_code' => $this->rejectCode,
        ];
    }
}
