<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ManualPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualPerformanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ManualPerformance::with(['customer:id,customer_name', 'creator:id,name']);

        if ($request->filled('sales_person')) {
            $query->where('sales_person', $request->input('sales_person'));
        }
        if ($request->filled('date_from')) {
            $query->where('performance_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('performance_date', '<=', $request->input('date_to'));
        }

        $records = $query->orderByDesc('performance_date')->orderByDesc('id')->get();

        return $this->success($records);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric',
            'profit' => 'nullable|numeric',
            'performance_date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        $record = ManualPerformance::create([
            'customer_id' => $customer->id,
            'sales_person' => $customer->sales_person,
            'amount' => $data['amount'],
            'profit' => $data['profit'] ?? $data['amount'],
            'performance_date' => $data['performance_date'],
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $record->load(['customer:id,customer_name', 'creator:id,name']);

        return $this->success($record, '添加成功');
    }

    public function destroy(ManualPerformance $manualPerformance): JsonResponse
    {
        $manualPerformance->delete();
        return $this->success(null, '已删除');
    }
}
