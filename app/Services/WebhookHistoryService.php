<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WebhookHistoryService
{
    /**
     * Build the deterministic unified query across active and archived tiers.
     */
    public function getLogsQueryForOrder(Order|int $order): Builder
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        $columns = [
            'id', 'order_id', 'event', 'event_id', 'url', 'payload',
            'response_code', 'response_body', 'success', 'attempt',
            'error_label', 'created_at', 'updated_at',
        ];

        $activeQuery = DB::table('webhook_logs')
            ->select($columns)
            ->selectRaw("'active' as storage_tier")
            ->where('order_id', $orderId);

        $archivedQuery = DB::table('archived_webhook_logs')
            ->select($columns)
            ->selectRaw("'archive' as storage_tier")
            ->where('order_id', $orderId);

        $derivedSql = "({$activeQuery->toSql()} UNION ALL {$archivedQuery->toSql()}) AS unified_webhook_logs";

        return DB::table(DB::raw($derivedSql))
            ->mergeBindings($activeQuery)
            ->mergeBindings($archivedQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->orderBy('storage_tier', 'asc');
    }

    /**
     * Get paginated logs for single order view.
     */
    public function getLogsForOrder(Order|int $order, int $perPage = 25): LengthAwarePaginator
    {
        return $this->getLogsQueryForOrder($order)->paginate($perPage);
    }

    /**
     * Get all logs for an order as a hydrated object collection.
     */
    public function getAllLogsForOrder(Order|int $order): Collection
    {
        return $this->getLogsQueryForOrder($order)->get()->map(function ($row) {
            if ($row->created_at !== null && ! ($row->created_at instanceof Carbon)) {
                $row->created_at = Carbon::parse($row->created_at);
            }
            if ($row->updated_at !== null && ! ($row->updated_at instanceof Carbon)) {
                $row->updated_at = Carbon::parse($row->updated_at);
            }
            if (is_string($row->payload)) {
                $decoded = json_decode($row->payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row->payload = $decoded;
                }
            }

            return $row;
        });
    }

    /**
     * Cross-tier reconciliation check: returns true if any active or archived log records a successful delivery.
     */
    public function hasSuccessfulLog(int $orderId, ?string $eventId, ?string $event = null): bool
    {
        $activeExists = DB::table('webhook_logs')
            ->where('order_id', $orderId)
            ->when($eventId !== null, fn ($q) => $q->where('event_id', $eventId), fn ($q) => $q->where('event', $event))
            ->where('success', true)
            ->exists();

        if ($activeExists) {
            return true;
        }

        return DB::table('archived_webhook_logs')
            ->where('order_id', $orderId)
            ->when($eventId !== null, fn ($q) => $q->where('event_id', $eventId), fn ($q) => $q->where('event', $event))
            ->where('success', true)
            ->exists();
    }
}
