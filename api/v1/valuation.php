<?php
declare(strict_types=1);

/**
 * StockPulse REST API — GET /api/v1/valuation.php
 * ---------------------------------------------------------------------------
 * Returns structured JSON stock metrics:
 *   - total inventory value : SUM(unit_price * quantity) across all zones
 *   - low-stock count       : SKUs at or below their reorder level
 *   - recent movements      : latest entries from the stock_logs audit trail
 *
 * Conventions:
 *   - JSON responses with explicit Content-Type and no-store caching
 *   - Non-GET methods are rejected with 405 + Allow header
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../models/StockModel.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(
        ['status' => 'error', 'message' => 'Method not allowed. Use GET.'],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    $model = new StockModel();

    $lowStock   = $model->getLowStockProducts();
    $movements  = $model->getRecentMovements(5);
    $totalValue = $model->getTotalInventoryValue();

    $payload = [
        'status'       => 'success',
        'generated_at' => date('c'),
        'data'         => [
            'total_inventory_value'           => $totalValue,
            'total_inventory_value_formatted' => number_format($totalValue, 2, '.', ','),
            'currency'                        => 'USD',
            'total_skus'                      => $model->getTotalSkuCount(),
            'low_stock_count'                 => count($lowStock),
            'low_stock_products'              => array_map(
                static function (array $row): array {
                    return [
                        'product_id'     => (int) $row['product_id'],
                        'sku'            => $row['sku'],
                        'product_name'   => $row['product_name'],
                        'total_quantity' => (int) $row['total_quantity'],
                        'reorder_level'  => (int) $row['reorder_level'],
                    ];
                },
                $lowStock
            ),
            'recent_movements'                => array_map(
                static function (array $row): array {
                    return [
                        'log_id'        => (int) $row['log_id'],
                        'movement_type' => $row['movement_type'],
                        'sku'           => $row['sku'],
                        'product_name'  => $row['product_name'],
                        'quantity'      => (int) $row['quantity'],
                        'notes'         => $row['notes'],
                        'user'          => $row['full_name'],
                        'logged_at'     => $row['logged_at'],
                    ];
                },
                $movements
            ),
        ],
    ];

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[StockPulse] valuation API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(
        ['status' => 'error', 'message' => 'Unable to compute valuation.'],
        JSON_UNESCAPED_SLASHES
    );
}
