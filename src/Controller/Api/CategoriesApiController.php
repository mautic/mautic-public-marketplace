<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CategoriesApiController extends AbstractController
{
    private const CATEGORY_CODES = [
        'mautic-plugin',
        'mautic-theme',
        'mautic-resource',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function list(): JsonResponse
    {
        $categories = [];
        foreach (self::CATEGORY_CODES as $code) {
            $categories[] = [
                'code' => $code,
                'label' => $this->translator->trans('mautic.marketplace.api.category.'.$code),
            ];
        }

        return $this->json(['categories' => $categories]);
    }
}
