<?php
// web-admin/public/app_connect.php
$pageTitle = 'Mobile App QR Connect';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requireAdminAuth();

$pdo = getDbConnection();

// Fetch fresh user data & api_token
$stmt = $pdo->prepare("SELECT id, username, full_name, role, api_token FROM users WHERE id = :id");
$stmt->execute(['id' => $currentUser['id']]);
$user = $stmt->fetch();

$message = '';
// Regenerate Token Action
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['regenerate_token'])) {
    requireCsrfToken();
    $newToken = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE users SET api_token = :t WHERE id = :id")->execute(['t' => $newToken, 'id' => $user['id']]);
    $user['api_token'] = $newToken;
    $_SESSION['api_token'] = $newToken;
    logSecurityEvent('API_TOKEN_REGENERATED', "User '{$user['username']}' regenerated personal API token", (int)$user['id'], $user['username']);
    $message = 'API Bearer Token regenerated successfully. Please rescan the QR code on your mobile device.';
}

// Determine Server Host for Mobile App
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$isLocal = in_array(explode(':', $host)[0], ['localhost', '127.0.0.1']);

// Get local machine IP for real device testing
$localIp = gethostbyname(gethostname());

// Mobile configuration payload
$appConfig = [
    'server_url' => "http://{$host}/api/",
    'api_token' => $user['api_token'],
    'surveyor_name' => $user['full_name'],
    'username' => $user['username'],
    'role' => $user['role']
];

$qrPayloadJson = json_encode($appConfig);

require_once __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="fa-solid fa-qrcode text-primary me-2"></i>Authenticate Mobile App via QR Code</h5>
                        <p class="text-muted small mb-0">Scan this secure QR code using the FieldPlus Android app to configure your server URL &amp; API Bearer token instantly.</p>
                    </div>
                    <form method="POST" onsubmit="return confirm('Regenerating will invalidate the current token on all mobile devices. Continue?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="regenerate_token" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> Regenerate Token
                        </button>
                    </form>
                </div>
            </div>

            <div class="card-body p-4 text-center">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-4 mb-md-0">
                        <div class="p-3 bg-white rounded-3 border d-inline-block shadow-sm">
                            <!-- QR Code rendered via QRCode.js -->
                            <div id="qrcodeCanvas" class="d-flex justify-content-center"></div>
                        </div>
                        <div class="mt-3">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                                <i class="fa-solid fa-shield-halved me-1"></i> Bearer Auth Config
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 text-start">
                        <h6 class="fw-bold mb-3 text-secondary text-uppercase" style="letter-spacing: 0.5px; font-size: 12px;">Configuration Details</h6>
                        
                        <div class="mb-3">
                            <label class="text-muted small mb-1 fw-semibold">Server API URL</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($appConfig['server_url']) ?>" readonly id="inServerUrl">
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('inServerUrl').value); alert('Copied!');">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="text-muted small mb-1 fw-semibold">Assigned Surveyor</label>
                            <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['username']) ?>)" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="text-muted small mb-1 fw-semibold">Active Bearer API Token</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control font-monospace" value="<?= htmlspecialchars($user['api_token']) ?>" readonly id="inToken">
                                <button class="btn btn-outline-secondary" type="button" onclick="const f = document.getElementById('inToken'); f.type = (f.type === 'password') ? 'text' : 'password';">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($user['api_token']) ?>'); alert('Token Copied!');">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <?php if ($isLocal): ?>
                            <div class="alert alert-warning py-2 px-3 small mb-0" role="alert">
                                <i class="fa-solid fa-circle-info me-1"></i>
                                <strong>Physical Device Tip:</strong> When testing on a physical phone via Wi-Fi, change host to your PC LAN IP: <code>http://<?= htmlspecialchars($localIp) ?>:8080/api/</code>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-footer bg-light py-3 px-4">
                <h6 class="fw-bold mb-2 small text-uppercase text-secondary">How to Authenticate from Mobile App:</h6>
                <ol class="mb-0 small text-muted ps-3">
                    <li class="mb-1">Open <strong>FieldPlus</strong> on your Android device.</li>
                    <li class="mb-1">Tap the top-right menu and choose <strong>Server &amp; Sync Settings</strong>.</li>
                    <li>Tap <strong>"📷 Scan QR Code to Authenticate"</strong> and point your camera at this QR code. The app will immediately pair and verify connection!</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- QR Code Generator Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const qrData = <?= json_encode($qrPayloadJson) ?>;
    new QRCode(document.getElementById("qrcodeCanvas"), {
        text: qrData,
        width: 230,
        height: 230,
        colorDark : "#0f172a",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.M
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
