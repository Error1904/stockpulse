<?php
/**
 * StockPulse — Dashboard view
 * ---------------------------------------------------------------------------
 * Variables injected by StockController::renderDashboard():
 *   $products        — product catalogue with aggregated totals
 *   $zones           — distinct warehouse zone names
 *   $zoneStock       — zone-level rows grouped by product_id
 *   $lowStock        — products at/below reorder level
 *   $recentMovements — latest stock_logs entries
 *   $totalValue      — SUM(unit_price * quantity)
 *   $totalSkus       — number of SKUs
 *   $movementCount   — number of logged movements
 * ---------------------------------------------------------------------------
 */
?>

<!-- Ajax feedback area — jQuery injects Bootstrap alerts here -->
<div id="alertContainer" aria-live="polite"></div>

<!-- ============================ KPI Cards ============================ -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="kpi-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-currency-dollar"></i>
                </div>
                <div>
                    <div class="text-muted small">Inventory Value</div>
                    <h5 class="mb-0 fw-bold">$<?= number_format($totalValue, 2) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="kpi-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="text-muted small">Low-Stock Alerts</div>
                    <h5 class="mb-0 fw-bold"><?= count($lowStock) ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="kpi-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-tags"></i>
                </div>
                <div>
                    <div class="text-muted small">Total SKUs</div>
                    <h5 class="mb-0 fw-bold"><?= $totalSkus ?></h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="kpi-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-arrow-left-right"></i>
                </div>
                <div>
                    <div class="text-muted small">Movements Logged</div>
                    <h5 class="mb-0 fw-bold"><?= $movementCount ?></h5>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">

    <!-- ======================= Stock overview table ======================= -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title mb-0"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Warehouse Stock Overview</h5>
                <button type="button" class="btn btn-primary btn-sm"
                        data-bs-toggle="modal" data-bs-target="#transferModal">
                    <i class="bi bi-arrow-left-right me-1"></i>New Transfer
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">SKU</th>
                            <th scope="col">Product</th>
                            <th scope="col">Category</th>
                            <th scope="col" class="text-end">Unit Price</th>
                            <th scope="col">Stock by Zone</th>
                            <th scope="col" class="text-end">Total Qty</th>
                            <th scope="col" class="text-end">Reorder Level</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p):
                            $total   = (int) $p['total_quantity'];
                            $reorder = (int) $p['reorder_level'];
                            $rows    = $zoneStock[(int) $p['product_id']] ?? [];

                            if ($total === 0) {
                                $badge = '<span class="badge bg-danger">Out of stock</span>';
                            } elseif ($total <= $reorder) {
                                $badge = '<span class="badge bg-warning text-dark">Low stock</span>';
                            } else {
                                $badge = '<span class="badge bg-success">In stock</span>';
                            }
                        ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($p['sku']) ?></td>
                                <td><?= htmlspecialchars($p['product_name']) ?></td>
                                <td><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                                <td class="text-end">$<?= number_format((float) $p['unit_price'], 2) ?></td>
                                <td>
                                    <?php if ($rows): ?>
                                        <?php foreach ($rows as $r): ?>
                                            <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary me-1 mb-1">
                                                <?= htmlspecialchars($r['zone_name']) ?>:
                                                <strong><?= (int) $r['quantity'] ?></strong>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold"><?= $total ?></td>
                                <td class="text-end text-muted"><?= $reorder ?></td>
                                <td><?= $badge ?></td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary transferBtn"
                                            data-product-id="<?= (int) $p['product_id'] ?>"
                                            title="Transfer <?= htmlspecialchars($p['product_name']) ?>"
                                            data-bs-toggle="modal" data-bs-target="#transferModal">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ======================= Recent movements =========================== -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recent Stock Movements</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Timestamp</th>
                            <th scope="col">SKU</th>
                            <th scope="col">Product</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-end">Qty</th>
                            <th scope="col">User</th>
                            <th scope="col">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentMovements)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No movements recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentMovements as $m):
                                $typeBadge = match ((string) $m['movement_type']) {
                                    'inward'  => '<span class="badge bg-success">Inward</span>',
                                    'outward' => '<span class="badge bg-danger">Outward</span>',
                                    default    => '<span class="badge bg-info text-dark">Transfer</span>',
                                };
                            ?>
                                <tr>
                                    <td class="text-muted small">
                                        <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string) $m['logged_at']))) ?>
                                    </td>
                                    <td class="fw-semibold"><?= htmlspecialchars($m['sku']) ?></td>
                                    <td><?= htmlspecialchars($m['product_name']) ?></td>
                                    <td><?= $typeBadge ?></td>
                                    <td class="text-end"><?= (int) $m['quantity'] ?></td>
                                    <td><?= htmlspecialchars($m['full_name']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars((string) ($m['notes'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ======================= Transfer modal ======================= -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="transferForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="transferModalLabel">
                        <i class="bi bi-arrow-left-right me-2"></i>Transfer Stock
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label for="transferProduct" class="form-label">Product</label>
                        <select name="product_id" id="transferProduct" class="form-select" required>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['product_id'] ?>">
                                    <?= htmlspecialchars($p['sku'] . ' — ' . $p['product_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="fromZone" class="form-label">From Zone</label>
                            <select name="from_zone" id="fromZone" class="form-select" required>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?= htmlspecialchars($zone) ?>">
                                        <?= htmlspecialchars($zone) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="toZone" class="form-label">To Zone</label>
                            <select name="to_zone" id="toZone" class="form-select" required>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?= htmlspecialchars($zone) ?>">
                                        <?= htmlspecialchars($zone) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-0 mt-3">
                        <label for="transferQuantity" class="form-label">Quantity</label>
                        <input type="number" name="quantity" id="transferQuantity"
                               class="form-control" min="1" step="1" value="1" required>
                        <div class="form-text">Units to move from the source zone to the destination zone.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Transfer Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================= Login modal ======================= -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="loginForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="loginModalLabel">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Staff Login
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="loginEmail" class="form-label">Email address</label>
                        <input type="email" name="email" id="loginEmail" class="form-control"
                               placeholder="admin@stockpulse.local" required>
                    </div>
                    <div class="mb-0">
                        <label for="loginPassword" class="form-label">Password</label>
                        <input type="password" name="password" id="loginPassword"
                               class="form-control" placeholder="••••••••" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Login
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
