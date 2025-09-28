<?php
// auth/callback.php
// Callback para Discord OAuth

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/jwt.php';

// Verificar que se recibió el código
$code = $_GET['code'] ?? '';

if (!$code) {
    // Redirigir al frontend con error
    header('Location: ' . BASE_URL . '?error=missing_code');
    exit;
}

try {
    // Intercambiar código por token de acceso
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: TFF-Demon-List/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $tokenResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to get access token from Discord');
    }
    
    $tokenInfo = json_decode($tokenResponse, true);
    
    if (!isset($tokenInfo['access_token'])) {
        throw new Exception('No access token in Discord response');
    }
    
    // Obtener información del usuario de Discord
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/users/@me');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $tokenInfo['access_token'],
        'User-Agent: TFF-Demon-List/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $userResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to get user info from Discord');
    }
    
    $discordUser = json_decode($userResponse, true);
    
    if (!isset($discordUser['id'])) {
        throw new Exception('No user ID in Discord response');
    }
    
    // Conectar a la base de datos
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar si el usuario ya existe
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
    $stmt->execute([$discordUser['id']]);
    $existingUser = $stmt->fetch();
    
    $avatar = null;
    if (isset($discordUser['avatar']) && $discordUser['avatar']) {
        $avatar = "https://cdn.discordapp.com/avatars/{$discordUser['id']}/{$discordUser['avatar']}.png?size=256";
    }
    
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
        $stmt->execute([
            $discordUser['username'],
            $discordUser['discriminator'] ?? '0',
            $avatar,
            $discordUser['id']
        ]);
        
        $user = $existingUser;
        $user['username'] = $discordUser['username'];
        $user['avatar'] = $avatar;
        
    } else {
        // Crear nuevo usuario
        $stmt = $db->prepare("
            INSERT INTO users (discord_id, username, discriminator, avatar, country) 
            VALUES (?, ?, ?, ?, 'US')
        ");
        $stmt->execute([
            $discordUser['id'],
            $discordUser['username'],
            $discordUser['discriminator'] ?? '0',
            $avatar
        ]);
        
        $user = [
            'id' => $db->lastInsertId(),
            'discord_id' => $discordUser['id'],
            'username' => $discordUser['username'],
            'discriminator' => $discordUser['discriminator'] ?? '0',
            'avatar' => $avatar,
            'clan' => null,
            'country' => 'US',
            'is_admin' => false,
            'is_banned' => false
        ];
    }
    
    // Generar JWT token
    $jwtPayload = [
        'userId' => $user['id'],
        'discordId' => $user['discord_id']
    ];
    $token = generateJWT($jwtPayload);
    
    // Crear respuesta con datos del usuario
    $userData = [
        'id' => $user['id'],
        'username' => $user['username'],
        'discriminator' => $user['discriminator'],
        'avatar' => $user['avatar'],
        'clan' => $user['clan'],
        'country' => $user['country'],
        'isAdmin' => (bool)$user['is_admin'],
        'token' => $token
    ];
    
    // Codificar datos para URL
    $encodedData = base64_encode(json_encode($userData));
    
    // Redirigir al frontend con los datos del usuario
    $redirectUrl = BASE_URL . "?auth=success&data=" . urlencode($encodedData);
    header('Location: ' . $redirectUrl);
    exit;
    
} catch (Exception $e) {
    error_log('Discord OAuth Error: ' . $e->getMessage());
    
    // Redirigir al frontend con error
    $errorMsg = 'Authentication failed. Please try again.';
    header('Location: ' . BASE_URL . '?error=' . urlencode($errorMsg));
    exit;
}
?>