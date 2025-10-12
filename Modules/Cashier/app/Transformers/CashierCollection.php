<?php

namespace Modules\Cashier\Transformers;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CashierCollection extends ResourceCollection
{
    public function toArray($request): array
    {
        return [
            'data' => CashierResource::collection($this->collection),
            'meta' => [
                'total' => $this->total(),
                'count' => $this->count(),
                'per_page' => $this->perPage(),
                'current_page' => $this->currentPage(),
                'total_pages' => $this->lastPage(),
            ],
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
        ];
    }
}
