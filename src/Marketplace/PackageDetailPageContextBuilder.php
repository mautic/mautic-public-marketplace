<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Dto\PackageDetail;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Component\HttpFoundation\Response;

final class PackageDetailPageContextBuilder
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    /**
     * @param array{rating:string,review:string} $reviewForm
     * @param array<string,string> $reviewErrors
     *
     * @return array{context: array<string, mixed>, status_code: int}
     */
    public function build(string $packageName, array $reviewForm = ['rating' => '0', 'review' => ''], array $reviewErrors = []): array
    {
        try {
            $detail = $this->apiClient->getPackage($packageName);
        } catch (SupabaseApiException $exception) {
            return [
                'context' => [
                    'error' => $exception->getMessage(),
                    'package' => null,
                    'name' => $packageName,
                    'review_form' => $reviewForm,
                    'review_errors' => $reviewErrors,
                ],
                'status_code' => Response::HTTP_BAD_GATEWAY,
            ];
        }

        if (!$detail instanceof PackageDetail) {
            return [
                'context' => [
                    'error' => 'Package not found.',
                    'package' => null,
                    'name' => $packageName,
                    'review_form' => $reviewForm,
                    'review_errors' => $reviewErrors,
                ],
                'status_code' => Response::HTTP_NOT_FOUND,
            ];
        }

        return [
            'context' => [
                'error' => null,
                'package' => $detail,
                'name' => $packageName,
                'review_form' => $reviewForm,
                'review_errors' => $reviewErrors,
            ],
            'status_code' => Response::HTTP_OK,
        ];
    }
}
