<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentOrder;
use App\Models\PaymentRefund;
use App\Services\Payment\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentRefundController extends Controller
{
    public function __construct(protected PaymentRefundService $refundService) {}

    public function orders(Request $request): JsonResponse
    {
        $query = PaymentOrder::with('customer:id,customer_name', 'gateway:id,name,type')
            ->orderByDesc('id');

        if ($request->filled('customer_name')) {
            $query->whereHas('customer', fn ($q) =>
                $q->where('customer_name', 'like', '%' . $request->customer_name . '%')
            );
        }
        if ($request->filled('gateway_type')) {
            $query->where('gateway_type', $request->gateway_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('page_size', 20), 100);
        $paginated = $query->paginate($perPage);

        $items = $paginated->getCollection()->map(function ($order) {
            $arr = $order->toArray();
            $arr['refundable_amount'] = $order->refundable_amount;
            $arr['customer_name'] = $order->customer?->customer_name;
            $arr['gateway_name'] = $order->gateway?->name;
            return $arr;
        });

        return $this->success([
            'data' => $items,
            'total' => $paginated->total(),
        ]);
    }

    public function refundOrder(Request $request, PaymentOrder $paymentOrder): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
            'subscription_id' => 'nullable|integer|exists:subscriptions,id',
        ]);

        try {
            $refund = $this->refundService->refund(
                order: $paymentOrder,
                amount: (float) $data['amount'],
                operatedBy: $request->user()->id,
                reason: $data['reason'] ?? null,
                subscriptionId: $data['subscription_id'] ?? null,
            );

            return $this->success($refund, '原路退款成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = PaymentRefund::with([
            'paymentOrder:id,order_no,amount',
            'customer:id,customer_name',
            'operator:id,name',
        ])->orderByDesc('id');

        if ($request->filled('customer_name')) {
            $query->whereHas('customer', fn ($q) =>
                $q->where('customer_name', 'like', '%' . $request->customer_name . '%')
            );
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->get('page_size', 20), 100);
        $paginated = $query->paginate($perPage);

        $items = $paginated->getCollection()->map(function ($refund) {
            $arr = $refund->toArray();
            $arr['customer_name'] = $refund->customer?->customer_name;
            $arr['order_no'] = $refund->paymentOrder?->order_no;
            $arr['operator_name'] = $refund->operator?->name;
            return $arr;
        });

        return $this->success([
            'data' => $items,
            'total' => $paginated->total(),
        ]);
    }

    public function refundableOrders(Request $request, Customer $customer): JsonResponse
    {
        $orders = $this->refundService->getRefundableOrders($customer->id);

        $items = $orders->map(function ($order) {
            return [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'amount' => $order->amount,
                'refunded_amount' => $order->refunded_amount,
                'refundable_amount' => $order->refundable_amount,
                'gateway_type' => $order->gateway_type,
                'paid_at' => $order->paid_at?->toDateTimeString(),
            ];
        });

        return $this->success($items);
    }
}
