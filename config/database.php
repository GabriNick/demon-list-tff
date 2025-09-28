<?php
// config/database.php
// Configuración de base de datos para hosting compartido

class Database {
    // Configuración típica de hosting compartido
    private $host = "localhost"; // O la IP que te proporcione tu hosting
    private $db_name = "tu_usuario_tff_demon_list"; // Formato típico: usuario_nombredb
    private $username = "tu_usuario_mysql"; // Tu usuario de MySQL del hosting
    private $password = "tu_password_mysql"; // Tu contraseña de MySQL
    private $charset = "utf8mb4";
    
    public $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            die("Database connection failed");
        }
        
        return $this->conn;
    }
}

// config/config.php
// Configuración general del sistema

// URL base de tu sitio (cambia por tu dominio)
define('BASE_URL', 'https://tudominio.com');
define('API_BASE_URL', BASE_URL . '/api');

// Discord OAuth (obtén estos datos de Discord Developer Portal)
define('DISCORD_CLIENT_ID', 'tu_discord_client_id');
define('DISCORD_CLIENT_SECRET', 'tu_discord_client_secret');
define('DISCORD_REDIRECT_URI', BASE_URL . '/auth/callback.php');

// JWT Secret (cambia por una clave segura)
define('JWT_SECRET', 'tu_clave_secreta_muy_segura_de_32_caracteres_minimo');

// Configuraciones adicionales
define('RECORDS_PER_PAGE', 50);
define('MAX_UPLOAD_SIZE', 10485760); // 10MB
define('ALLOWED_VIDEO_DOMAINS', ['youtube.com', 'youtu.be']);

// Timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Error reporting (desactivar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Headers para CORS
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
?>

<?php
// api/index.php
// Router principal de la API

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Obtener la ruta solicitada
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$path = str_replace('/api', '', $path);
$method = $_SERVER['REQUEST_METHOD'];

// Inicializar base de datos
$database = new Database();
$db = $database->getConnection();

// Router simple
switch ($path) {
    case '/health':
        handleHealth();
        break;
        
    case '/demons':
        if ($method === 'GET') {
            handleGetDemons($db);
        }
        break;
        
    case (preg_match('/^\/demons\/(\d+)$/', $path, $matches) ? true : false):
        if ($method === 'GET') {
            handleGetDemon($db, $matches[1]);
        }
        break;
        
    case '/auth/discord/callback':
        if ($method === 'POST') {
            handleDiscordCallback($db);
        }
        break;
        
    case (preg_match('/^\/users\/(\d+)$/', $path, $matches) ? true : false):
        if ($method === 'GET') {
            handleGetUser($db, $matches[1]);
        }
        break;
        
    case '/records/submit':
        if ($method === 'POST') {
            $user = authenticateUser($db);
            if ($user) {
                handleSubmitRecord($db, $user);
            } else {
                sendResponse(401, ['error' => 'Authentication required']);
            }
        }
        break;
        
    case '/stats/global':
        if ($method === 'GET') {
            handleGlobalStats($db);
        }
        break;
        
    case '/stats/countries':
        if ($method === 'GET') {
            handleCountryStats($db);
        }
        break;
        
    case '/stats/clans':
        if ($method === 'GET') {
            handleClanStats($db);
        }
        break;
        
    // Admin routes
    case '/admin/levels':
        $user = authenticateUser($db);
        if ($user && $user['is_admin']) {
            handleAdminLevels($db, $method);
        } else {
            sendResponse(403, ['error' => 'Admin access required']);
        }
        break;
        
    case '/admin/records':
        $user = authenticateUser($db);
        if ($user && $user['is_admin']) {
            handleAdminRecords($db, $method);
        } else {
            sendResponse(403, ['error' => 'Admin access required']);
        }
        break;
        
    case (preg_match('/^\/admin\/records\/(\d+)\/(approve|reject)$/', $path, $matches) ? true : false):
        $user = authenticateUser($db);
        if ($user && $user['is_admin']) {
            handleRecordAction($db, $matches[1], $matches[2], $user);
        } else {
            sendResponse(403, ['error' => 'Admin access required']);
        }
        break;
        
    default:
        sendResponse(404, ['error' => 'Endpoint not found']);
        break;
}

// Funciones del API

function handleHealth() {
    sendResponse(200, [
        'status' => 'OK',
        'timestamp' => date('c'),
        'version' => '1.0.0'
    ]);
}

function handleGetDemons($db) {
    try {
        $start = intval($_GET['start'] ?? 1);
        $end = isset($_GET['end']) ? intval($_GET['end']) : null;
        $submittable = $_GET['submittable'] ?? false;
        
        $sql = "SELECT * FROM demons WHERE position >= ?";
        $params = [$start];
        
        if ($end) {
            $sql .= " AND position <= ?";
            $params[] = $end;
        }
        
        if ($submittable === 'true') {
            $sql .= " AND position <= 150";
        }
        
        $sql .= " ORDER BY position ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $demons = $stmt->fetchAll();
        
        // Calcular puntos
        foreach ($demons as &$demon) {
            $demon['points'] = calculatePoints($demon['position'], 100);
        }
        
        sendResponse(200, $demons);
    } catch (Exception $e) {
        error_log("Error getting demons: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleGetDemon($db, $demonId) {
    try {
        $stmt = $db->prepare("SELECT * FROM demons WHERE id = ?");
        $stmt->execute([$demonId]);
        $demon = $stmt->fetch();
        
        if (!$demon) {
            sendResponse(404, ['error' => 'Demon not found']);
            return;
        }
        
        $demon['points'] = calculatePoints($demon['position'], 100);
        
        // Obtener records del demon
        $stmt = $db->prepare("
            SELECT r.*, u.username, u.clan, u.country 
            FROM records r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.demon_id = ? AND r.status = 'approved'
            ORDER BY r.percentage DESC, r.submitted_at ASC
        ");
        $stmt->execute([$demonId]);
        $demon['records'] = $stmt->fetchAll();
        
        sendResponse(200, $demon);
    } catch (Exception $e) {
        error_log("Error getting demon: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleDiscordCallback($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $code = $input['code'] ?? '';
        
        if (!$code) {
            sendResponse(400, ['error' => 'Missing authorization code']);
            return;
        }
        
        // Intercambiar código por token
        $tokenData = [
            'client_id' => DISCORD_CLIENT_ID,
            'client_secret' => DISCORD_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => DISCORD_REDIRECT_URI
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $tokenResponse = curl_exec($ch);
        $tokenInfo = json_decode($tokenResponse, true);
        curl_close($ch);
        
        if (!isset($tokenInfo['access_token'])) {
            sendResponse(400, ['error' => 'Failed to get access token']);
            return;
        }
        
        // Obtener info del usuario de Discord
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/users/@me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $tokenInfo['access_token']
        ]);
        
        $userResponse = curl_exec($ch);
        $discordUser = json_decode($userResponse, true);
        curl_close($ch);
        
        if (!isset($discordUser['id'])) {
            sendResponse(400, ['error' => 'Failed to get user info']);
            return;
        }
        
        // Verificar si el usuario ya existe
        $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
        $stmt->execute([$discordUser['id']]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            // Actualizar usuario existente
            $stmt = $db->prepare("
                UPDATE users SET 
                    username = ?, 
                    discriminator = ?, 
                    avatar = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE discord_id = ?
            ");
            $avatar = isset($discordUser['avatar']) ? 
                "https://cdn.discordapp.com/avatars/{$discordUser['id']}/{$discordUser['avatar']}.png" : null;
            
            $stmt->execute([
                $discordUser['username'],
                $discordUser['discriminator'] ?? '0000',
                $avatar,
                $discordUser['id']
            ]);
            
            $user = $existingUser;
        } else {
            // Crear nuevo usuario
            $avatar = isset($discordUser['avatar']) ? 
                "https://cdn.discordapp.com/avatars/{$discordUser['id']}/{$discordUser['avatar']}.png" : null;
                
            $stmt = $db->prepare("
                INSERT INTO users (discord_id, username, discriminator, avatar) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $discordUser['id'],
                $discordUser['username'],
                $discordUser['discriminator'] ?? '0000',
                $avatar
            ]);
            
            $user = [
                'id' => $db->lastInsertId(),
                'discord_id' => $discordUser['id'],
                'username' => $discordUser['username'],
                'discriminator' => $discordUser['discriminator'] ?? '0000',
                'avatar' => $avatar,
                'clan' => null,
                'country' => 'US',
                'is_admin' => false
            ];
        }
        
        // Generar JWT token
        $token = generateJWT(['userId' => $user['id'], 'discordId' => $user['discord_id']]);
        
        $response = $user;
        $response['token'] = $token;
        
        sendResponse(200, $response);
    } catch (Exception $e) {
        error_log("Discord auth error: " . $e->getMessage());
        sendResponse(500, ['error' => 'Authentication failed']);
    }
}

function handleGetUser($db, $userId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                u.*,
                us.total_points,
                us.completed_count,
                us.progress_count,
                us.global_rank,
                c.flag_emoji
            FROM users u
            LEFT JOIN user_stats us ON u.id = us.id
            LEFT JOIN countries c ON u.country = c.code
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendResponse(404, ['error' => 'User not found']);
            return;
        }
        
        // Obtener records del usuario
        $stmt = $db->prepare("
            SELECT r.*, d.name as demon_name, d.position
            FROM records r
            JOIN demons d ON r.demon_id = d.id
            WHERE r.user_id = ? AND r.status = 'approved'
            ORDER BY d.position ASC
        ");
        $stmt->execute([$userId]);
        $user['records'] = $stmt->fetchAll();
        
        sendResponse(200, $user);
    } catch (Exception $e) {
        error_log("Error getting user: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleSubmitRecord($db, $user) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $levelId = $input['levelId'] ?? null;
        $percentage = intval($input['percentage'] ?? 0);
        $videoLink = $input['videoLink'] ?? '';
        $rawFootage = $input['rawFootage'] ?? null;
        $notes = $input['notes'] ?? null;
        
        // Validaciones
        if (!$levelId || !$percentage || !$videoLink) {
            sendResponse(400, ['error' => 'Missing required fields']);
            return;
        }
        
        if ($percentage < 1 || $percentage > 100) {
            sendResponse(400, ['error' => 'Invalid percentage']);
            return;
        }
        
        // Verificar que el demon existe y es submittable
        $stmt = $db->prepare("SELECT * FROM demons WHERE id = ? AND position <= 150");
        $stmt->execute([$levelId]);
        $demon = $stmt->fetch();
        
        if (!$demon) {
            sendResponse(400, ['error' => 'Invalid demon or demon not accepting records']);
            return;
        }
        
        // Verificar porcentaje mínimo
        if ($percentage < $demon['min_percent']) {
            sendResponse(400, ['error' => "Minimum percentage for this level is {$demon['min_percent']}%"]);
            return;
        }
        
        // Verificar si ya tiene un record
        $stmt = $db->prepare("SELECT * FROM records WHERE user_id = ? AND demon_id = ?");
        $stmt->execute([$user['id'], $levelId]);
        
        if ($stmt->fetch()) {
            sendResponse(400, ['error' => 'You already have a record for this demon']);
            return;
        }
        
        // Validar URL de YouTube
        if (!isValidYouTubeUrl($videoLink)) {
            sendResponse(400, ['error' => 'Invalid YouTube URL']);
            return;
        }
        
        // Insertar record
        $stmt = $db->prepare("
            INSERT INTO records (user_id, demon_id, percentage, video_url, raw_footage_url, notes) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $levelId, $percentage, $videoLink, $rawFootage, $notes]);
        
        sendResponse(200, ['message' => 'Record submitted successfully']);
    } catch (Exception $e) {
        error_log("Error submitting record: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleGlobalStats($db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT u.id) as total_players,
                COUNT(DISTINCT r.id) as total_records,
                COALESCE(SUM(r.points), 0) as total_points,
                COALESCE(AVG(r.points), 0) as average_points
            FROM users u
            LEFT JOIN records r ON u.id = r.user_id AND r.status = 'approved'
            WHERE u.is_banned = FALSE
        ");
        $stmt->execute();
        $stats = $stmt->fetch();
        
        sendResponse(200, $stats);
    } catch (Exception $e) {
        error_log("Error getting global stats: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleCountryStats($db) {
    try {
        $stmt = $db->prepare("SELECT * FROM country_stats ORDER BY total_points DESC LIMIT 50");
        $stmt->execute();
        $countries = $stmt->fetchAll();
        
        sendResponse(200, $countries);
    } catch (Exception $e) {
        error_log("Error getting country stats: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleClanStats($db) {
    try {
        $stmt = $db->prepare("SELECT * FROM clan_stats ORDER BY total_points DESC LIMIT 50");
        $stmt->execute();
        $clans = $stmt->fetchAll();
        
        sendResponse(200, $clans);
    } catch (Exception $e) {
        error_log("Error getting clan stats: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

// Funciones administrativas
function handleAdminLevels($db, $method) {
    try {
        if ($method === 'GET') {
            $stmt = $db->prepare("SELECT * FROM demons ORDER BY position ASC");
            $stmt->execute();
            $demons = $stmt->fetchAll();
            sendResponse(200, $demons);
        }
    } catch (Exception $e) {
        error_log("Error in admin levels: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleAdminRecords($db, $method) {
    try {
        if ($method === 'GET') {
            $stmt = $db->prepare("
                SELECT 
                    r.*,
                    u.username as user_name,
                    d.name as demon_name,
                    d.position as demon_position
                FROM records r
                JOIN users u ON r.user_id = u.id
                JOIN demons d ON r.demon_id = d.id
                WHERE r.status = 'pending'
                ORDER BY r.submitted_at ASC
            ");
            $stmt->execute();
            $records = $stmt->fetchAll();
            sendResponse(200, $records);
        }
    } catch (Exception $e) {
        error_log("Error in admin records: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}

function handleRecordAction($db, $recordId, $action, $user) {
    try {
        if ($action === 'approve') {
            // Obtener info del record para calcular puntos
            $stmt = $db->prepare("
                SELECT r.*, d.position 
                FROM records r 
                JOIN demons d ON r.demon_id = d.id 
                WHERE r.id = ?
            ");
            $stmt->execute([$recordId]);
            $record = $stmt->fetch();
            
            if (!$record) {
                sendResponse(404, ['error' => 'Record not found']);
                return;
            }
            
            $points = calculatePoints($record['position'], $record['percentage']);
            
            $stmt = $db->prepare("
                UPDATE records 
                SET status = 'approved', points = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$points, $user['id'], $recordId]);
            
        } elseif ($action === 'reject') {
            $input = json_decode(file_get_contents('php://input'), true);
            $reason = $input['reason'] ?? 'No reason provided';
            
            $stmt = $db->prepare("
                UPDATE records 
                SET status = 'rejected', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP,
                    notes = CONCAT(COALESCE(notes, ''), ' [REJECTED: ', ?, ']')
                WHERE id = ?
            ");
            $stmt->execute([$user['id'], $reason, $recordId]);
        }
        
        sendResponse(200, ['message' => 'Record ' . $action . 'd successfully']);
    } catch (Exception $e) {
        error_log("Error in record action: " . $e->getMessage());
        sendResponse(500, ['error' => 'Internal server error']);
    }
}
?>

<?php
// includes/functions.php
// Funciones auxiliares

function sendResponse($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function calculatePoints($position, $percentage) {
    if ($percentage < 100) return 0;
    
    if ($position <= 75) {
        // Main list
        return round(1000 * pow(0.99, $position - 1), 2);
    } elseif ($position <= 150) {
        // Extended list
        return round(500 * pow(0.98, $position - 76), 2);
    }
    
    return 0; // Legacy list
}

function isValidYouTubeUrl($url) {
    $pattern = '/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+/';
    return preg_match($pattern, $url);
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function logActivity($db, $userId, $action, $details = []) {
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (admin_id, action, target_type, target_id, new_data, created_at) 
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $userId,
            $action,
            $details['type'] ?? 'unknown',
            $details['id'] ?? 0,
            json_encode($details)
        ]);
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}
?>

<?php
// includes/auth.php
// Sistema de autenticación

require_once 'jwt.php';

function authenticateUser($db) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return null;
    }
    
    $token = $matches[1];
    
    try {
        $payload = validateJWT($token);
        
        if (!$payload || !isset($payload['userId'])) {
            return null;
        }
        
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_banned = FALSE");
        $stmt->execute([$payload['userId']]);
        $user = $stmt->fetch();
        
        return $user ?: null;
    } catch (Exception $e) {
        error_log("Auth error: " . $e->getMessage());
        return null;
    }
}

function requireAuth($db) {
    $user = authenticateUser($db);
    if (!$user) {
        sendResponse(401, ['error' => 'Authentication required']);
    }
    return $user;
}

function requireAdmin($db) {
    $user = requireAuth($db);
    if (!$user['is_admin']) {
        sendResponse(403, ['error' => 'Admin privileges required']);
    }
    return $user;
}
?>

<?php
// includes/jwt.php
// Implementación simple de JWT para PHP

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

function generateJWT($payload) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload['exp'] = time() + (7 * 24 * 60 * 60); // 7 días
    $payload['iat'] = time();
    $payload = json_encode($payload);
    
    $headerEncoded = base64UrlEncode($header);
    $payloadEncoded = base64UrlEncode($payload);
    
    $signature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);
    
    return $headerEncoded . "." . $payloadEncoded . "." . $signatureEncoded;
}

function validateJWT($jwt) {
    $parts = explode('.', $jwt);
    
    if (count($parts) !== 3) {
        return false;
    }
    
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    
    $signature = base64UrlDecode($signatureEncoded);
    $expectedSignature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, JWT_SECRET, true);
    
    if (!hash_equals($signature, $expectedSignature)) {
        return false;
    }
    
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    
    if ($payload['exp'] < time()) {
        return false; // Token expirado
    }
    
    return $payload;
}
?>