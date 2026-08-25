<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Size caps in the "mautic_share" group also run against share-flow uploads,
 * where all metadata comes from the ZIP's composer.json instead of this form.
 */
final class SubmitRequest
{
    public const SHARE_LIMITS_GROUP = 'mautic_share';

    /**
     * @param list<string> $keywords
     * @param list<string> $languages
     * @param list<string> $works_with
     * @param list<string> $gallery_alt
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Package name is required.')]
        #[Assert\Regex(
            pattern: '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#',
            message: 'Name must be a valid package name (vendor/package).',
        )]
        #[Assert\Length(max: 255, maxMessage: 'Package name must be at most {{ limit }} characters.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Version is required.')]
        #[Assert\Length(max: 64, maxMessage: 'Version must be at most {{ limit }} characters.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly string $version = '',

        #[Assert\NotBlank(message: 'Category is required.')]
        #[Assert\Choice(choices: ['mautic-plugin', 'mautic-theme', 'mautic-resource'], message: 'Invalid category.')]
        public readonly string $category = '',

        #[Assert\NotBlank(message: 'Headline is required.')]
        #[Assert\Length(max: 60, maxMessage: 'Headline must be at most {{ limit }} characters.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly string $headline = '',

        #[Assert\Count(max: 20, maxMessage: 'At most {{ limit }} keywords are allowed.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        #[Assert\All([new Assert\Length(max: 50, maxMessage: 'Each keyword must be at most {{ limit }} characters.')], groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly array $keywords = [],

        #[Assert\NotBlank(message: 'Description is required.')]
        #[Assert\Length(min: 50, minMessage: 'Description must be at least {{ limit }} characters.')]
        #[Assert\Length(max: 5000, maxMessage: 'Description must be at most {{ limit }} characters.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly string $description = '',

        #[Assert\Count(max: 50, maxMessage: 'At most {{ limit }} languages are allowed.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        #[Assert\All([new Assert\Length(max: 20, maxMessage: 'Each language must be at most {{ limit }} characters.')], groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly array $languages = [],

        #[Assert\NotBlank(message: 'At least one Mautic version must be selected.')]
        #[Assert\Count(max: 20, maxMessage: 'At most {{ limit }} Mautic versions are allowed.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        #[Assert\All([new Assert\Length(max: 20, maxMessage: 'Each Mautic version must be at most {{ limit }} characters.')], groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly array $works_with = [],

        #[Assert\NotBlank(message: 'License type is required.')]
        #[Assert\Length(max: 64, maxMessage: 'License type must be at most {{ limit }} characters.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly string $license_type = '',

        #[Assert\Choice(choices: ['free', 'paid'], message: 'Invalid pricing model.')]
        public readonly string $pricing_model = 'free',

        #[Assert\PositiveOrZero(message: 'Price must be zero or greater.', groups: ['Default', self::SHARE_LIMITS_GROUP])]
        public readonly float $price = 0.0,

        #[Assert\Length(max: 3, maxMessage: 'Currency must be a 3-letter ISO code.')]
        public readonly ?string $currency = null,

        #[Assert\IsTrue(message: 'You must accept the ownership and Terms and Conditions.')]
        public readonly bool $ip_ownership_accepted = false,

        #[Assert\Url(message: 'GitHub URL must be a valid URL.', requireTld: true)]
        #[Assert\Regex(pattern: '#^https://github\.com/#', message: 'GitHub URL must point to github.com.')]
        public readonly ?string $github_url = null,

        #[Assert\Url(message: 'Packagist URL must be a valid URL.', requireTld: true)]
        #[Assert\Regex(pattern: '#^https://packagist\.org/#', message: 'Packagist URL must point to packagist.org.')]
        public readonly ?string $packagist_url = null,

        #[Assert\Url(message: 'Documentation URL must be a valid URL.', requireTld: true)]
        #[Assert\Length(max: 255, maxMessage: 'Documentation URL must be at most {{ limit }} characters.')]
        public readonly ?string $documentation = null,

        #[Assert\Count(max: 10, maxMessage: 'At most {{ limit }} gallery captions are allowed.')]
        #[Assert\All([new Assert\Length(max: 255, maxMessage: 'Each gallery caption must be at most {{ limit }} characters.')])]
        public readonly array $gallery_alt = [],
    ) {
    }

    /**
     * A paid package must carry a real price and currency; free packages leave both unset.
     */
    #[Assert\Callback]
    public function validatePaidPricing(ExecutionContextInterface $context): void
    {
        if ('paid' !== $this->pricing_model) {
            return;
        }

        if ($this->price <= 0) {
            $context->buildViolation('A paid package must have a price greater than zero.')
                ->atPath('price')
                ->addViolation();
        }

        if (null === $this->currency || '' === trim($this->currency)) {
            $context->buildViolation('A paid package must have a currency.')
                ->atPath('currency')
                ->addViolation();
        }
    }
}
