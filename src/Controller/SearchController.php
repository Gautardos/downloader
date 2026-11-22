<?php

namespace App\Controller;

use App\Service\Search\GlobalSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search/global', name: 'search_global', methods: ['GET'])]
    public function global(Request $request, GlobalSearchService $search): JsonResponse
    {
        $query = $request->query->get('q', '');
        $scope = $request->query->get('scope', 'all');
        $limit = $request->query->get('limit');

        return new JsonResponse($search->search(
            is_string($query) ? $query : '',
            is_string($scope) ? $scope : 'all',
            is_numeric($limit) ? (int) $limit : null
        ));
    }
}
