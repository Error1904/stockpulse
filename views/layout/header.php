<?php
/**
 * StockPulse — page header (layout)
 * Renders <head>, Bootstrap 5 navigation and opens <main>.
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>StockPulse — Smart Warehouse &amp; Stock Movement Tracker</title>

    <!-- Bootstrap 5 CSS + Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- StockPulse custom styles -->
    <link href="<?= BASE_URL ?>/css/style.css" rel="stylesheet">

    <!-- CSRF token + Ajax endpoint consumed by public/js/stock.js -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <script>
        // Resolves to the project-root location of controllers/StockController.php
        // and to the base URL of the public/ folder (front-controller routes).
        window.STOCKPULSE_API_URL  = '<?= BASE_URL ?>/../controllers/StockController.php';
        window.STOCKPULSE_BASE_URL = '<?= BASE_URL ?>';
    </script>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- ============================ Navbar ============================ -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/">
            <i class="bi bi-box-seam-fill me-1"></i>StockPulse
        </a>
        <span class="navbar-text d-none d-md-inline opacity-75">
            Smart Warehouse &amp; Stock Movement Tracker
        </span>
        <div class="d-flex align-items-center gap-2">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="navbar-text me-1">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>
                    <span class="badge bg-light text-primary ms-1 text-capitalize">
                        <?= htmlspecialchars($_SESSION['role'] ?? 'user', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </span>
                <button id="logoutBtn" type="button" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i>Logout
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-light btn-sm"
                        data-bs-toggle="modal" data-bs-target="#loginModal">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Login
                </button>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="container-fluid flex-grow-1 py-4">
