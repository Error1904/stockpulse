<?php
declare(strict_types=1);

/**
 * StockPulse — Database (Singleton PDO connection)
 * ---------------------------------------------------------------------------
 * Guarantees exactly one PDO instance per request lifecycle. The constructor is
 * private so the class can only be instantiated through getInstance(), and
 * cloning / unserialization are disabled to protect the singleton contract.
 * ---------------------------------------------------------------------------
 */
class Database
{
    /** @var self|null Holds the single instance of this class. */
    private static ?self $instance = null;

    /** @var PDO The shared database connection. */
    private PDO $connection;

    /**
     * Connection settings — edit these for your environment.
     * (In production, prefer environment variables or a config file outside
     *  the document root so credentials never end up in version control.)
     */
    private array $settings = [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => 'stockpulse',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ];

    /** Private constructor — prevents external instantiation. */
    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->settings['host'],
            $this->settings['port'],
            $this->settings['dbname'],
            $this->settings['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw PDOExceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Fetch associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                   // Use REAL prepared statements (no SQL injection emulation)
            PDO::ATTR_PERSISTENT         => false,                   // Avoid stale-state surprises in shared hosting
        ];

        try {
            $this->connection = new PDO($dsn, $this->settings['username'], $this->settings['password'], $options);
        } catch (PDOException $e) {
            // Log the detail server-side, never expose it (or the credentials) to the client.
            error_log('[StockPulse] Database connection failed: ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode([
                'status'  => 'error',
                'message' => 'Service temporarily unavailable. Please try again later.',
            ], JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Returns the shared instance, creating it lazily on first use.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the active PDO connection.
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /** Singleton safeguards. */
    private function __clone() {}
    public function __wakeup() {}
}
