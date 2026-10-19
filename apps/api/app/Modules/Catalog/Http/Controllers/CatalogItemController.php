<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\CatalogItemRequest;
use App\Modules\Catalog\Http\Resources\CatalogItemResource;
use App\Modules\Catalog\Models\CatalogItem;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CatalogItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:product,service'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = CatalogItem::query()
            ->when($request->query('search'), function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like)->orWhere('category', 'ilike', $like));
            })
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')->orderBy('id');

        return CatalogItemResource::collection($query->paginate((int) $request->query('per_page', 25))->withQueryString());
    }

    public function store(CatalogItemRequest $request, AuditLogger $audit): JsonResponse
    {
        $item = $this->persist(new CatalogItem, $request);
        $audit->record('catalog_item.created', $item);

        return (new CatalogItemResource($item->refresh()))->response()->setStatusCode(201);
    }

    public function show(CatalogItem $item): CatalogItemResource
    {
        return new CatalogItemResource($item);
    }

    public function update(CatalogItemRequest $request, CatalogItem $item, AuditLogger $audit): CatalogItemResource
    {
        $this->persist($item, $request);
        $audit->record('catalog_item.updated', $item, ['fields' => array_keys($item->getChanges())]);

        return new CatalogItemResource($item);
    }

    public function destroy(CatalogItem $item, AuditLogger $audit): JsonResponse
    {
        $item->delete();
        $audit->record('catalog_item.archived', $item);

        return new JsonResponse(null, 204);
    }

    private function persist(CatalogItem $item, CatalogItemRequest $request): CatalogItem
    {
        $data = $request->validated();

        if (array_key_exists('price', $data)) {
            $data['default_price_minor'] = Money::parseToMinor($data['price']);
            unset($data['price']);
        }
        if (array_key_exists('default_discount_percent', $data)) {
            // Percent with two decimals -> basis points ("12.5" -> 1250).
            $data['default_discount_bp'] = Money::parseToMinor($data['default_discount_percent'] ?? '0');
            unset($data['default_discount_percent']);
        }
        if (array_key_exists('tax_rate_percent', $data)) {
            $data['tax_rate_bp'] = $data['tax_rate_percent'] === null ? null : Money::parseToMinor($data['tax_rate_percent']);
            unset($data['tax_rate_percent']);
        }
        if (isset($data['unit'])) {
            $data['unit'] = mb_strtolower(trim($data['unit']));
        }

        $item->fill($data);

        try {
            DB::transaction(fn () => $item->save());
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, 'An item with this SKU already exists.', 422, ['fields' => ['sku' => ['An item with this SKU already exists.']]]);
        }

        return $item;
    }
}
