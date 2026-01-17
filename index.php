<?php

require_once __DIR__ . "/vendor/autoload.php";

use App\Controllers\TestController;
use PDO;

try {
// Configure logging - Warning and Error only
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
ini_set('error_reporting', E_WARNING | E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR);
ini_set('display_errors', 0); // Don't display errors, only log them

// Create logs directory if it doesn't exist
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Global error and exception handlers for runtime error reporting
function extractBoardId() {
    // Try query parameter
    if (isset($_GET['boardId']) && !empty($_GET['boardId'])) {
        return $_GET['boardId'];
    }
    
    // Try header
    $headers = getallheaders();
    if (isset($headers['X-Board-Id']) && !empty($headers['X-Board-Id'])) {
        return $headers['X-Board-Id'];
    }
    
    // Try environment variable
    $boardId = getenv('BOARD_ID');
    if ($boardId) {
        return $boardId;
    }
    
    // Try to extract from hostname (Railway pattern: webapi{boardId}.up.railway.app - no hyphen)
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if (preg_match('/webapi([a-f0-9]{24})/i', $host, $matches)) {
        return $matches[1];
    }
    
    // Try to extract from RUNTIME_ERROR_ENDPOINT_URL if it contains boardId pattern
    $endpointUrl = getenv('RUNTIME_ERROR_ENDPOINT_URL') ?: '';
    if (preg_match('/webapi([a-f0-9]{24})/i', $endpointUrl, $matches)) {
        return $matches[1];
    }
    
    return null;
}

function sendErrorToEndpoint($endpointUrl, $boardId, $exception) {
    // Run in background (fire and forget) using file_get_contents with stream context
    $payload = json_encode([
        'boardId' => $boardId,
        'timestamp' => gmdate('c'),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'stackTrace' => $exception->getTraceAsString(),
        'message' => $exception->getMessage(),
        'exceptionType' => get_class($exception),
        'requestPath' => $_SERVER['REQUEST_URI'] ?? '/',
        'requestMethod' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ];
    
    // Fire and forget - don't wait for response
    @file_get_contents($endpointUrl, false, stream_context_create($opts));
}

// Set exception handler
set_exception_handler(function ($exception) {
    error_log('[EXCEPTION HANDLER] Unhandled exception: ' . $exception->getMessage());
    
    $boardId = extractBoardId();
    error_log('[EXCEPTION HANDLER] Extracted boardId: ' . ($boardId ?? 'NULL'));
    
    $runtimeErrorEndpointUrl = getenv('RUNTIME_ERROR_ENDPOINT_URL');
    if ($runtimeErrorEndpointUrl) {
        error_log('[EXCEPTION HANDLER] Sending error to endpoint: ' . $runtimeErrorEndpointUrl);
        sendErrorToEndpoint($runtimeErrorEndpointUrl, $boardId, $exception);
    } else {
        error_log('[EXCEPTION HANDLER] RUNTIME_ERROR_ENDPOINT_URL is not set - skipping error reporting');
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'An error occurred while processing your request',
        'message' => $exception->getMessage()
    ]);
    exit;
});

// Set error handler for non-fatal errors
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // Don't handle if error reporting is disabled for this severity
    }
    
    // Only convert to exception for non-fatal errors (fatal errors are handled by shutdown function)
    if ($severity !== E_PARSE && $severity !== E_CORE_ERROR && $severity !== E_COMPILE_ERROR) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    
    return false; // Let PHP handle fatal errors normally (they'll be caught by shutdown function)
}, E_WARNING | E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR);

// Register shutdown function to catch fatal errors (including parse errors)
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_ERROR])) {
        error_log('[FATAL ERROR HANDLER] Fatal error occurred: ' . $error['message']);
        
        $boardId = extractBoardId();
        error_log('[FATAL ERROR HANDLER] Extracted boardId: ' . ($boardId ?? 'NULL'));
        
        $runtimeErrorEndpointUrl = getenv('RUNTIME_ERROR_ENDPOINT_URL');
        if ($runtimeErrorEndpointUrl) {
            error_log('[FATAL ERROR HANDLER] Sending error to endpoint: ' . $runtimeErrorEndpointUrl);
            
            // Create a synthetic exception for fatal errors
            $exception = new ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );
            
            sendErrorToEndpoint($runtimeErrorEndpointUrl, $boardId, $exception);
        } else {
            error_log('[FATAL ERROR HANDLER] RUNTIME_ERROR_ENDPOINT_URL is not set - skipping error reporting');
        }
        
        // Send error response
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'A fatal error occurred',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);
        }
    }
});

// Get request method and path first (before database connection)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle routes that don't require database connection first
if ($path === '/swagger') {
    // Swagger UI endpoint - serve interactive Swagger UI HTML page
    header('Content-Type: text/html');
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Backend API - Swagger UI</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.9.0/swagger-ui.css" />
    <style>
        html { box-sizing: border-box; overflow: -moz-scrollbars-vertical; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin:0; background: #fafafa; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.9.0/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.9.0/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "/swagger.json",
                dom_id: "#swagger-ui",
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout"
            });
        };
    </script>
</body>
</html>';
    exit;
} elseif ($path === '/swagger.json') {
    // Swagger JSON endpoint - return OpenAPI spec as JSON
    header('Content-Type: application/json');
    echo json_encode([
        'openapi' => '3.0.0',
        'info' => [
            'title' => 'Backend API',
            'version' => '1.0.0',
            'description' => 'PHP Backend API Documentation'
        ],
        'paths' => [
            '/api/test' => [
                'get' => [
                    'summary' => 'Get all test projects',
                    'responses' => [
                        '200' => [
                            'description' => 'List of test projects',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/TestProjects']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'post' => [
                    'summary' => 'Create a new test project',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/TestProjectsInput']
                            ]
                        ]
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Created test project',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/TestProjects']
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            '/api/test/{id}' => [
                'get' => [
                    'summary' => 'Get test project by ID',
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer']
                        ]
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Test project found',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/TestProjects']
                                ]
                            ]
                        ],
                        '404' => ['description' => 'Project not found']
                    ]
                ],
                'put' => [
                    'summary' => 'Update test project',
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer']
                        ]
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/TestProjectsInput']
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => 'Updated test project'],
                        '404' => ['description' => 'Project not found']
                    ]
                ],
                'delete' => [
                    'summary' => 'Delete test project',
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer']
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => 'Deleted successfully'],
                        '404' => ['description' => 'Project not found']
                    ]
                ]
            ]
        ],
        'components' => [
            'schemas' => [
                'TestProjects' => [
                    'type' => 'object',
                    'properties' => [
                        'Id' => ['type' => 'integer'],
                        'Name' => ['type' => 'string']
                    ]
                ],
                'TestProjectsInput' => [
                    'type' => 'object',
                    'required' => ['Name'],
                    'properties' => [
                        'Name' => ['type' => 'string']
                    ]
                ]
            ]
        ]
    ], JSON_PRETTY_PRINT);
    exit;
} elseif ($path === '/' || $path === '') {
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Backend API is running',
        'status' => 'ok',
        'swagger' => '/swagger',
        'api' => '/api/test'
    ]);
    exit;
} elseif ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'Backend API'
    ]);
    exit;
}

// Routes that require database connection
try {
    // Parse DATABASE_URL
    $databaseUrl = getenv('DATABASE_URL');
    if (!$databaseUrl) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'DATABASE_URL environment variable not set']);
        exit;
    }

    // Parse PostgreSQL connection string
    // Use parse_url with PHP_URL_* components to ensure proper parsing
    $url = parse_url($databaseUrl);
    
    if ($url === false) {
        throw new Exception('Invalid DATABASE_URL format');
    }
    
    $host = $url['host'] ?? 'localhost';
    $port = isset($url['port']) ? (int)$url['port'] : 5432;
    
    // Extract database name from path - ensure full path is extracted
    // Database name is in the path component (e.g., /AppDB_69626b9aa83a298b692f8150)
    $dbPath = $url['path'] ?? '/postgres';
    // Remove leading slash - database name should not have leading slash
    $dbname = ltrim($dbPath, '/');
    // URL decode in case database name has encoded characters (though it shouldn't normally)
    $dbname = urldecode($dbname);
    // If path is empty after trimming, use default
    if (empty($dbname)) {
        $dbname = 'postgres';
    }
    
    // Extract and decode username and password
    $username = isset($url['user']) ? urldecode($url['user']) : 'postgres';
    $password = isset($url['pass']) ? urldecode($url['pass']) : '';
    
    // Build DSN string - PDO PostgreSQL DSN format uses semicolon-separated parameters, NOT query string
    // Format: pgsql:host=...;port=...;dbname=...;sslmode=require
    $dsn = "pgsql:host=" . $host . ";port=" . $port . ";dbname=" . $dbname;
    
    // Parse query parameters from original URL to check for sslmode
    $sslMode = 'require'; // Default to require for Neon
    if (isset($url['query']) && !empty($url['query'])) {
        parse_str($url['query'], $queryParams);
        if (isset($queryParams['sslmode'])) {
            $sslMode = $queryParams['sslmode'];
        }
    }
    
    // Add SSL mode as a semicolon-separated parameter (NOT as query string)
    $dsn .= ';sslmode=' . $sslMode;
    
    // Create PDO connection with error handling
    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        // Log connection details for debugging (without exposing password)
        error_log('Database connection failed. Host: ' . $host . ', Port: ' . $port . ', Database: ' . $dbname . ', User: ' . $username);
        throw $e;
    }
    
    // Create controller
    $controller = new TestController($pdo);
    header('Content-Type: application/json');
    
    // Route handling for API endpoints
    if ($path === '/api/test' || $path === '/api/test/') {
        if ($method === 'GET') {
            echo json_encode($controller->getAll());
            exit;
        } elseif ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            http_response_code(201);
            echo json_encode($controller->create($data));
            exit;
        }
    } elseif (preg_match('#^/api/test/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];
        
        if ($method === 'GET') {
            $result = $controller->getById($id);
            if ($result === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Project not found']);
            } else {
                echo json_encode($result);
            }
            exit;
        } elseif ($method === 'PUT') {
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $controller->update($id, $data);
            if ($result === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Project not found']);
            } else {
                echo json_encode($result);
            }
            exit;
        } elseif ($method === 'DELETE') {
            if ($controller->delete($id)) {
                echo json_encode(['message' => 'Deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Project not found']);
            }
            exit;
        }
    }
    
    // 404 Not Found for API routes
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    
} catch (Exception $e) {
    // Let the global exception handler handle it
    throw $e;
}
} catch (Throwable $startupEx) {
    // Startup error handler - catch errors during require_once or initialization
    error_log('[STARTUP ERROR] Application failed to start: ' . $startupEx->getMessage());
    
    // Send startup error to endpoint
    $runtimeErrorEndpointUrl = getenv('RUNTIME_ERROR_ENDPOINT_URL');
    $boardId = getenv('BOARD_ID');
    
    if ($runtimeErrorEndpointUrl) {
        $payload = json_encode([
            'boardId' => $boardId,
            'timestamp' => gmdate('c'),
            'file' => $startupEx->getFile(),
            'line' => $startupEx->getLine(),
            'stackTrace' => $startupEx->getTraceAsString(),
            'message' => $startupEx->getMessage(),
            'exceptionType' => get_class($startupEx),
            'requestPath' => 'STARTUP',
            'requestMethod' => 'STARTUP',
            'userAgent' => 'STARTUP_ERROR'
        ]);
        
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ];
        
        @file_get_contents($runtimeErrorEndpointUrl, false, stream_context_create($opts));
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Application failed to start',
        'message' => $startupEx->getMessage()
    ]);
    exit(1);
}
