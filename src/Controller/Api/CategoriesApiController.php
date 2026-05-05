<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CategoriesApiController extends AbstractController
{
    private const CATEGORIES = [
        ['code' => 'mautic-plugin', 'label' => 'Plugin'],
        ['code' => 'mautic-theme', 'label' => 'Theme'],
        ['code' => 'mautic-resource', 'label' => 'Resource (Campaign)'],
    ];

    public function list(): JsonResponse
    {
        return $this->json(['categories' => self::CATEGORIES]);
    }
}
