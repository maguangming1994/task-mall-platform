<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Cart\Models\Cart;
use App\Modules\Inventory\Models\ShippingTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShippingTemplateService
{
    public function create(AdminUser $admin, array $data, string $idempotencyKey): ShippingTemplate
    {
        $hash = hash('sha256', $idempotencyKey);
        $existing = ShippingTemplate::query()->where('created_by_admin_id', $admin->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }
        $this->validate($data);

        return DB::transaction(function () use ($admin, $data, $hash) {
            $template = ShippingTemplate::query()->create([
                'name' => $data['name'], 'status' => $data['enabled'] ? 'active' : 'draft',
                'created_by_admin_id' => $admin->id, 'idempotency_key_hash' => $hash,
                'published_at' => $data['enabled'] ? now() : null,
            ]);
            $template->versions()->create(['version_no' => 1, 'content' => $this->content($data), 'created_by_admin_id' => $admin->id]);

            return $template->fresh('versions');
        });
    }

    public function update(AdminUser $admin, ShippingTemplate $template, array $data): ShippingTemplate
    {
        $this->validate($data);

        return DB::transaction(function () use ($admin, $template, $data) {
            $template = ShippingTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->lock_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => '运费模板已更新，请刷新后重试。']);
            }
            $next = $template->current_version_no + 1;
            $template->update([
                'name' => $data['name'], 'status' => $data['enabled'] ? 'active' : 'inactive',
                'current_version_no' => $next, 'lock_version' => $template->lock_version + 1,
                'published_at' => $data['enabled'] ? now() : $template->published_at,
            ]);
            $template->versions()->create(['version_no' => $next, 'content' => $this->content($data), 'created_by_admin_id' => $admin->id]);

            return $template->fresh('versions');
        });
    }

    public function present(ShippingTemplate $template): array
    {
        $version = $template->versions()->where('version_no', $template->current_version_no)->firstOrFail();

        return ['id' => $template->public_id, 'name' => $template->name, 'status' => $template->status, 'version' => $template->lock_version, 'definition_version' => $template->current_version_no, ...$version->content];
    }

    public function quoteCart(User $user, UserAddress $address): array
    {
        abort_unless($address->user_id === $user->id, 404);
        $cart = Cart::query()->where('user_id', $user->id)->with(['items.sku.product', 'items.sku.purchaseOptions', 'items.sku.shippingTemplate.versions', 'items.storeSku.product.store'])->first();
        if (! $cart) {
            return ['shipping_cash_amount' => 0, 'groups' => [], 'cash_only' => true];
        }
        $selected = $cart->items->where('selected', true);
        $groups = $selected->filter(fn ($item) => $item->sku !== null && $item->sku->fulfillment_type === 'logistics')->groupBy('sku.shipping_template_id');
        $result = [];
        $total = 0;
        foreach ($groups as $templateId => $items) {
            if (! $templateId) {
                throw ValidationException::withMessages(['shipping' => '购物车包含未配置运费模板的物流商品。']);
            }
            /** @var ShippingTemplate $template */
            $template = $items->first()->sku->shippingTemplate;
            if ($template->status !== 'active') {
                throw ValidationException::withMessages(['shipping' => '购物车商品的运费模板当前不可用。']);
            }
            $version = $template->versions->firstWhere('version_no', $template->current_version_no);
            $content = $version->content;
            $region = collect($content['regions'])->first(fn ($rule) => count(array_intersect($rule['region_codes'], [$address->province, $address->city, $address->district])) > 0)
                ?? collect($content['regions'])->first(fn ($rule) => in_array('*', $rule['region_codes'], true));
            if (! $region || ! $region['deliverable']) {
                throw ValidationException::withMessages(['address_id' => '当前地址不在配送范围内。']);
            }
            $quantity = $items->sum('quantity');
            $weightGrams = $items->sum(fn ($item) => ((int) $item->sku->weight_grams) * $item->quantity);
            $cashSubtotal = $items->sum(function ($item) {
                $option = $item->sku->purchaseOptions->firstWhere('purchase_mode', $item->purchase_mode);

                return $option->cash_amount * $item->quantity;
            });
            $freeRule = collect($content['free_rules'])->first(fn ($rule) => $this->matchesFreeRule($rule, $items, $cashSubtotal, $quantity));
            $base = $freeRule ? 0 : $this->charge($content, $quantity, $weightGrams);
            $surcharge = $freeRule ? 0 : (int) $region['remote_surcharge_amount'];
            $amount = $base + $surcharge;
            $total += $amount;
            $result[] = [
                'template_id' => $template->public_id, 'template_version' => $template->current_version_no,
                'charge_type' => $content['charge_type'], 'quantity' => $quantity, 'weight_grams' => $weightGrams,
                'base_amount' => $base, 'remote_surcharge_amount' => $surcharge, 'free_rule' => $freeRule,
                'shipping_cash_amount' => $amount,
            ];
        }

        foreach ($selected->filter(fn ($item) => $item->storeSku !== null && $item->storeSku->fulfillment_type === 'logistics')->groupBy(fn ($item) => $item->storeSku?->product?->store_id) as $storeItems) {
            $store = $storeItems->first()->storeSku?->product?->store;
            if ($store === null || ! $store->isActive()) {
                throw ValidationException::withMessages(['shipping' => '购物车包含已暂停营业的门店商品。']);
            }
            $quantity = $storeItems->sum('quantity');
            $cashSubtotal = $storeItems->sum(fn ($item) => (int) $item->storeSku->cash_amount * $item->quantity);
            $free = $store->free_shipping_threshold !== null && $cashSubtotal >= (int) $store->free_shipping_threshold;
            $amount = $free ? 0 : (int) $store->shipping_fee_amount;
            $total += $amount;
            $result[] = [
                'template_id' => null, 'store_id' => $store->public_id, 'template_version' => null,
                'charge_type' => 'store_flat', 'quantity' => $quantity, 'weight_grams' => 0,
                'base_amount' => $amount, 'remote_surcharge_amount' => 0, 'free_rule' => $free,
                'shipping_cash_amount' => $amount,
            ];
        }

        return ['shipping_cash_amount' => $total, 'groups' => $result, 'cash_only' => true, 'address_id' => $address->public_id];
    }

    private function charge(array $content, int $quantity, int $weightGrams): int
    {
        if ($content['charge_type'] === 'fixed') {
            return (int) $content['fixed_amount'];
        }
        $units = $content['charge_type'] === 'by_quantity' ? $quantity : $weightGrams / 1000;
        $extra = max(0, $units - (float) $content['first_unit']);

        return (int) $content['first_amount'] + (int) ceil($extra / (float) $content['additional_unit']) * (int) $content['additional_amount'];
    }

    private function matchesFreeRule(array $rule, $items, int $cash, int $quantity): bool
    {
        return match ($rule['type']) {
            'cash_threshold' => $cash >= (int) ($rule['threshold_amount'] ?? PHP_INT_MAX),
            'quantity_threshold' => $quantity >= (int) ($rule['threshold_quantity'] ?? PHP_INT_MAX),
            'sku' => $items->contains(fn ($item) => in_array($item->sku->public_id, $rule['target_ids'] ?? [], true)),
            'product' => $items->contains(fn ($item) => in_array($item->sku->product->public_id, $rule['target_ids'] ?? [], true)),
            default => false,
        };
    }

    private function validate(array $data): void
    {
        $type = $data['charge_type'];
        if ($type === 'fixed' && ! isset($data['fixed_amount'])) {
            throw ValidationException::withMessages(['fixed_amount' => '固定运费必须填写金额。']);
        }
        if ($type !== 'fixed' && (! isset($data['first_unit'], $data['first_amount'], $data['additional_unit'], $data['additional_amount']))) {
            throw ValidationException::withMessages(['charge_type' => '按件或按重量必须填写首段与续段规则。']);
        }
        if (! collect($data['regions'])->contains(fn ($region) => in_array('*', $region['region_codes'], true))) {
            throw ValidationException::withMessages(['regions' => '必须提供通配默认配送规则。']);
        }
    }

    private function content(array $data): array
    {
        return collect($data)->only(['charge_type', 'fixed_amount', 'first_unit', 'first_amount', 'additional_unit', 'additional_amount', 'regions', 'free_rules'])->all();
    }
}
