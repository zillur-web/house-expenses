<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return ['submission_key' => 'nullable|uuid', 'type' => 'required|in:payment,expense,bazar,bill,adjustment,opening,cash_opening', 'date' => 'required|date_format:Y-m-d', 'member_id' => 'nullable|required_if:type,payment,bill,adjustment,opening|exists:members,id', 'expense_category_id' => 'nullable|required_if:type,expense,bazar|exists:expense_categories,id', 'amount' => 'required_unless:type,bazar,bill|nullable|decimal:0,2|between:-999999999,999999999', 'description' => 'required|string|max:1000', 'payment_method' => 'required|in:Cash,Bank,bKash,Nagad,Rocket,Other', 'external_reference' => 'nullable|string|max:255', 'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', 'sharing' => 'nullable|in:none,equal,selected,custom,percentage', 'shares' => 'nullable|array', 'shares.*' => 'nullable|numeric|min:0|max:999999999', 'monthly_charge' => 'nullable|decimal:0,2|min:0', 'other_charge' => 'nullable|decimal:0,2|min:0', 'discount' => 'nullable|decimal:0,2|min:0', 'direction' => 'nullable|in:debit,credit', 'shop' => 'nullable|string|max:255', 'paid_by' => 'nullable|string|max:255', 'note' => 'nullable|string|max:2000', 'items' => 'required_if:type,bazar|array|min:1|max:100', 'items.*.item' => 'required|string|max:255', 'items.*.quantity' => 'required|numeric|gt:0|max:999999|decimal:0,3', 'items.*.unit_price' => 'required|decimal:0,2|min:0|max:9999999'];
    }
}
