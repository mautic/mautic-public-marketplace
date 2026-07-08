<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SubmitRequest
{
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
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Version is required.')]
        public readonly string $version = '',

        #[Assert\NotBlank(message: 'Category is required.')]
        #[Assert\Choice(choices: ['mautic-plugin', 'mautic-theme', 'mautic-resource'], message: 'Invalid category.')]
        public readonly string $category = '',

        #[Assert\NotBlank(message: 'Headline is required.')]
        #[Assert\Length(max: 60, maxMessage: 'Headline must be at most {{ limit }} characters.')]
        public readonly string $headline = '',

        public readonly array $keywords = [],

        #[Assert\NotBlank(message: 'Description is required.')]
        #[Assert\Length(min: 50, minMessage: 'Description must be at least {{ limit }} characters.')]
        public readonly string $description = '',

        public readonly array $languages = [],

        #[Assert\NotBlank(message: 'At least one Mautic version must be selected.')]
        public readonly array $works_with = [],

        #[Assert\NotBlank(message: 'License type is required.')]
        public readonly string $license_type = '',

        #[Assert\Choice(choices: ['free', 'paid'], message: 'Invalid pricing model.')]
        public readonly string $pricing_model = 'free',

        #[Assert\PositiveOrZero(message: 'Price must be zero or greater.')]
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

        public readonly ?string $documentation = null,

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
