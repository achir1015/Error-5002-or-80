<?php
// ============================================================
// includes/Auth.php  完整版（含 Google + Facebook OAuth）
// 在原有 Auth.php 基礎上，新增 Facebook 登入支援
// ============================================================

require_once __DIR__ . '/Database.php';

class Auth {

    // --------------------------------------------------------
    // 本地登入（Email + 密碼）— 原有，不變
    // --------------------------------------------------------
    public static function loginLocal($email, $password, $ip) {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM members WHERE LOWER(email) = LOWER(?) AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $member = $stmt->fetch();

        $success = false;
        $message = '';

        if ($member && $member['password_hash'] && password_verify($password, $member['password_hash'])) {
            $db->prepare('UPDATE members SET last_login = NOW() WHERE id = ?')->execute([$member['id']]);
            self::setSession($member);
            $success = true;
            $message = '登入成功';
        } else {
            $message = 'Email 或密碼錯誤';
        }

        self::logLogin($success ? ($member ? $member['id'] : null) : null, $email, $ip, 'local', $success);
        return ['success' => $success, 'message' => $message];
    }

    // --------------------------------------------------------
    // 會員註冊 — 原有，不變
    // --------------------------------------------------------
    public static function register($username, $email, $password, $role = 'guest') {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM members WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => '此 Email 或帳號名稱已被使用'];
        }
        if ($role === 'admin' && (!self::isLoggedIn() || self::currentUser()['role'] !== 'admin')) {
            $role = 'user';
        }
        $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $token = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO members (username, email, password_hash, display_name, role, verify_token) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$username, $email, $hash, $username, $role, $token]);
        return ['success' => true, 'message' => '註冊成功！', 'id' => $db->lastInsertId()];
    }

    // --------------------------------------------------------
    // Google OAuth — 原有，不變
    // --------------------------------------------------------
    public static function loginGoogle($googleUser, $ip) {
        $db       = Database::getInstance();
        $googleId = $googleUser['id'];
        $email    = $googleUser['email'];
        $name     = isset($googleUser['name'])    ? $googleUser['name']    : $email;
        $avatar   = isset($googleUser['picture']) ? $googleUser['picture'] : '';

        $stmt = $db->prepare('SELECT * FROM members WHERE google_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$googleId]);
        $member = $stmt->fetch();

        if (!$member) {
            $stmt = $db->prepare('SELECT * FROM members WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $member = $stmt->fetch();

            if ($member) {
                $db->prepare('UPDATE members SET google_id=?, google_email=?, avatar_url=?, last_login=NOW() WHERE id=?')
                   ->execute([$googleId, $email, $avatar, $member['id']]);
            } else {
                $username = 'g_' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($name)), 0, 20) . '_' . rand(100, 999);
                $db->prepare('INSERT INTO members (username, email, display_name, google_id, google_email, avatar_url, role, is_active, is_verified) VALUES (?, ?, ?, ?, ?, ?, "user", 1, 1)')
                   ->execute([$username, $email, $name, $googleId, $email, $avatar]);
                $stmt = $db->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
                $stmt->execute([$db->lastInsertId()]);
                $member = $stmt->fetch();
            }
        } else {
            $db->prepare('UPDATE members SET avatar_url=?, display_name=?, last_login=NOW() WHERE id=?')
               ->execute([$avatar, $name, $member['id']]);
        }

        self::setSession($member);
        self::logLogin($member['id'], $email, $ip, 'google', true);
        return ['success' => true, 'message' => 'Google 登入成功'];
    }

    public static function getGoogleAuthUrl() {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $params = http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public static function fetchGoogleUser($code) {
        $tokenRes = self::httpPostStream('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
        if (!$tokenRes) {
            $tokenRes = self::httpPostCurl('https://oauth2.googleapis.com/token', [
                'code'          => $code,
                'client_id'     => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'redirect_uri'  => GOOGLE_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
            ]);
        }
        if (!$tokenRes) return null;
        $token = json_decode($tokenRes, true);
        if (empty($token['access_token'])) return null;

        $userRes = self::httpGetStream('https://www.googleapis.com/oauth2/v2/userinfo', $token['access_token']);
        if (!$userRes) {
            $userRes = self::httpGetCurl('https://www.googleapis.com/oauth2/v2/userinfo', $token['access_token']);
        }
        return $userRes ? json_decode($userRes, true) : null;
    }

    // ========================================================
    // ✅ 新增：Facebook OAuth 登入
    // 邏輯與 Google 完全相同，只是 API 端點不同
    // ========================================================

    /**
     * 產生 Facebook 授權網址
     * 類比：就像 getGoogleAuthUrl()，但對象換成 Facebook
     */
    public static function getFacebookAuthUrl() {
        $state = bin2hex(random_bytes(16));
        $_SESSION['fb_oauth_state'] = $state; // 防 CSRF 用

        $params = http_build_query([
            'client_id'     => FACEBOOK_APP_ID,
            'redirect_uri'  => FACEBOOK_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'email,public_profile', // 只申請基本資料，不需要審核
            'state'         => $state,
        ]);
        return 'https://www.facebook.com/v22.0/dialog/oauth?' . $params;
    }

    /**
     * 用授權碼換取 Facebook 用戶資料
     * 與 fetchGoogleUser() 邏輯相同
     */
    public static function fetchFacebookUser($code) {
        // 步驟 1：用 code 換 access_token
        $tokenRes = self::httpGetStream(
            'https://graph.facebook.com/v22.0/oauth/access_token?' . http_build_query([
                'client_id'     => FACEBOOK_APP_ID,
                'client_secret' => FACEBOOK_APP_SECRET,
                'redirect_uri'  => FACEBOOK_REDIRECT_URI,
                'code'          => $code,
            ]),
            '' // GET 不需要 Bearer token
        );

        if (!$tokenRes) {
            error_log('[FB] token exchange failed');
            return null;
        }

        $token = json_decode($tokenRes, true);
        if (empty($token['access_token'])) {
            error_log('[FB] no access_token in response: ' . $tokenRes);
            return null;
        }

        $accessToken = $token['access_token'];

        // 步驟 2：用 token 取得用戶資料（id, name, email, picture）
        $userRes = self::httpGetStream(
            'https://graph.facebook.com/v22.0/me?' . http_build_query([
                'fields'       => 'id,name,email,picture.type(large)',
                'access_token' => $accessToken,
            ]),
            '' // 直接帶在 URL 參數，不用 Bearer header
        );

        if (!$userRes) {
            error_log('[FB] user info fetch failed');
            return null;
        }

        $fbUser = json_decode($userRes, true);

        // 整理成與 Google 格式相同的結構，方便 loginFacebook() 使用
        return [
            'id'      => $fbUser['id']   ?? null,
            'name'    => $fbUser['name'] ?? '',
            'email'   => $fbUser['email'] ?? '',           // 若 FB 帳號未綁 Email 可能為空
            'picture' => $fbUser['picture']['data']['url'] ?? '',
        ];
    }

    /**
     * Facebook 登入/自動註冊
     * 與 loginGoogle() 邏輯完全相同，欄位換成 facebook_id
     */
    public static function loginFacebook($fbUser, $ip) {
        $db         = Database::getInstance();
        $facebookId = $fbUser['id'];
        $email      = $fbUser['email'] ?? '';
        $name       = $fbUser['name']  ?: ('fb_user_' . $facebookId);
        $avatar     = $fbUser['picture'] ?? '';

        // 1. 先用 facebook_id 找舊會員
        $stmt = $db->prepare('SELECT * FROM members WHERE facebook_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$facebookId]);
        $member = $stmt->fetch();

        if (!$member) {
            // 2. 若有 email，試著合併既有帳號（例如他之前用 Google 登入過）
            if ($email) {
                $stmt = $db->prepare('SELECT * FROM members WHERE email = ? AND is_active = 1 LIMIT 1');
                $stmt->execute([$email]);
                $member = $stmt->fetch();
            }

            if ($member) {
                // 合併：把 facebook_id 綁到既有帳號
                $db->prepare('UPDATE members SET facebook_id=?, facebook_email=?, avatar_url=?, last_login=NOW() WHERE id=?')
                   ->execute([$facebookId, $email, $avatar, $member['id']]);
            } else {
                // 新用戶：自動建立帳號
                $username = 'fb_' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($name)), 0, 20) . '_' . rand(100, 999);
                // 若 Facebook 沒有提供 email，用假 email 佔位（避免 UNIQUE 衝突）
                $emailForDb = $email ?: ('fb_' . $facebookId . '@noemail.local');
                $db->prepare('INSERT INTO members (username, email, display_name, facebook_id, facebook_email, avatar_url, role, is_active, is_verified) VALUES (?, ?, ?, ?, ?, ?, "user", 1, 1)')
                   ->execute([$username, $emailForDb, $name, $facebookId, $email, $avatar]);
                $stmt = $db->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
                $stmt->execute([$db->lastInsertId()]);
                $member = $stmt->fetch();
            }
        } else {
            // 更新大頭貼和名稱
            $db->prepare('UPDATE members SET avatar_url=?, display_name=?, last_login=NOW() WHERE id=?')
               ->execute([$avatar, $name, $member['id']]);
        }

        self::setSession($member);
        self::logLogin($member['id'], $email, $ip, 'facebook', true);
        return ['success' => true, 'message' => 'Facebook 登入成功'];
    }

    // --------------------------------------------------------
    // Session 與輔助方法 — 原有，不變
    // --------------------------------------------------------
    public static function isLoggedIn() {
        return !empty($_SESSION['member_id']) && !empty($_SESSION['member']);
    }

    public static function currentUser() {
        return $_SESSION['member'] ?? null;
    }

    /**
     * 檢查目前登入者是否擁有指定角色，否則強制跳轉到登入頁。
     *
     * 使用方式（admin.php 第 9 行）：
     *   Auth::requireRole('admin');
     *
     * 邏輯流程：
     *  1. 尚未登入 → 導回 login.php
     *  2. 已登入但角色不符 → 導回 dashboard.php（403 語意）
     *  3. 符合角色 → 繼續執行
     *
     * @param string|string[] $requiredRole  允許的角色，可傳單一字串或陣列
     */
    public static function requireRole($requiredRole) {
        // 步驟 1：確認已登入
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }

        // 步驟 2：取得目前使用者角色
        $user = self::currentUser();
        $userRole = $user['role'] ?? '';

        // 步驟 3：支援單一角色或多角色陣列
        $allowed = is_array($requiredRole) ? $requiredRole : [$requiredRole];

        // 步驟 4：角色不符則拒絕
        if (!in_array($userRole, $allowed, true)) {
            header('Location: dashboard.php?error=forbidden');
            exit;
        }
        // 角色符合，繼續執行
    }

    public static function logout() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function setSession($member) {
        session_regenerate_id(true);
        $_SESSION['member_id'] = $member['id'];
        $_SESSION['member']    = [
            'id'           => $member['id'],
            'username'     => $member['username'],
            'email'        => $member['email'],
            'display_name' => isset($member['display_name']) ? $member['display_name'] : $member['username'],
            'avatar_url'   => isset($member['avatar_url'])   ? $member['avatar_url']   : '',
            'role'         => $member['role'],
            'google_id'    => isset($member['google_id'])    ? $member['google_id']    : null,
            'facebook_id'  => isset($member['facebook_id'])  ? $member['facebook_id']  : null, // ✅ 新增
        ];
    }

    private static function logLogin($memberId, $email, $ip, $type, $success) {
        try {
            Database::getInstance()
                ->prepare('INSERT INTO login_logs (member_id,email,ip_address,login_type,success) VALUES (?,?,?,?,?)')
                ->execute([$memberId, $email, $ip, $type, $success ? 1 : 0]);
        } catch (Exception $e) {
            error_log('記錄登入失敗: ' . $e->getMessage());
        }
    }

    // --------------------------------------------------------
    // HTTP 工具方法（stream + curl 雙重備援）— 原有，不變
    // --------------------------------------------------------
    private static function httpPostStream($url, $data) {
        if (!ini_get('allow_url_fopen')) return '';
        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => http_build_query($data),
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $res = @file_get_contents($url, false, stream_context_create($opts));
        return $res ?: '';
    }

    private static function httpGetStream($url, $token) {
        if (!ini_get('allow_url_fopen')) return '';
        $headers = "Accept: application/json\r\n";
        if ($token) $headers .= "Authorization: Bearer $token\r\n";
        $opts = [
            'http' => ['method' => 'GET', 'header' => $headers, 'timeout' => 15, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $res = @file_get_contents($url, false, stream_context_create($opts));
        return $res ?: '';
    }

    private static function httpPostCurl($url, $data) {
        if (!function_exists('curl_init')) return '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ?: '';
    }

    private static function httpGetCurl($url, $token) {
        if (!function_exists('curl_init')) return '';
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ?: '';
    }
}
