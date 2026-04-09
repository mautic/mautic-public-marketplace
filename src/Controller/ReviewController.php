<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth0\Auth0User;
use App\Marketplace\Dto\ReviewRequest;
use App\Marketplace\Exception\ReviewValidationException;
use App\Marketplace\MarketplaceApiClient;
use App\Marketplace\PackageDetailPageContextBuilder;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ReviewController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly PackageDetailPageContextBuilder $detailPageContextBuilder,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function submit(Request $request, string $package): Response
    {
        if (!$this->isCsrfTokenValid('review_'.$package, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var Auth0User $user */
        $user = $this->getUser();

        $reviewForm = [
            'rating' => $request->request->getString('rating', '0'),
            'review' => $request->request->getString('review'),
        ];

        try {
            $reviewRequest = ReviewRequest::fromPayload($request->request);
        } catch (ReviewValidationException $exception) {
            return $this->renderReviewErrorPage($package, $reviewForm, [
                'form' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->apiClient->submitReview(
                $package,
                $user->getUserIdentifier(),
                $user->getName() ?? $user->getEmail() ?? 'Anonymous',
                $user->getPicture(),
                $reviewRequest,
            );
        } catch (SupabaseApiException) {
            return $this->renderReviewErrorPage($package, $reviewForm, [
                'form' => 'Failed to submit review. Please try again.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $this->addFlash('success', 'Review submitted successfully.');

        return $this->redirectToRoute('marketplace_detail', [
            'package' => $package,
        ]);
    }

    /**
     * @param array{rating:string,review:string} $reviewForm
     * @param array<string,string>               $reviewErrors
     */
    private function renderReviewErrorPage(string $package, array $reviewForm, array $reviewErrors, int $statusCode): Response
    {
        $page = $this->detailPageContextBuilder->build($package, $reviewForm, $reviewErrors);

        return $this->render('marketplace/detail.html.twig', $page['context'], new Response(
            '',
            Response::HTTP_OK === $page['status_code'] ? $statusCode : $page['status_code'],
        ));
    }
}
