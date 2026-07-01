<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $customer = $request->user();
        return $this->success([
            'id' => $customer->id,
            'username' => $customer->username,
            'customer_name' => $customer->customer_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company_name' => $customer->company_name,
            'company_id' => $customer->company_id,
            'address' => $customer->address,
            'balance' => (float) $customer->balance,
            'commission_balance' => (float) $customer->commission_balance,
            'auto_renew_default' => (bool) $customer->auto_renew_default,
            'sms_expiry_notify' => (bool) $customer->sms_expiry_notify,
            'created_at' => $customer->created_at,
            'last_login_at' => $customer->last_login_at,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $customer = $request->user();

        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:100|unique:customers,customer_name,' . $customer->id,
            'email' => 'nullable|email|max:191|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:30',
            'company_name' => 'nullable|string|max:200',
            'company_id' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            'auto_renew_default' => 'nullable|boolean',
            'sms_expiry_notify' => 'nullable|boolean',
        ], [
            'customer_name.unique' => '客户名称已存在，请换一个',
        ]);

        if (isset($data['customer_name'])) {
            $data['customer_name'] = trim($data['customer_name']);
        }
        $customer->update($data);
        $customer->refresh();
        return $this->success([
            'id' => $customer->id,
            'username' => $customer->username,
            'customer_name' => $customer->customer_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company_name' => $customer->company_name,
            'company_id' => $customer->company_id,
            'address' => $customer->address,
            'balance' => (float) $customer->balance,
            'commission_balance' => (float) $customer->commission_balance,
            'auto_renew_default' => (bool) $customer->auto_renew_default,
            'sms_expiry_notify' => (bool) $customer->sms_expiry_notify,
            'created_at' => $customer->created_at,
            'last_login_at' => $customer->last_login_at,
        ], '资料已更新');
    }
}
