<?php
declare(strict_types=1);

/**
 * StockPulse — StockModel (Data Access Layer)
 * ---------------------------------------------------------------------------
 * Every database interaction lives here and uses PDO prepared statements, so
 * no user input is ever concatenated into SQL. The critical write operation
 * (transferStock) runs inside an ACID transaction with row locking, which
 * guarantees stock can never be created or destroyed by a partially-completed
 * or concurrently-executed transfer.
 * ---------------------------------------------------------------------------
 */
class StockModel
{
    /** @var PDO Shared connection obtained from the Database singleton. */
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /* ---------------------------------------------------------------------- */
    /* READ QUERIES — dashboard data                                          */
    /* ---------------------------------------------------------------------- */

    /**
     * Product catalogue joined with category names and total on-hand quantity
     * aggregated across all warehouse zones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductStockSummary(): array
    {
        $sql = "
            SELECT p.product_id,
                   p.sku,
                   p.product_name,
                   p.unit_price,
                   p.reorder_level,
                   c.category_name,
                   COALESCE(SUM(ws.quantity), 0) AS total_quantity
            FROM products p
            LEFT JOIN categories c      ON c.category_id = p.category_id
            LEFT JOIN warehouse_stock ws ON ws.product_id = p.product_id
            GROUP BY p.product_id, p.sku, p.product_name, p.unit_price,
                     p.reorder_level, c.category_name
            ORDER BY p.product_name ASC
        ";
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * Raw zone-level stock rows indexed by product id:
     *   [product_id => [ ['zone_name' => ..., 'quantity' => ...], ... ]]
     * A single query avoids the N+1 problem when rendering the stock table.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getStockGroupedByProduct(): array
    {
        $sql = "SELECT product_id, zone_name, quantity
                FROM warehouse_stock
                ORDER BY product_id ASC, zone_name ASC";

        $grouped = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * Distinct warehouse zone names (used to populate the transfer modal).
     *
     * @return array<int, string>
     */
    public function getZones(): array
    {
        $sql = "SELECT DISTINCT zone_name
                FROM warehouse_stock
                ORDER BY zone_name ASC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Products whose total on-hand quantity is at or below their reorder level.
     */
    public function getLowStockProducts(): array
    {
        $sql = "
            SELECT p.product_id, p.sku, p.product_name, p.reorder_level,
                   COALESCE(SUM(ws.quantity), 0) AS total_quantity
            FROM products p
            LEFT JOIN warehouse_stock ws ON ws.product_id = p.product_id
            GROUP BY p.product_id, p.sku, p.product_name, p.reorder_level
            HAVING total_quantity <= p.reorder_level
            ORDER BY total_quantity ASC, p.product_name ASC
        ";
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * Total monetary value of all stock on hand: SUM(unit_price * quantity).
     */
    public function getTotalInventoryValue(): float
    {
        $sql = "
            SELECT COALESCE(SUM(p.unit_price * ws.quantity), 0) AS total_value
            FROM products p
            INNER JOIN warehouse_stock ws ON ws.product_id = p.product_id
        ";
        return (float) $this->pdo->query($sql)->fetch()['total_value'];
    }

    /** Number of distinct SKUs in the catalogue. */
    public function getTotalSkuCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    /** Total number of recorded stock movements. */
    public function getMovementCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stock_logs')->fetchColumn();
    }

    /**
     * Most recent movements with product SKU/name and the responsible user.
     *
     * @param int $limit Maximum rows to return
     * @return array<int, array<string, mixed>>
     */
    public function getRecentMovements(int $limit = 10): array
    {
        $sql = "
            SELECT sl.log_id, sl.movement_type, sl.quantity, sl.notes, sl.logged_at,
                   p.sku, p.product_name, u.full_name
            FROM stock_logs sl
            INNER JOIN products p ON p.product_id = sl.product_id
            INNER JOIN users   u ON u.user_id   = sl.user_id
            ORDER BY sl.log_id DESC
            LIMIT :limit
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /* ---------------------------------------------------------------------- */
    /* USER LOOKUPS — session authentication                                  */
    /* ---------------------------------------------------------------------- */

    /**
     * Fetches a user by e-mail address (used for login verification).
     *
     * @return array<string, mixed>|false
     */
    public function getUserByEmail(string $email): array|false
    {
        $sql = "SELECT user_id, full_name, email, password_hash, role
                FROM users
                WHERE email = :email
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['email' => $email]);
        return $stmt->fetch();
    }

    /* ---------------------------------------------------------------------- */
    /* WRITE OPERATIONS — wrapped in transactions                             */
    /* ---------------------------------------------------------------------- */

    /**
     * Transfers units of a product from one warehouse zone to another.
     *
     * The entire operation is wrapped in a single PDO transaction:
     *
     *   1. Lock the source-zone row with SELECT ... FOR UPDATE so two
     *      concurrent transfers can never overdraw the same stock (race
     *      condition protection under InnoDB row-level locking).
     *   2. Verify sufficient quantity exists in $fromZone.
     *   3. Deduct the quantity from $fromZone.
     *   4. Add the quantity to $toZone (upsert — inserts the zone row when
     *      the product has no stock there yet).
     *   5. Append an audit entry to stock_logs.
     *
     * If any step fails, rollBack() restores the previous state, so the
     * database can never end up with stock deducted but not received.
     *
     * @param int    $productId Product identifier
     * @param string $fromZone  Source zone name
     * @param string $toZone    Destination zone name
     * @param int    $quantity  Units to move (must be > 0)
     * @param int    $userId    Session user performing the movement (audit trail)
     *
     * @return array{status: string, message: string} JSON-ready result
     */
    public function transferStock(int $productId, string $fromZone, string $toZone, int $quantity, int $userId): array
    {
        /* Guard clauses — cheap validation before touching the transaction. */
        if ($quantity <= 0) {
            return ['status' => 'error', 'message' => 'Quantity must be a positive integer.'];
        }

        $fromZone = trim($fromZone);
        $toZone   = trim($toZone);

        if ($fromZone === '' || $toZone === '') {
            return ['status' => 'error', 'message' => 'Source and destination zones are required.'];
        }

        if (strcasecmp($fromZone, $toZone) === 0) {
            return ['status' => 'error', 'message' => 'Source and destination zones must be different.'];
        }

        try {
            /* ---- Transaction start -------------------------------------- */
            $this->pdo->beginTransaction();

            // 1) Lock the source row for the duration of the transaction.
            $stmt = $this->pdo->prepare(
                'SELECT quantity
                 FROM warehouse_stock
                 WHERE product_id = :product_id AND zone_name = :zone_name
                 FOR UPDATE'
            );
            $stmt->execute(['product_id' => $productId, 'zone_name' => $fromZone]);
            $source = $stmt->fetch();

            if ($source === false) {
                $this->pdo->rollBack();
                return [
                    'status'  => 'error',
                    'message' => "Product #{$productId} has no stock recorded in zone '{$fromZone}'.",
                ];
            }

            // 2) Verify sufficient stock BEFORE deducting.
            $available = (int) $source['quantity'];
            if ($available < $quantity) {
                $this->pdo->rollBack();
                return [
                    'status'  => 'error',
                    'message' => "Insufficient stock in '{$fromZone}'. Available: {$available}, requested: {$quantity}.",
                ];
            }

            // 3) Deduct from the source zone.
            $stmt = $this->pdo->prepare(
                'UPDATE warehouse_stock
                 SET quantity = quantity - :quantity
                 WHERE product_id = :product_id AND zone_name = :zone_name'
            );
            $stmt->execute([
                'quantity'   => $quantity,
                'product_id' => $productId,
                'zone_name'  => $fromZone,
            ]);

            // 4) Add to the destination zone (upsert on UNIQUE(product_id, zone_name)).
            //    Note: VALUES() in the UPDATE clause is standard MySQL syntax and
            //    remains fully supported through the MySQL 8.x line.
            $stmt = $this->pdo->prepare(
                'INSERT INTO warehouse_stock (product_id, zone_name, quantity)
                 VALUES (:product_id, :zone_name, :quantity)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
            );
            $stmt->execute([
                'product_id' => $productId,
                'zone_name'  => $toZone,
                'quantity'   => $quantity,
            ]);

            // 5) Audit log entry — who moved what, when, and where.
            $stmt = $this->pdo->prepare(
                'INSERT INTO stock_logs (product_id, user_id, movement_type, quantity, notes)
                 VALUES (:product_id, :user_id, \'transfer\', :quantity, :notes)'
            );
            $stmt->execute([
                'product_id' => $productId,
                'user_id'    => $userId,
                'quantity'   => $quantity,
                'notes'      => "Transferred {$quantity} unit(s) from {$fromZone} to {$toZone}",
            ]);

            /* ---- All statements succeeded -> persist atomically --------- */
            $this->pdo->commit();

            return [
                'status'  => 'success',
                'message' => "Transferred {$quantity} unit(s) of product #{$productId} from '{$fromZone}' to '{$toZone}'.",
            ];
        } catch (Throwable $e) {
            /* Any exception -> roll back so no partial state is persisted. */
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[StockPulse] transferStock failed: ' . $e->getMessage());
            return [
                'status'  => 'error',
                'message' => 'Transfer could not be completed. Please try again.',
            ];
        }
    }
}
