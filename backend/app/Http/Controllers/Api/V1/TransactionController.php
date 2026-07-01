<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transactions = QueryBuilder::for(Transaction::class)
            ->allowedFilters([
                AllowedFilter::exact('customer_id'),
                AllowedFilter::exact('type'),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    $query->whereDate('created_at', '>=', $value);
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    $query->whereDate('created_at', '<=', $value);
                }),
            ])
            ->with(['customer', 'operator'])
            ->allowedSorts(['id', 'amount', 'created_at'])
            ->defaultSort('-id')
            ->paginate($request->input('per_page', 15));

        return $this->paginated($transactions);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load(['customer', 'operator']);

        return $this->success($transaction);
    }
}
