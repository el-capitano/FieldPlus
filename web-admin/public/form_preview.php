<?php
// web-admin/public/form_preview.php
$pageTitle = 'Form Preview & Test Submission';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requireAdminAuth();

$pdo = getDbConnection();
$formId = $_GET['id'] ?? null;

if (!$formId) {
    header('Location: forms.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM forms WHERE id = :id");
$stmt->execute(['id' => $formId]);
$form = $stmt->fetch();

if (!$form) {
    die("Form not found.");
}

$schema = json_decode($form['schema_json'], true);

require_once __DIR__ . '/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="forms.php" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Forms
            </a>
            <div class="d-flex gap-2">
                <a href="form_builder.php?id=<?= urlencode($form['id']) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-pen me-1"></i> Edit in Builder
                </a>
                <span class="badge bg-primary-subtle text-primary align-self-center px-3 py-2">
                    <i class="fa-solid fa-eye me-1"></i> Field Test Mode
                </span>
            </div>
        </div>

        <div id="submitAlert"></div>

        <div class="card shadow">
            <div class="card-header bg-primary text-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="fw-bold mb-0"><?= htmlspecialchars($form['title']) ?></h4>
                        <small class="opacity-75"><?= htmlspecialchars($form['category']) ?> &bull; Version <?= $form['version'] ?></small>
                    </div>
                    <span class="badge bg-light text-dark font-monospace"><?= htmlspecialchars($form['id']) ?></span>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($form['description'])): ?>
                    <div class="alert alert-light border mb-4">
                        <i class="fa-solid fa-circle-info text-primary me-2"></i>
                        <?= nl2br(htmlspecialchars($form['description'])) ?>
                    </div>
                <?php endif; ?>

                <form id="previewForm" enctype="multipart/form-data">
                    <input type="hidden" name="form_id" value="<?= htmlspecialchars($form['id']) ?>">
                    <input type="hidden" name="device_id" value="Web Simulator (Chrome/PC)">

                    <div class="card mb-4 bg-light border-0">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3 text-secondary text-uppercase small">Surveyor Profile</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Submitter Name</label>
                                    <input type="text" class="form-control" name="submitter_name" value="Field Inspector John">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Timestamp</label>
                                    <input type="text" class="form-control" value="<?= date('Y-m-d H:i:s') ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php foreach ($schema['sections'] ?? [] as $sIdx => $section): ?>
                        <div class="card mb-4 border">
                            <div class="card-header bg-white fw-bold py-2">
                                <i class="fa-solid fa-folder me-2 text-primary"></i>
                                <?= htmlspecialchars($section['title']) ?>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($section['description'])): ?>
                                    <p class="text-muted small mb-3"><?= htmlspecialchars($section['description']) ?></p>
                                <?php endif; ?>

                                <?php foreach ($section['fields'] ?? [] as $fld): 
                                    $req = !empty($fld['required']);
                                    $reqMark = $req ? '<span class="text-danger">*</span>' : '';
                                    $fId = htmlspecialchars($fld['id']);
                                    $depField = htmlspecialchars($fld['depends_on']['field'] ?? '');
                                    $depOp = htmlspecialchars($fld['depends_on']['operator'] ?? '==');
                                    $depVal = is_bool($fld['depends_on']['value'] ?? null) 
                                        ? ($fld['depends_on']['value'] ? 'true' : 'false') 
                                        : htmlspecialchars((string)($fld['depends_on']['value'] ?? ''));
                                ?>
                                    <div class="mb-3 field-row" data-field-id="<?= $fId ?>" data-type="<?= $fld['type'] ?>"
                                         data-depends-field="<?= $depField ?>" data-depends-op="<?= $depOp ?>" data-depends-val="<?= $depVal ?>">
                                        <label class="form-label fw-semibold small mb-1">
                                            <?= htmlspecialchars($fld['label']) ?> <?= $reqMark ?>
                                            <?php if (!empty($fld['depends_on'])): ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle ms-1" style="font-size: 10px;">
                                                    <i class="fa-solid fa-code-branch me-1"></i>If <?= $depField ?> <?= $depOp ?> <?= $depVal ?>
                                                </span>
                                            <?php endif; ?>
                                        </label>

                                        <?php switch ($fld['type']): 
                                            case 'text': ?>
                                                <input type="text" class="form-control field-input" name="field_<?= $fId ?>" 
                                                       placeholder="<?= htmlspecialchars($fld['placeholder'] ?? '') ?>" <?= $req ? 'required' : '' ?>>
                                                <?php break; ?>

                                            <?php case 'barcode': ?>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="fa-solid fa-barcode"></i></span>
                                                    <input type="text" class="form-control field-input" name="field_<?= $fId ?>" 
                                                           id="barcode_<?= $fId ?>" placeholder="Scan or enter asset barcode/serial" <?= $req ? 'required' : '' ?>>
                                                    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('barcode_<?= $fId ?>').value = 'SN-' + Math.floor(100000 + Math.random() * 900000);">
                                                        <i class="fa-solid fa-camera me-1"></i> Scan
                                                    </button>
                                                </div>
                                                <?php break; ?>

                                            <?php case 'textarea': ?>
                                                <textarea class="form-control field-input" name="field_<?= $fId ?>" rows="3" 
                                                          placeholder="<?= htmlspecialchars($fld['placeholder'] ?? '') ?>" <?= $req ? 'required' : '' ?>></textarea>
                                                <?php break; ?>

                                            <?php case 'number': ?>
                                                <?php 
                                                    $fMin = isset($fld['min']) && $fld['min'] !== '' ? $fld['min'] : null;
                                                    $fMax = isset($fld['max']) && $fld['max'] !== '' ? $fld['max'] : null;
                                                ?>
                                                <input type="number" step="any" class="form-control field-input" name="field_<?= $fId ?>" 
                                                       placeholder="<?= htmlspecialchars($fld['placeholder'] ?? '0') ?>" 
                                                       <?= ($fMin !== null) ? 'min="' . htmlspecialchars($fMin) . '"' : '' ?>
                                                       <?= ($fMax !== null) ? 'max="' . htmlspecialchars($fMax) . '"' : '' ?>
                                                       <?= $req ? 'required' : '' ?>>
                                                <?php if ($fMin !== null || $fMax !== null): ?>
                                                    <small class="text-muted d-block" style="font-size: 11px;">Range: [<?= $fMin ?? '-∞' ?> to <?= $fMax ?? '+∞' ?>]</small>
                                                <?php endif; ?>
                                                <?php break; ?>

                                            <?php case 'date': ?>
                                                <input type="date" class="form-control field-input" name="field_<?= $fId ?>" 
                                                       value="<?= date('Y-m-d') ?>" <?= $req ? 'required' : '' ?>>
                                                <?php break; ?>

                                            <?php case 'time': ?>
                                                <input type="time" class="form-control field-input" name="field_<?= $fId ?>" 
                                                       value="<?= date('H:i') ?>" <?= $req ? 'required' : '' ?>>
                                                <?php break; ?>

                                            <?php case 'dropdown': ?>
                                                <select class="form-select field-input" name="field_<?= $fId ?>" <?= $req ? 'required' : '' ?>>
                                                    <option value="">Select option...</option>
                                                    <?php foreach ($fld['options'] ?? [] as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php break; ?>

                                            <?php case 'radio': ?>
                                                <?php foreach ($fld['options'] ?? [] as $rIdx => $opt): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input field-input" type="radio" name="field_<?= $fId ?>" 
                                                               id="<?= $fId . '_' . $rIdx ?>" value="<?= htmlspecialchars($opt) ?>" <?= ($rIdx === 0 && $req) ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="<?= $fId . '_' . $rIdx ?>"><?= htmlspecialchars($opt) ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php break; ?>

                                            <?php case 'checkbox': ?>
                                                <?php foreach ($fld['options'] ?? [] as $cIdx => $opt): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input field-input" type="checkbox" name="field_<?= $fId ?>[]" 
                                                               id="<?= $fId . '_' . $cIdx ?>" value="<?= htmlspecialchars($opt) ?>">
                                                        <label class="form-check-label small" for="<?= $fId . '_' . $cIdx ?>"><?= htmlspecialchars($opt) ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php break; ?>

                                            <?php case 'switch': ?>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input field-input" type="checkbox" role="switch" name="field_<?= $fId ?>" id="switch_<?= $fId ?>" value="1">
                                                    <label class="form-check-label small" for="switch_<?= $fId ?>">Yes / Positive</label>
                                                </div>
                                                <?php break; ?>

                                            <?php case 'gps': ?>
                                                <div class="border rounded p-3 bg-light gps-container" id="gps_box_<?= $fId ?>">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <i class="fa-solid fa-location-crosshairs fa-lg text-danger"></i>
                                                            <span class="small fw-bold">GPS Coordinates</span>
                                                        </div>
                                                        <div class="d-flex gap-1">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="acquireRealGps('<?= $fId ?>')">
                                                                <i class="fa-solid fa-crosshairs me-1"></i> Get Device GPS
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="generateMockGps('<?= $fId ?>')">
                                                                <i class="fa-solid fa-dice me-1"></i> Mock Coordinates
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="row g-2">
                                                        <div class="col-md-6">
                                                            <input type="text" class="form-control form-control-sm font-monospace" id="lat_<?= $fId ?>" placeholder="Latitude (e.g. 24.7136)">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <input type="text" class="form-control form-control-sm font-monospace" id="lng_<?= $fId ?>" placeholder="Longitude (e.g. 46.6753)">
                                                        </div>
                                                    </div>
                                                    <div class="d-flex gap-3 mt-1 text-muted small">
                                                        <span>Accuracy: <span id="acc_<?= $fId ?>">5.0</span> m</span>
                                                        <span>Altitude: <span id="alt_<?= $fId ?>">612</span> m</span>
                                                    </div>
                                                </div>
                                                <?php break; ?>

                                            <?php case 'journey_gps': ?>
                                                <div class="border rounded p-3 bg-light border-success-subtle">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <i class="fa-solid fa-route fa-lg text-success"></i>
                                                            <span class="small fw-bold">Continuous Journey GPS Track</span>
                                                            <span class="badge bg-success-subtle text-success border">Live Tracker</span>
                                                        </div>
                                                        <span class="badge bg-dark font-monospace" id="journey_timer_<?= $fId ?>">00:00:00</span>
                                                    </div>
                                                    <p class="text-muted small mb-2">When recording, points are logged in the background while surveyor travels and completes this form.</p>
                                                    <div class="d-flex gap-2 mb-2">
                                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="togglePreviewJourney('<?= $fId ?>')">
                                                            <i class="fa-solid fa-play me-1"></i> Simulate Start / Stop
                                                        </button>
                                                        <span class="badge bg-light text-dark border align-self-center" id="journey_pts_<?= $fId ?>">0 points recorded</span>
                                                    </div>
                                                    <input type="hidden" name="field_<?= $fId ?>" id="journey_data_<?= $fId ?>" value="">
                                                </div>
                                                <?php break; ?>

                                            <?php case 'file': ?>
                                                <div class="border rounded p-3 bg-light">
                                                    <input type="file" class="form-control" name="<?= $fId ?>" accept="image/*,.pdf,.doc,.docx" <?= $req ? 'required' : '' ?>>
                                                    <small class="text-muted d-block mt-1">Upload an image or document attachment</small>
                                                </div>
                                                <?php break; ?>

                                            <?php case 'multiple_photos': ?>
                                                <div class="border rounded p-3 bg-light border-info-subtle">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="small fw-bold text-dark"><i class="fa-solid fa-images text-info me-1"></i> Multiple Photos (Gallery)</span>
                                                        <span class="badge bg-info-subtle text-info border">Multi-Upload</span>
                                                    </div>
                                                    <input type="file" class="form-control" name="<?= $fId ?>[]" accept="image/*" multiple <?= $req ? 'required' : '' ?>>
                                                    <small class="text-muted d-block mt-1">Select or capture multiple photos for this audit item.</small>
                                                </div>
                                                <?php break; ?>

                                            <?php case 'video': ?>
                                                <div class="border rounded p-3 bg-light border-danger-subtle">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="small fw-bold text-dark"><i class="fa-solid fa-video text-danger me-1"></i> Video Recording / Clip</span>
                                                        <span class="badge bg-danger-subtle text-danger border">Video (MP4)</span>
                                                    </div>
                                                    <input type="file" class="form-control" name="<?= $fId ?>" accept="video/*" <?= $req ? 'required' : '' ?>>
                                                    <small class="text-muted d-block mt-1">Record or attach a video clip inspection.</small>
                                                </div>
                                                <?php break; ?>

                                            <?php case 'signature': ?>
                                                <div class="border rounded p-3 bg-light">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="small fw-semibold text-muted">Sign with mouse or touch:</span>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="clearSignature('<?= $fId ?>')">Clear</button>
                                                    </div>
                                                    <canvas id="canvas_<?= $fId ?>" width="500" height="120" style="background: #fff; border: 1px dashed #cbd5e1; border-radius: 6px; width: 100%; touch-action: none; cursor: crosshair;"></canvas>
                                                    <input type="hidden" id="sig_data_<?= $fId ?>" name="field_<?= $fId ?>">
                                                </div>
                                                <?php break; ?>

                                        <?php endswitch; ?>

                                        <?php if (!empty($fld['help'])): ?>
                                            <small class="text-muted d-block mt-1" style="font-size: 11px;">
                                                <i class="fa-regular fa-circle-question me-1"></i><?= htmlspecialchars($fld['help']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg shadow-sm" id="btnSubmitForm">
                            <i class="fa-solid fa-paper-plane me-2"></i> Submit & Test API Sync
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Signature Canvas Setup
const signatureCanvases = {};

document.querySelectorAll('canvas[id^="canvas_"]').forEach(canvas => {
    const fId = canvas.id.replace('canvas_', '');
    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';
    let isDrawing = false;
    let hasDrawn = false;

    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function start(e) {
        e.preventDefault();
        isDrawing = true;
        const pos = getPos(e);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    }

    function draw(e) {
        if (!isDrawing) return;
        e.preventDefault();
        const pos = getPos(e);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        hasDrawn = true;
    }

    function stop(e) {
        if (isDrawing) {
            isDrawing = false;
            if (hasDrawn) {
                document.getElementById('sig_data_' + fId).value = canvas.toDataURL('image/png');
            }
        }
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stop);
    canvas.addEventListener('touchstart', start);
    canvas.addEventListener('touchmove', draw);
    canvas.addEventListener('touchend', stop);

    signatureCanvases[fId] = { canvas, ctx, clear: () => {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById('sig_data_' + fId).value = '';
        hasDrawn = false;
    }};
});

function clearSignature(fId) {
    if (signatureCanvases[fId]) {
        signatureCanvases[fId].clear();
    }
}

// GPS Helpers
function acquireRealGps(fId) {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                document.getElementById('lat_' + fId).value = pos.coords.latitude.toFixed(6);
                document.getElementById('lng_' + fId).value = pos.coords.longitude.toFixed(6);
                document.getElementById('acc_' + fId).innerText = pos.coords.accuracy.toFixed(1);
                document.getElementById('alt_' + fId).innerText = (pos.coords.altitude || 600).toFixed(1);
            },
            err => {
                alert('GPS Error: ' + err.message + '. Generating mock location instead.');
                generateMockGps(fId);
            }
        );
    } else {
        generateMockGps(fId);
    }
}

function generateMockGps(fId) {
    const lat = (24.7136 + (Math.random() - 0.5) * 0.05).toFixed(6);
    const lng = (46.6753 + (Math.random() - 0.5) * 0.05).toFixed(6);
    document.getElementById('lat_' + fId).value = lat;
    document.getElementById('lng_' + fId).value = lng;
    document.getElementById('acc_' + fId).innerText = (3 + Math.random() * 4).toFixed(1);
    document.getElementById('alt_' + fId).innerText = (610 + Math.random() * 15).toFixed(1);
}

// Auto-fill mock GPS on page load for convenience
document.querySelectorAll('[id^="lat_"]').forEach(el => {
    const fId = el.id.replace('lat_', '');
    generateMockGps(fId);
});

// Skip Logic / Reactive Visibility Engine
function evaluateWebDependencies() {
    const form = document.getElementById('previewForm');
    if (!form) return;

    function getFieldValue(fId) {
        const row = document.querySelector(`.field-row[data-field-id="${fId}"]`);
        if (!row) return null;
        const type = row.dataset.type;

        if (type === 'switch') {
            const cb = row.querySelector('input[type="checkbox"]');
            return cb ? cb.checked : false;
        } else if (type === 'checkbox') {
            const checked = [];
            row.querySelectorAll('input[type="checkbox"]:checked').forEach(c => checked.push(c.value));
            return checked;
        } else if (type === 'radio') {
            const r = row.querySelector('input[type="radio"]:checked');
            return r ? r.value : '';
        } else {
            const input = row.querySelector('.field-input');
            return input ? input.value : '';
        }
    }

    document.querySelectorAll('.field-row[data-depends-field]').forEach(row => {
        const depField = row.dataset.dependsField;
        if (!depField) return;

        const depOp = row.dataset.dependsOp || '==';
        const depVal = row.dataset.dependsVal;
        const actualVal = getFieldValue(depField);

        let isMatch = false;

        if (typeof actualVal === 'boolean') {
            const expectedBool = (depVal === 'true' || depVal === '1');
            isMatch = (depOp === '!=') ? (actualVal !== expectedBool) : (actualVal === expectedBool);
        } else if (Array.isArray(actualVal)) {
            if (depOp === 'contains' || depOp === '==' || depOp === 'in') {
                isMatch = actualVal.includes(depVal);
            } else if (depOp === '!=') {
                isMatch = !actualVal.includes(depVal);
            }
        } else {
            const actStr = String(actualVal || '').trim();
            const expStr = String(depVal || '').trim();
            const actNum = parseFloat(actStr);
            const expNum = parseFloat(expStr);

            if (!isNaN(actNum) && !isNaN(expNum) && actStr !== '' && expStr !== '') {
                switch (depOp) {
                    case '>': isMatch = actNum > expNum; break;
                    case '>=': isMatch = actNum >= expNum; break;
                    case '<': isMatch = actNum < expNum; break;
                    case '<=': isMatch = actNum <= expNum; break;
                    case '!=': isMatch = actNum !== expNum; break;
                    default: isMatch = actNum === expNum; break;
                }
            } else {
                switch (depOp) {
                    case '!=': isMatch = actStr.toLowerCase() !== expStr.toLowerCase(); break;
                    case 'contains': isMatch = actStr.toLowerCase().includes(expStr.toLowerCase()); break;
                    default: isMatch = actStr.toLowerCase() === expStr.toLowerCase(); break;
                }
            }
        }

        if (isMatch) {
            row.style.display = '';
            row.querySelectorAll('[data-orig-required="true"]').forEach(el => el.required = true);
        } else {
            row.style.display = 'none';
            row.querySelectorAll('[required]').forEach(el => {
                el.dataset.origRequired = 'true';
                el.required = false;
            });
        }
    });
}

// Attach listeners for live skip logic evaluation
const formEl = document.getElementById('previewForm');
if (formEl) {
    formEl.addEventListener('input', evaluateWebDependencies);
    formEl.addEventListener('change', evaluateWebDependencies);
    // Initial evaluation
    evaluateWebDependencies();
}

// Form Submission via API
document.getElementById('previewForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitForm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Submitting to API...';

    const formDataObj = new FormData(this);
    const answersData = {};

    // Collect field answers
    document.querySelectorAll('.field-row').forEach(row => {
        const fId = row.dataset.fieldId;
        const type = row.dataset.type;

        if (type === 'gps') {
            const lat = document.getElementById('lat_' + fId).value;
            const lng = document.getElementById('lng_' + fId).value;
            const acc = document.getElementById('acc_' + fId).innerText;
            const alt = document.getElementById('alt_' + fId).innerText;
            if (lat && lng) {
                answersData[fId] = {
                    latitude: parseFloat(lat),
                    longitude: parseFloat(lng),
                    accuracy: parseFloat(acc),
                    altitude: parseFloat(alt)
                };
            }
        } else if (type === 'checkbox') {
            const selected = [];
            row.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => selected.push(cb.value));
            answersData[fId] = selected;
        } else if (type === 'switch') {
            const cb = row.querySelector('input[type="checkbox"]');
            answersData[fId] = cb ? cb.checked : false;
        } else if (type === 'signature') {
            const sig = document.getElementById('sig_data_' + fId).value;
            if (sig) answersData[fId] = sig;
        } else if (type === 'file') {
            // File input is automatically attached in formDataObj with key fId
        } else {
            const input = row.querySelector('.field-input');
            if (input) {
                answersData[fId] = input.value;
            }
        }
    });

    formDataObj.append('submission_uuid', 'sub_' + Math.random().toString(36).substring(2, 12) + Date.now().toString(36));
    formDataObj.append('data', JSON.stringify(answersData));

    try {
        const res = await fetch('api/submissions.php', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + <?= json_encode($currentUser['api_token'] ?? '') ?>
            },
            body: formDataObj
        });
        const result = await res.json();

        if (result.status === 'success') {
            document.getElementById('submitAlert').innerHTML = `
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <h5 class="fw-bold"><i class="fa-solid fa-circle-check me-2"></i>Submission Recorded!</h5>
                    <p class="mb-2">Your test field visit was synced successfully with UUID: <code>${result.submission_uuid}</code></p>
                    <div class="d-flex gap-2">
                        <a href="submission_detail.php?uuid=${result.submission_uuid}" class="btn btn-sm btn-success">View Inspection Report</a>
                        <a href="submissions.php" class="btn btn-sm btn-outline-secondary">Go to Submissions Table</a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            alert('Submission failed: ' + (result.message || 'Error'));
        }
    } catch (err) {
        alert('API error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i> Submit & Test API Sync';
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
