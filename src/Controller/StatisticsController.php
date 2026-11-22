<?php

namespace App\Controller;

use App\Service\StatisticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatisticsController extends AbstractController
{
    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function index(Request $request, StatisticsService $statistics): Response
    {
        $period = $statistics->normalizePeriod($request->query->get('period'));

        return $this->render('statistics/index.html.twig', $statistics->getStatistics($period));
    }

    #[Route('/statistics/export', name: 'statistics_export', methods: ['GET'])]
    public function export(Request $request, StatisticsService $statistics): Response
    {
        $period = $statistics->normalizePeriod($request->query->get('period'));
        $filename = sprintf(
            'statistics-%s-%s.csv',
            $period,
            $statistics->getNow()->format('Ymd-His')
        );

        return new Response($statistics->exportCsv($period), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
