<?php

namespace App\Controller;

use App\Entity\Deck;
use App\Repository\DeckRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final class AdminBgaController extends AbstractController
{
    private const BGA_VALID_FORMATS = ['standard', 'nuc', 'sandbox'];

    public function __construct(
        private readonly DeckRepository      $deckRepository,
        private readonly SerializerInterface $serializer,
    ) {}

    #[Route('/admin/bga', name: 'admin_bga_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$request->getSession()->has('admin_user_id')) {
            return $this->redirectToRoute('admin_login');
        }

        $name   = (string) $request->query->get('name', '');
        $format = (string) $request->query->get('format', '');
        $page   = max(1, (int) $request->query->get('page', 1));
        $items  = 20;

        $factions = ['AX', 'BR', 'MU', 'LY', 'OR', 'YZ'];
        $decks    = $this->deckRepository->findBgaDecks(null, $page, $items, $name, $factions, '', $format, self::BGA_VALID_FORMATS);
        $total    = $this->deckRepository->countBgaDecks(null, $name, $factions, '', $format, self::BGA_VALID_FORMATS);
        $lastPage = max(1, (int) ceil($total / $items));

        $rows = array_map(function (Deck $deck): array {
            $heroRef = $deck->getStats()['hero']['reference'] ?? null;
            $parts   = $heroRef ? explode('_', $heroRef) : [];

            return [
                'id'         => (string) $deck->getId(),
                'name'       => $deck->getName(),
                'format'     => $deck->getFormat(),
                'heroRef'    => $heroRef,
                'faction'    => $parts[3] ?? null,
                'totalCards' => $deck->getStats()['totalCards'] ?? null,
            ];
        }, $decks);

        return $this->render('admin/bga/index.html.twig', [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'lastPage' => $lastPage,
            'filters'  => compact('name', 'format'),
            'formats'  => self::BGA_VALID_FORMATS,
        ]);
    }

    #[Route('/admin/bga/{id}', name: 'admin_bga_show', methods: ['GET'],
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(string $id, Request $request): Response
    {
        if (!$request->getSession()->has('admin_user_id')) {
            return $this->redirectToRoute('admin_login');
        }

        $deck = $this->deckRepository->find($id);
        if (!$deck) {
            throw $this->createNotFoundException();
        }

        $heroRef  = $deck->getStats()['hero']['reference'] ?? null;
        $parts    = $heroRef ? explode('_', $heroRef) : [];
        $faction  = $parts[3] ?? null;

        $collectionEntry = [
            'hero'      => $heroRef,
            'faction'   => $faction,
            'apiId'     => (string) $deck->getId(),
            'deckName'  => $deck->getName(),
            'cardCount' => $deck->getStats()['totalCards'] ?? 0,
        ];

        try {
            $itemData = $this->serializer->normalize($deck, 'json', [
                'groups' => ['deck:read', 'deck:read:detail'],
                'view'   => 'bga',
            ]);
            $itemJson = json_encode($itemData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $itemData = null;
            $itemJson = 'Erreur normalisation : ' . $e->getMessage();
        }

        return $this->render('admin/bga/deck.html.twig', [
            'deck'            => $deck,
            'faction'         => $faction,
            'collectionJson'  => json_encode(
                ['success' => 1, 'content' => ['decks' => [$collectionEntry]]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ),
            'itemJson'        => $itemJson,
            'hasErrors'       => !empty($deck->getFormatErrors()),
            'formatErrors'    => $deck->getFormatErrors() ?? [],
        ]);
    }
}
