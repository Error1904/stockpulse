<?php
/**
 * StockPulse — page footer (layout)
 * Closes <main>, renders the site footer and loads JavaScript bundles.
 */
?>
</main>

<!-- ============================ Footer ============================ -->
<footer class="bg-light border-top py-3 mt-auto">
    <div class="container-fluid">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <span class="text-muted">
                <i class="bi bi-box-seam me-1"></i>StockPulse v1.0 — Native PHP 8.x · MySQL (InnoDB) · jQuery Ajax
            </span>
            <span class="text-muted">
                Rendered in <?= number_format(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 4) ?> s
            </span>
        </div>
    </div>
</footer>

<!-- Bootstrap 5 JS bundle (modal support) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- StockPulse Ajax handlers -->
<script src="<?= BASE_URL ?>/js/stock.js"></script>
</body>
</html>
