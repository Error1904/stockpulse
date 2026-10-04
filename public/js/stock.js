/**
 * StockPulse — stock.js
 * ---------------------------------------------------------------------------
 * jQuery Ajax handlers for stock transfers, staff login and logout.
 * Every request is asynchronous (no full page reload) and talks to
 * controllers/StockController.php, which responds with structured JSON:
 *
 *   { "status": "success" | "error", "message": "..." }
 * ---------------------------------------------------------------------------
 */
(function ($) {
    'use strict';

    /* Endpoints injected by views/layout/header.php (with sane fallbacks). */
    var API_URL    = window.STOCKPULSE_API_URL  || '../controllers/StockController.php';
    var BASE_URL   = window.STOCKPULSE_BASE_URL || '';
    var CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content') || '';

    /**
     * POSTs url-encoded data and routes the decoded JSON response to the
     * supplied handlers:
     *
     *   postJson('transfer', data, {
     *       success: function (response) { ... },
     *       error:   function (xhr) { ... },
     *       complete: function () { ... }        // optional, fires once
     *   });
     *
     * The primary target is the direct controller endpoint
     * (StockController.php?action=<action>). When the server is
     * configured with public/ as the document root that URL is not
     * reachable (404), so the request is transparently retried
     * against the front-controller route (/<action>) served by
     * public/index.php.
     *
     * @param {string} action  transfer | login | logout
     * @param {string} data    url-encoded payload (incl. csrf_token)
     * @param {object} handlers
     */
    function postJson(action, data, handlers) {
        var urls = [API_URL + '?action=' + action, BASE_URL + '/' + action];
        var index = 0;
        var settled = false;

        function finish() {
            if (!settled) {
                settled = true;
                if (typeof handlers.complete === 'function') {
                    handlers.complete();
                }
            }
        }

        function send(url) {
            $.ajax({
                url: url,
                method: 'POST',
                data: data,
                dataType: 'json'
            }).done(function (response) {
                finish();
                handlers.success(response);
            }).fail(function (xhr) {
                if (xhr.status === 404 && index === 0) {
                    index = 1;
                    send(urls[index]); // transparent front-controller fallback
                    return;
                }
                finish();
                handlers.error(xhr);
            });
        }

        send(urls[0]);
    }

    /**
     * Renders a dismissible Bootstrap alert at the top of the dashboard.
     *
     * @param {'success'|'danger'} type  Bootstrap alert variant
     * @param {string}             msg   Message returned by the JSON payload
     */
    function showAlert(type, msg) {
        var icon = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
        var html =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                '<i class="bi ' + icon + ' me-2"></i>' + msg +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '</div>';
        $('#alertContainer').prepend(html);

        // Keep the notice stack tidy — auto-dismiss success messages.
        if (type === 'success') {
            setTimeout(function () {
                $('#alertContainer .alert').first().alert('close');
            }, 4000);
        }
    }

    /**
     * Serialises a form and appends the per-session CSRF token so the
     * server can validate the request origin.
     */
    function formDataWithCsrf($form) {
        var data = $form.serializeArray();
        data.push({ name: 'csrf_token', value: CSRF_TOKEN });
        return $.param(data);
    }

    /** Extracts a JSON error message from a failed jqXHR, if present. */
    function errorMessage(xhr) {
        var fallback = 'Network error — the request could not be completed.';
        try {
            var response = JSON.parse(xhr.responseText);
            if (response && response.message) {
                return response.message;
            }
        } catch (err) {
            /* keep the default fallback message */
        }
        return fallback;
    }

    /** Toggles a form's submit button between idle and busy states. */
    function setSubmitButton($form, busy, idleHtml) {
        $form.find('button[type="submit"]')
            .prop('disabled', busy)
            .html(busy
                ? '<span class="spinner-border spinner-border-sm me-1"></span>Processing…'
                : idleHtml);
    }

    $(function () {

        /* ---------------------------------------------------------------- */
        /* STOCK TRANSFER — intercept the modal form and POST via Ajax */
        /* ---------------------------------------------------------------- */
        $('#transferForm').on('submit', function (e) {
            e.preventDefault(); // Stop the classic (full-reload) form post.

            var $form = $(this);
            var IDLE  = '<i class="bi bi-send me-1"></i>Transfer Stock';

            setSubmitButton($form, true, IDLE);

            postJson('transfer', formDataWithCsrf($form), {
                success: function (response) {
                    if (response.status === 'success') {
                        showAlert('success', response.message);
                        $form.trigger('reset');
                        var modal = bootstrap.Modal.getInstance($form.closest('.modal'));
                        if (modal) { modal.hide(); }
                        // Refresh the tables so totals reflect the transfer.
                        setTimeout(function () { location.reload(); }, 900);
                    } else {
                        showAlert('danger', response.message || 'Transfer failed.');
                    }
                },
                error: function (xhr) {
                    showAlert('danger', errorMessage(xhr));
                },
                complete: function () {
                    setSubmitButton($form, false, IDLE);
                }
            });
        });

        /* Pre-select the clicked product inside the transfer modal. */
        $(document).on('click', '.transferBtn', function () {
            $('#transferProduct').val($(this).data('product-id'));
        });

        /* ---------------------------------------------------------------- */
        /* LOGIN — authenticate via Ajax without reloading the page */
        /* ---------------------------------------------------------------- */
        $('#loginForm').on('submit', function (e) {
            e.preventDefault();

            var $form = $(this);
            var IDLE  = '<i class="bi bi-box-arrow-in-right me-1"></i>Login';

            setSubmitButton($form, true, IDLE);

            postJson('login', formDataWithCsrf($form), {
                success: function (response) {
                    showAlert(response.status === 'success' ? 'success' : 'danger', response.message);
                    if (response.status === 'success') {
                        setTimeout(function () { location.reload(); }, 900);
                    }
                },
                error: function (xhr) {
                    showAlert('danger', errorMessage(xhr));
                },
                complete: function () {
                    setSubmitButton($form, false, IDLE);
                }
            });
        });

        /* ---------------------------------------------------------------- */
        /* LOGOUT — destroy the session via Ajax then reload */
        /* ---------------------------------------------------------------- */
        $('#logoutBtn').on('click', function () {
            $.post(API_URL + '?action=logout', { csrf_token: CSRF_TOKEN })
                .done(function (response) {
                    showAlert('success', response.message || 'Logged out.');
                    setTimeout(function () { location.reload(); }, 900);
                })
                .fail(function () {
                    location.reload();
                });
        });
    });
})(jQuery);
