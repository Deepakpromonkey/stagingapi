<?php

namespace App\Http\Controllers\Api\V1\Drayage;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Drayage\ListDrayageCarriersRequest;
use App\Http\Requests\Drayage\LookupDrayageCarriersRequest;
use App\Http\Requests\Drayage\ShowDrayageCarrierRequest;
use App\Services\Drayage\DrayageDirectoryService;
use App\Services\Drayage\DrayageEnrichmentService;
use App\Services\Drayage\DrayageNormalizer;
use Illuminate\Http\Request;

/**
 * Drayage carriers imported from the LoadMatch / Drayage.com directory:
 * search, detail and identifier lookup.
 *
 * The directory itself comes from JSON files (DrayageDirectoryService);
 * anything DollarTraq knows about the same carrier - FMCSA record, Trust
 * Score, the broker's own onboarding - is attached only when asked for with
 * include=, because it costs a trip to the carrier database.
 */
class DrayageCarrierController extends BaseController
{
    public function __construct(
        private DrayageDirectoryService $directory,
        private DrayageEnrichmentService $enrichment,
    ) {}

    public function index(ListDrayageCarriersRequest $request)
    {
        $result = $this->directory->search($request->drayageQuery());

        $items = $this->enrich($result['items'], $result['page_rows'], $request->includes(), $request);

        return $this->success([
            'carriers' => $items,
            'pagination' => $result['pagination'],
            'facets' => $result['facets'],
            'summary' => $result['stats'],
            'dataset' => $this->datasetInfo(),
            'source' => config('drayage.source_label'),
        ], 'Drayage carriers retrieved successfully.');
    }

    public function show(ShowDrayageCarrierRequest $request, string $carrierKey)
    {
        $document = $this->directory->find($carrierKey);

        if (! $document) {
            return $this->error('Drayage carrier not found.', null, 404);
        }

        $includes = $request->includes();
        $record = DrayageNormalizer::flatten($document);
        $dot = $record['usdot'] ?? null;

        $data = ['carrier' => $document];

        if (in_array('onboarding', $includes, true) || in_array('fmcsa', $includes, true)) {
            $inDollarTraq = $dot ? ($this->enrichment->inDollarTraq([$dot])[$dot] ?? null) : null;
            $data['in_dollartraq'] = $inDollarTraq;

            if (in_array('onboarding', $includes, true)) {
                $onboarding = $dot ? ($this->enrichment->onboarding($request->user()->company_id, [$dot])[$dot] ?? null) : null;
                $data['onboarding'] = $onboarding;
                $data['actions'] = $this->enrichment->actions($dot, $inDollarTraq, $onboarding, $record);
            }
        }

        if (in_array('fmcsa', $includes, true)) {
            $data['fmcsa'] = $dot ? $this->enrichment->fmcsa($dot) : null;
        }

        if (in_array('trust_score', $includes, true)) {
            $data['trust_score'] = $dot ? ($this->enrichment->trustScores([$dot])[$dot] ?? null) : null;
        }

        $data['dataset'] = $this->datasetInfo();
        $data['source'] = config('drayage.source_label');

        return $this->success($data, 'Drayage carrier retrieved successfully.');
    }

    public function lookup(LookupDrayageCarriersRequest $request)
    {
        [$type, $value] = $request->lookup();

        $carriers = $this->directory->lookup($type, $value);

        return $this->success([
            'carriers' => $carriers,
            'count' => count($carriers),
            'lookup' => ['type' => $type, 'value' => $value],
            'dataset' => $this->datasetInfo(),
            'source' => config('drayage.source_label'),
        ], 'Drayage carriers retrieved successfully.');
    }

    /**
     * Page-level enrichment: one batched lookup per kind for the whole page.
     */
    private function enrich(array $items, array $rows, array $includes, Request $request): array
    {
        if ($includes === []) {
            return $items;
        }

        $dots = array_values(array_filter(array_column($rows, 'usdot')));

        $scores = in_array('trust_score', $includes, true) ? $this->enrichment->trustScores($dots) : null;

        $inDollarTraq = null;
        $onboarding = null;
        if (in_array('onboarding', $includes, true)) {
            $inDollarTraq = $this->enrichment->inDollarTraq($dots);
            $onboarding = $this->enrichment->onboarding($request->user()->company_id, $dots);
        }

        foreach ($items as $i => $item) {
            $dot = $rows[$i]['usdot'] ?? null;

            if ($scores !== null) {
                $items[$i]['trust_score'] = $dot ? ($scores[$dot] ?? null) : null;
            }

            if ($inDollarTraq !== null) {
                $items[$i]['in_dollartraq'] = $dot ? ($inDollarTraq[$dot] ?? null) : null;
                $items[$i]['onboarding'] = $dot ? ($onboarding[$dot] ?? null) : null;
                $items[$i]['actions'] = $this->enrichment->actions($dot, $items[$i]['in_dollartraq'], $items[$i]['onboarding'], $rows[$i]);
            }
        }

        return $items;
    }

    private function datasetInfo(): array
    {
        $current = $this->directory->current();

        return [
            'dataset_id' => $current['dataset_id'],
            'activated_at' => $current['activated_at'],
        ];
    }
}
