<?php
declare(strict_types=1);

/**
 * StockPulse — StockController
 * ---------------------------------------------------------------------------
 * Mediates between incoming requests and the StockModel.
 *
 * Dual-purpose file:
 *   - Page mode: included by public/index.php (the front controller) and
 *     used to render the HTML dashboard.
 *   - API mode: when requested directly as
 *     controllers/StockController.php?action=transfer|login|logout it acts
 *     as the JSON endpoint for the jQuery handlers in public/js/stock.js.
 *     The STOCKPULSE_FRONT_CONTROLLER constant distinguishes the two modes.
 * ---------------------------------------------------------------------------
 */

// Never leak warnings/notices into JSON responses (headers must stay intact).
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Set to '1' while developing.

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/StockModel.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Per-session CSRF token — created once, embedded in every form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

class StockController
{
    private StockModel $model;

    public function __construct()
    {
        $this->model = new StockModel();
    }

    /* ---------------------------------------------------------------------- */
    /* PAGE RENDERING                                                        */
    /* ---------------------------------------------------------------------- */

    /** Renders the main dashboard view. */
    public function renderDashboard(): void
    {
        $products        = $this->model->getProductStockSummary();
        $zones           = $this->model->getZones();
        $zoneStock       = $this->model->getStockGroupedByProduct();
        $lowStock        = $this->model->getLowStockProducts();
        $recentMovements = $this->model->getRecentMovements(8);
        $totalValue      = $this->model->getTotalInventoryValue();
        $totalSkus       = $this->model->getTotalSkuCount();
        $movementCount   = $this->model->getMovementCount();

        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/dashboard.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /* ---------------------------------------------------------------------- */
    /* JSON ENDPOINTS (Ajax)                                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * Dispatches an Ajax action. Every response is JSON with explicit
     * Content-Type and Cache-Control headers.
     */
    public function handleAjax(string $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        switch ($action) {
            case 'transfer':
                $this->transfer();
                break;
            case 'login':
                $this->login();
                break;
            case 'logout':
                $this->logout();
                break;
            default:
                http_response_code(400);
                echo json_encode(
                    ['status' => 'error', 'message' => 'Unknown action.'],
                    JSON_UNESCAPED_SLASHES
                );
        }
    }

    /**
     * Ajax action: transfer stock between zones (POST only).
     */
    private function transfer(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST method required.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        if (!$this->csrfValid()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Please log in to transfer stock. (Demo: admin@stockpulse.local / admin1234)',
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $quantity  = filter_input(INPUT_POST, 'quantity',  FILTER_VALIDATE_INT);
        $fromZone  = filter_input(INPUT_POST, 'from_zone', FILTER_SANITIZE_SPECIAL_CHARS);
        $toZone    = filter_input(INPUT_POST, 'to_zone',   FILTER_SANITIZE_SPECIAL_CHARS);

        if (!$productId || !$quantity || $fromZone === null || $toZone === null || $fromZone === '' || $toZone === '') {
            http_response_code(422);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Please provide a valid product, both zones and a positive quantity.',
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        $result = $this->model->transferStock(
            (int) $productId,
            (string) $fromZone,
            (string) $toZone,
            (int) $quantity,
            (int) $_SESSION['user_id']
        );

        http_response_code($result['status'] === 'success' ? 200 : 400);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ajax action: authenticate a user with email + password (POST only).
     */
    private function login(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST method required.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        if (!$this->csrfValid()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (!$email || !is_string($password) || $password === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'A valid email and password are required.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $user = $this->model->getUserByEmail($email);

        // password_verify() is timing-attack safe and handles bcrypt hashes.
        if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        // Regenerate the session ID to prevent session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['user_id'];
        $_SESSION['full_name'] = (string) $user['full_name'];
        $_SESSION['role']      = (string) $user['role'];

        echo json_encode([
            'status'  => 'success',
            'message' => 'Welcome, ' . $user['full_name'] . '!',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ajax action: destroy the current session (POST only).
     */
    private function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        echo json_encode([
            'status'  => 'success',
            'message' => 'You have been logged out.',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Constant-time comparison of the submitted CSRF token against the
     * token stored in the session.
     */
    private function csrfValid(): bool
    {
        $token = $_POST['csrf_token'] ?? '';
        return is_string($token)
            && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
    }
}

/*
 * ----------------------------------------------------------------------
 * Direct-access dispatch (JSON API mode)
 *
 * Reached when the browser posts straight to
 * controllers/StockController.php?action=transfer (see public/js/stock.js).
 * public/index.php defines STOCKPULSE_FRONT_CONTROLLER before including
 * this file, so the block below is skipped in page-rendering mode.
 * ----------------------------------------------------------------------
 */
if (!defined('STOCKPULSE_FRONT_CONTROLLER')) {
    $action = $_GET['action'] ?? '';

    if (in_array($action, ['transfer', 'login', 'logout'], true)) {
        (new StockController())->handleAjax((string) $action);
        exit;
    }

    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.'], JSON_UNESCAPED_SLASHES);
    exit;
}
