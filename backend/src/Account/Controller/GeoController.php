<?php

namespace App\Account\Controller;

use App\Account\Entity\Geo\GeoComune;
use App\Account\Entity\Geo\GeoCountry;
use App\Account\Repository\GeoComuneRepository;
use App\Account\Repository\GeoCountryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Autocompletamento di stati e comuni per i form anagrafici.
 *   GET /api/geo/countries?q=ita
 *   GET /api/geo/comuni?q=mil&storici=1   (storici = anche comuni soppressi, per il luogo di nascita)
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class GeoController extends AbstractController
{
    #[Route('/api/geo/countries', name: 'api_geo_countries', methods: ['GET'])]
    public function countries(Request $request, GeoCountryRepository $countries): JsonResponse
    {
        $result = $countries->search($request->query->get('q'));

        return $this->json(array_map(static fn (GeoCountry $c) => [
            'istatId' => $c->getIstatId(),
            'name' => $c->getNameIt(),
            'isoAlpha2' => $c->getIsoAlpha2(),
        ], $result));
    }

    #[Route('/api/geo/comuni', name: 'api_geo_comuni', methods: ['GET'])]
    public function comuni(Request $request, GeoComuneRepository $comuni): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(static fn (GeoComune $c) => [
            'id' => $c->getId(),
            'name' => $c->getLabel(),
            'provincia' => $c->getProvincialCode(),
            'active' => $c->isActive(),
        ], $comuni->search($query, $request->query->getBoolean('storici'))));
    }
}
