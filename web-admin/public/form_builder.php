<?php
// web-admin/public/form_builder.php
$pageTitle = 'Visual Form Builder';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requirePermission('forms_write', 'You do not have permission to create or edit form templates.');

$pdo = getDbConnection();
$formId = $_GET['id'] ?? null;
$existingForm = null;

if ($formId) {
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = :id");
    $stmt->execute(['id' => $formId]);
    $existingForm = $stmt->fetch();

    // Permission check: only admin, forms_all scope, or original form creator can edit
    if ($existingForm && !isAdmin($currentUser) && !hasPermission('forms_all', $currentUser) && (int)$existingForm['created_by'] !== (int)$currentUser['id']) {
        header('Location: forms.php');
        exit();
    }
}

$allUsers = $pdo->query("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
$allRoles = $pdo->query("SELECT id, slug, name, description FROM roles ORDER BY name ASC")->fetchAll();

// Initial form state
$schemaObj = null;
if ($existingForm && !empty($existingForm['schema_json'])) {
    $schemaObj = json_decode($existingForm['schema_json'], true);
    if (is_string($schemaObj)) {
        $schemaObj = json_decode($schemaObj, true);
    }
}

$initialData = [
    'id' => $existingForm ? $existingForm['id'] : 'form_' . substr(bin2hex(random_bytes(4)), 0, 8),
    'title' => $existingForm ? $existingForm['title'] : 'New Field Survey',
    'category' => $existingForm ? $existingForm['category'] : 'Field Inspection',
    'description' => $existingForm ? $existingForm['description'] : 'Field visit data collection template',
    'version' => $existingForm ? (int)$existingForm['version'] : 1,
    'is_active' => $existingForm ? (int)$existingForm['is_active'] : 1,
    'access_type' => $existingForm ? ($existingForm['access_type'] ?? 'all') : 'all',
    'assigned_users' => $existingForm ? (json_decode($existingForm['assigned_users_json'] ?? '[]', true) ?: []) : [],
    'assigned_roles' => $existingForm ? (json_decode($existingForm['assigned_roles_json'] ?? '[]', true) ?: []) : [],
    'schema' => ($schemaObj && is_array($schemaObj)) ? $schemaObj : [
        'id' => 'form_' . substr(bin2hex(random_bytes(4)), 0, 8),
        'title' => 'New Field Survey',
        'category' => 'Field Inspection',
        'description' => 'Field visit data collection template',
        'version' => 1,
        'sections' => [
            [
                'id' => 'sec_general',
                'title' => 'General Information',
                'description' => 'Primary identification details',
                'fields' => [
                    [
                        'id' => 'visit_name',
                        'type' => 'text',
                        'label' => 'Facility / Visit Subject',
                        'placeholder' => 'Enter site or client name',
                        'required' => true,
                        'help' => 'Main title for this visit'
                    ],
                    [
                        'id' => 'visit_gps',
                        'type' => 'gps',
                        'label' => 'Field Visit GPS Location',
                        'required' => true,
                        'help' => 'Capture coordinates on site'
                    ],
                    [
                        'id' => 'visit_date',
                        'type' => 'date',
                        'label' => 'Visit Date',
                        'required' => true
                    ]
                ]
            ]
        ]
    ]
];

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-hammer text-primary me-2"></i>
            <?= $existingForm ? 'Edit Form: ' . htmlspecialchars($existingForm['title']) : 'Create Field Survey Form' ?>
        </h2>
        <p class="text-muted mb-0">Build flexible schemas with GPS, Photos, Signatures, Pickers, and Selectors that sync to Android.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="forms.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Catalog
        </a>
        <button type="button" class="btn btn-outline-primary" onclick="openJsonModal()">
            <i class="fa-solid fa-code me-1"></i> JSON Schema
        </button>
        <button type="button" class="btn btn-success" id="btnSaveForm">
            <i class="fa-solid fa-floppy-disk me-1"></i> Save & Publish Form
        </button>
    </div>
</div>

<div id="alertContainer"></div>

<div class="row g-4">
    <!-- Left Column: Form Builder Workspace -->
    <div class="col-lg-7">
        <!-- Form Settings Card -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-white py-3">
                <span class="fw-bold"><i class="fa-solid fa-sliders text-primary me-2"></i>Form Metadata</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Form Unique ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="formId" value="<?= htmlspecialchars($initialData['id']) ?>" <?= $existingForm ? 'readonly' : '' ?>>
                        <small class="text-muted" style="font-size: 11px;">Machine-readable ID (e.g. form_site_audit)</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Category</label>
                        <input type="text" class="form-control" id="formCategory" value="<?= htmlspecialchars($initialData['category']) ?>" placeholder="e.g. Engineering, Sales, Audit">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Form Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control fw-bold" id="formTitle" value="<?= htmlspecialchars($initialData['title']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Description / Instructions</label>
                        <textarea class="form-control" id="formDescription" rows="2"><?= htmlspecialchars($initialData['description']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small"><i class="fa-solid fa-user-lock text-primary me-1"></i> Form Access Permissions</label>
                        <select class="form-select" id="formAccessType" onchange="toggleBuilderAccessPanels()">
                            <option value="all" <?= $initialData['access_type'] === 'all' ? 'selected' : '' ?>>Public: All Staff &amp; Surveyors</option>
                            <option value="creator_only" <?= $initialData['access_type'] === 'creator_only' ? 'selected' : '' ?>>Private: Creator Only</option>
                            <option value="specific_users" <?= $initialData['access_type'] === 'specific_users' ? 'selected' : '' ?>>Restricted: Specific Users</option>
                            <option value="specific_roles" <?= $initialData['access_type'] === 'specific_roles' ? 'selected' : '' ?>>Restricted: Specific Roles / Groups</option>
                        </select>
                    </div>

                    <!-- Builder Specific Users Panel -->
                    <div class="col-12 <?= $initialData['access_type'] === 'specific_users' ? '' : 'd-none' ?>" id="builderPanelUsers">
                        <div class="p-3 bg-light border rounded">
                            <label class="form-label fw-semibold small mb-2"><i class="fa-solid fa-users text-info me-1"></i> Authorized Users:</label>
                            <div class="row g-2" style="max-height: 160px; overflow-y: auto;">
                                <?php foreach ($allUsers as $u): 
                                    $checked = in_array((int)$u['id'], $initialData['assigned_users']);
                                ?>
                                    <div class="col-md-6">
                                        <div class="form-check bg-white p-2 rounded border">
                                            <input class="form-check-input builder-user-cb ms-0 me-2" type="checkbox" value="<?= $u['id'] ?>" id="b_u_<?= $u['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="b_u_<?= $u['id'] ?>">
                                                <?= htmlspecialchars($u['full_name']) ?> <span class="text-muted">(<?= htmlspecialchars($u['username']) ?>)</span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Builder Specific Roles Panel -->
                    <div class="col-12 <?= $initialData['access_type'] === 'specific_roles' ? '' : 'd-none' ?>" id="builderPanelRoles">
                        <div class="p-3 bg-light border rounded">
                            <label class="form-label fw-semibold small mb-2"><i class="fa-solid fa-id-badge text-primary me-1"></i> Authorized Roles:</label>
                            <div class="row g-2">
                                <?php foreach ($allRoles as $r): 
                                    $checked = in_array($r['slug'], $initialData['assigned_roles']);
                                ?>
                                    <div class="col-md-6">
                                        <div class="form-check bg-white p-2 rounded border">
                                            <input class="form-check-input builder-role-cb ms-0 me-2" type="checkbox" value="<?= htmlspecialchars($r['slug']) ?>" id="b_r_<?= htmlspecialchars($r['slug']) ?>" <?= $checked ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="b_r_<?= htmlspecialchars($r['slug']) ?>">
                                                <strong><?= htmlspecialchars($r['name']) ?></strong>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section Header with Quick Add Button -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-layer-group text-primary me-2"></i>Form Sections &amp; Controls</h5>
                <small class="text-muted">Organize your survey into structured multi-step sections</small>
            </div>
            <button type="button" class="btn btn-sm btn-primary shadow-sm" id="btnQuickAddSection">
                <i class="fa-solid fa-plus me-1"></i> Add Section
            </button>
        </div>

        <!-- Sections & Fields Container -->
        <div id="sectionsContainer">
            <!-- Dynamic Sections rendered via JS -->
        </div>

        <div class="d-grid mb-5">
            <button type="button" class="btn btn-outline-primary py-2 border-dashed shadow-sm" id="btnAddSection">
                <i class="fa-solid fa-plus me-1"></i> Add New Form Section
            </button>
        </div>
    </div>

    <!-- Right Column: Interactive Live Preview (ODK / Mobile Simulation) -->
    <div class="col-lg-5">
        <div class="card sticky-top shadow-sm" style="top: 20px;">
            <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="fa-solid fa-mobile-screen me-2 text-warning"></i>Live Mobile Preview</span>
                <span class="badge bg-primary">Sync Preview</span>
            </div>
            <div class="card-body bg-light p-3" style="max-height: 80vh; overflow-y: auto;">
                <div class="bg-white p-3 rounded shadow-sm border mb-3">
                    <h5 class="fw-bold text-primary mb-1" id="previewTitle">Form Title</h5>
                    <p class="text-muted small mb-0" id="previewDescription">Form description...</p>
                </div>
                <div id="previewBody">
                    <!-- Live preview fields rendered here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Field -->
<div class="modal fade" id="fieldModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="fieldModalTitle"><i class="fa-solid fa-plus me-2 text-primary"></i>Add Field Control</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalSectionIdx">
                <input type="hidden" id="modalFieldIdx">

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Control Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="modalFieldType">
                        <option value="text">🔤 Text (Single line)</option>
                        <option value="textarea">📝 Multiline Text / Notes</option>
                        <option value="number">🔢 Number / Decimal</option>
                        <option value="date">📅 Date Picker</option>
                        <option value="time">⏰ Time Picker</option>
                        <option value="gps">📍 GPS Location Capture</option>
                        <option value="journey_gps">🛰️ GPS Journey Track (Continuous Path)</option>
                        <option value="file">📷 Photo / Camera (Single)</option>
                        <option value="multiple_photos">📸 Multiple Photos Gallery</option>
                        <option value="video">🎥 Record / Upload Video</option>
                        <option value="signature">✍️ Digital Signature Pad</option>
                        <option value="dropdown">🔽 Dropdown Selection</option>
                        <option value="radio">🔘 Radio Choice</option>
                        <option value="checkbox">☑️ Multi-select Checkboxes</option>
                        <option value="switch">🔘 Boolean Switch (Yes/No)</option>
                        <option value="barcode">📷 Barcode / QR Code Scanner</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Field Label / Question <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="modalFieldLabel" placeholder="e.g. Inspector Signature">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Field Identifier (Code Key) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control font-monospace" id="modalFieldId" placeholder="e.g. inspector_sig">
                    <small class="text-muted" style="font-size: 11px;">Unique variable key in database and JSON</small>
                </div>

                <div class="mb-3" id="optionsGroup" style="display: none;">
                    <label class="form-label fw-semibold small">Options (Comma separated) <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="modalFieldOptions" rows="2" placeholder="Option 1, Option 2, Option 3"></textarea>
                    <small class="text-muted" style="font-size: 11px;">Separate choices with commas</small>
                </div>

                <div class="row g-2 mb-3" id="constraintsGroup">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Min Value (Numeric)</label>
                        <input type="number" step="any" class="form-control form-control-sm" id="modalFieldMin" placeholder="e.g. 0">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Max Value (Numeric)</label>
                        <input type="number" step="any" class="form-control form-control-sm" id="modalFieldMax" placeholder="e.g. 100">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Validation Pattern (Regex)</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="modalFieldPattern" placeholder="e.g. ^[A-Z0-9-]+$">
                    </div>
                </div>

                <!-- Skip Logic / Conditional Display -->
                <div class="border rounded p-3 bg-light mb-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="modalEnableSkipLogic">
                        <label class="form-check-label fw-semibold small" for="modalEnableSkipLogic">
                            <i class="fa-solid fa-code-branch text-primary me-1"></i> Enable Skip Logic (Conditional Display)
                        </label>
                    </div>
                    <div id="skipLogicGroup" style="display: none;">
                        <small class="text-muted d-block mb-2" style="font-size: 11px;">Display this field only when a specific parent condition is satisfied.</small>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Depends on Field ID</label>
                                <input type="text" class="form-control form-control-sm font-monospace" id="modalDepField" placeholder="e.g. pest_present">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Condition</label>
                                <select class="form-select form-select-sm" id="modalDepOperator">
                                    <option value="==">Equals (==)</option>
                                    <option value="!=">Not Equals (!=)</option>
                                    <option value="contains">Contains</option>
                                    <option value="not_empty">Is Not Empty</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Matching Value</label>
                                <input type="text" class="form-control form-control-sm" id="modalDepValue" placeholder="e.g. true or specific choice text">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Placeholder Text</label>
                    <input type="text" class="form-control" id="modalFieldPlaceholder" placeholder="Optional placeholder hint">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Help / Guidance Text</label>
                    <input type="text" class="form-control" id="modalFieldHelp" placeholder="Optional instruction for mobile surveyor">
                </div>

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="modalFieldRequired" checked>
                    <label class="form-check-label fw-semibold small" for="modalFieldRequired">Required Field</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveFieldModal">Apply to Form</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: JSON Schema Import / Export -->
<div class="modal fade" id="jsonSchemaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-code text-primary me-2"></i>JSON Schema Inspector &amp; Importer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Inspect the complete ODK/Kobo-compatible JSON schema, copy for offline backup, or paste external schema JSON to import directly.</p>
                <div class="mb-3">
                    <textarea class="form-control font-monospace" id="schemaJsonTextarea" rows="15" style="font-size: 12px; background: #0f172a; color: #38bdf8;" placeholder="Paste JSON schema here..."></textarea>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copySchemaJson()">
                            <i class="fa-solid fa-copy me-1"></i> Copy to Clipboard
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="downloadSchemaJson()">
                            <i class="fa-solid fa-download me-1"></i> Download .json
                        </button>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-warning fw-semibold shadow-sm" onclick="importSchemaJson()">
                            <i class="fa-solid fa-file-import me-1"></i> Parse &amp; Load into Builder
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let formData = <?= json_encode($initialData['schema']) ?>;
if (typeof formData === 'string') {
    try {
        formData = JSON.parse(formData);
    } catch (_) {
        formData = {};
    }
}
if (!formData || typeof formData !== 'object') {
    formData = {};
}
if (!Array.isArray(formData.sections)) {
    formData.sections = [];
}

let fieldModal = null;
function getFieldModal() {
    if (!fieldModal && typeof bootstrap !== 'undefined') {
        const el = document.getElementById('fieldModal');
        if (el) {
            fieldModal = new bootstrap.Modal(el);
        }
    }
    return fieldModal;
}

function addNewSection() {
    if (!Array.isArray(formData.sections)) {
        formData.sections = [];
    }
    const secNum = formData.sections.length + 1;
    formData.sections.push({
        id: 'sec_' + secNum + '_' + Math.random().toString(36).substring(2, 6),
        title: 'Section ' + secNum,
        description: '',
        fields: []
    });
    renderBuilder();

    setTimeout(() => {
        const container = document.getElementById('sectionsContainer');
        if (container && container.lastElementChild) {
            container.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            const titleInput = container.lastElementChild.querySelector('input');
            if (titleInput) titleInput.focus();
        }
    }, 50);
}

function updateSectionDescription(sIdx, newDesc) {
    if (formData.sections[sIdx]) {
        formData.sections[sIdx].description = newDesc;
        renderPreview();
    }
}

// Control type icons helper
function getControlIcon(type) {
    switch (type) {
        case 'text': return '<i class="fa-solid fa-font text-secondary"></i>';
        case 'textarea': return '<i class="fa-solid fa-align-left text-secondary"></i>';
        case 'number': return '<i class="fa-solid fa-hashtag text-secondary"></i>';
        case 'date': return '<i class="fa-regular fa-calendar text-primary"></i>';
        case 'time': return '<i class="fa-regular fa-clock text-primary"></i>';
        case 'gps': return '<i class="fa-solid fa-location-crosshairs text-danger"></i>';
        case 'journey_gps': return '<i class="fa-solid fa-route text-success"></i>';
        case 'file': return '<i class="fa-solid fa-camera text-info"></i>';
        case 'multiple_photos': return '<i class="fa-solid fa-images text-info"></i>';
        case 'video': return '<i class="fa-solid fa-video text-danger"></i>';
        case 'signature': return '<i class="fa-solid fa-signature text-success"></i>';
        case 'dropdown': return '<i class="fa-solid fa-list text-warning"></i>';
        case 'radio': return '<i class="fa-regular fa-circle-dot text-warning"></i>';
        case 'checkbox': return '<i class="fa-regular fa-square-check text-warning"></i>';
        case 'switch': return '<i class="fa-solid fa-toggle-on text-success"></i>';
        case 'barcode': return '<i class="fa-solid fa-barcode text-primary"></i>';
        default: return '<i class="fa-solid fa-cube text-secondary"></i>';
    }
}

// Render Builder UI
function renderBuilder() {
    const container = document.getElementById('sectionsContainer');
    container.innerHTML = '';

    formData.sections.forEach((sec, sIdx) => {
        const secCard = document.createElement('div');
        secCard.className = 'card mb-3 shadow-sm';
        secCard.innerHTML = `
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                        <span class="badge bg-primary">Section ${sIdx + 1}</span>
                        <input type="text" class="form-control form-control-sm fw-bold border bg-white" value="${sec.title || 'Untitled Section'}" oninput="updateSectionTitle(${sIdx}, this.value)" style="max-width: 260px;" placeholder="Section Title">
                    </div>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="moveSectionUp(${sIdx})" ${sIdx === 0 ? 'disabled' : ''} title="Move Section Up">
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="moveSectionDown(${sIdx})" ${sIdx === formData.sections.length - 1 ? 'disabled' : ''} title="Move Section Down">
                            <i class="fa-solid fa-arrow-down"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openAddFieldModal(${sIdx})">
                            <i class="fa-solid fa-plus me-1"></i> Add Control
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSection(${sIdx})" title="Delete Section">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="mt-2">
                    <input type="text" class="form-control form-control-sm text-muted bg-white border" value="${sec.description || ''}" placeholder="Optional guidance/instructions for surveyor in this section..." oninput="updateSectionDescription(${sIdx}, this.value)">
                </div>
            </div>
            <div class="card-body p-2">
                <div class="list-group list-group-flush" id="sec-fields-${sIdx}">
                    ${(sec.fields && sec.fields.length > 0) ? sec.fields.map((fld, fIdx) => `
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 border rounded mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fs-5">${getControlIcon(fld.type)}</span>
                                <div>
                                    <div class="fw-bold small">
                                        ${fld.label || 'Untitled Field'}
                                        ${fld.required ? '<span class="text-danger">*</span>' : ''}
                                        ${fld.depends_on ? `<span class="badge bg-info-subtle text-info border border-info-subtle ms-1" style="font-size:10px;"><i class="fa-solid fa-code-branch me-1"></i>If ${fld.depends_on.field} ${fld.depends_on.operator} ${fld.depends_on.value}</span>` : ''}
                                    </div>
                                    <div class="text-muted" style="font-size: 11px;">
                                        <code>${fld.id}</code> &bull; Type: <span class="badge bg-light text-dark border">${fld.type}</span>
                                        ${(fld.min !== undefined || fld.max !== undefined) ? ` &bull; Range: [${fld.min ?? '-∞'}, ${fld.max ?? '+∞'}]` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="moveFieldUp(${sIdx}, ${fIdx})" ${fIdx === 0 ? 'disabled' : ''} title="Move Up">
                                    <i class="fa-solid fa-arrow-up"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="moveFieldDown(${sIdx}, ${fIdx})" ${fIdx === sec.fields.length - 1 ? 'disabled' : ''} title="Move Down">
                                    <i class="fa-solid fa-arrow-down"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="duplicateField(${sIdx}, ${fIdx})" title="Duplicate Control">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="openEditFieldModal(${sIdx}, ${fIdx})" title="Edit">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deleteField(${sIdx}, ${fIdx})" title="Delete">
                                    <i class="fa-solid fa-times"></i>
                                </button>
                            </div>
                        </div>
                    `).join('') : '<div class="text-muted text-center py-3 small">No controls in this section yet. Click "Add Control" above.</div>'}
                </div>
            </div>
        `;
        container.appendChild(secCard);
    });

    renderPreview();
}

// Render Mobile Preview
function renderPreview() {
    document.getElementById('previewTitle').innerText = document.getElementById('formTitle').value || 'Untitled Form';
    document.getElementById('previewDescription').innerText = document.getElementById('formDescription').value || '';

    const previewBody = document.getElementById('previewBody');
    previewBody.innerHTML = '';

    formData.sections.forEach((sec, sIdx) => {
        const secDiv = document.createElement('div');
        secDiv.className = 'bg-white p-3 rounded shadow-sm border mb-3';
        secDiv.innerHTML = `<h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-regular fa-folder-open me-2 text-primary"></i>${sec.title}</h6>`;

        (sec.fields || []).forEach(fld => {
            const fieldDiv = document.createElement('div');
            fieldDiv.className = 'mb-3';

            let controlHtml = '';
            const reqLabel = fld.required ? '<span class="text-danger">*</span>' : '';

            switch (fld.type) {
                case 'text':
                    controlHtml = `<input type="text" class="form-control form-control-sm" placeholder="${fld.placeholder || ''}" disabled>`;
                    break;
                case 'textarea':
                    controlHtml = `<textarea class="form-control form-control-sm" rows="2" placeholder="${fld.placeholder || ''}" disabled></textarea>`;
                    break;
                case 'number':
                    controlHtml = `<input type="number" class="form-control form-control-sm" placeholder="${fld.placeholder || '0'}" disabled>`;
                    break;
                case 'date':
                    controlHtml = `<div class="input-group input-group-sm"><span class="input-group-text"><i class="fa-regular fa-calendar"></i></span><input type="date" class="form-control" disabled></div>`;
                    break;
                case 'time':
                    controlHtml = `<div class="input-group input-group-sm"><span class="input-group-text"><i class="fa-regular fa-clock"></i></span><input type="time" class="form-control" disabled></div>`;
                    break;
                case 'gps':
                    controlHtml = `
                        <div class="border rounded p-2 bg-light text-center">
                            <i class="fa-solid fa-location-crosshairs text-danger mb-1 fs-5"></i>
                            <div class="small fw-bold">GPS Geolocation Widget</div>
                            <small class="text-muted d-block" style="font-size: 11px;">Captures Lat, Lng, Accuracy & Altitude via Native Android Sensor</small>
                            <button class="btn btn-sm btn-outline-danger mt-1 py-0 px-2" disabled><i class="fa-solid fa-satellite-dish me-1"></i> Capture Coordinates</button>
                        </div>
                    `;
                    break;
                case 'journey_gps':
                    controlHtml = `
                        <div class="border rounded p-2 bg-light text-center border-success">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge bg-success-subtle text-success border"><i class="fa-solid fa-satellite-dish me-1"></i> Continuous GPS Tracker</span>
                                <span class="badge bg-secondary">00:00:00</span>
                            </div>
                            <i class="fa-solid fa-route text-success mb-1 fs-5"></i>
                            <div class="small fw-bold text-dark">Journey Map Path Recorder</div>
                            <small class="text-muted d-block" style="font-size: 11px;">Records surveyor route from Start to End while survey is open.</small>
                            <div class="d-flex justify-content-center gap-2 mt-2">
                                <button class="btn btn-sm btn-outline-success py-0 px-2" disabled><i class="fa-solid fa-play me-1"></i> Record Path</button>
                                <button class="btn btn-sm btn-outline-danger py-0 px-2" disabled><i class="fa-solid fa-stop me-1"></i> Stop</button>
                            </div>
                        </div>
                    `;
                    break;
                case 'file':
                    controlHtml = `
                        <div class="border rounded p-2 bg-light text-center">
                            <i class="fa-solid fa-camera text-primary mb-1 fs-5"></i>
                            <div class="small fw-bold">Camera / Photo Attachment</div>
                            <small class="text-muted d-block" style="font-size: 11px;">Snap photo or choose from device gallery</small>
                            <button class="btn btn-sm btn-outline-primary mt-1 py-0 px-2" disabled><i class="fa-solid fa-camera me-1"></i> Take Photo</button>
                        </div>
                    `;
                    break;
                case 'multiple_photos':
                    controlHtml = `
                        <div class="border rounded p-2 bg-light">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-bold text-primary"><i class="fa-solid fa-images me-1"></i> Multiple Photos (Gallery)</span>
                                <span class="badge bg-info">0 captured</span>
                            </div>
                            <small class="text-muted d-block mb-2" style="font-size: 11px;">Take or upload multiple site photos with thumbnail grid.</small>
                            <div class="d-flex gap-1 overflow-hidden" style="max-height: 60px;">
                                <div class="border rounded bg-white p-2 text-center text-muted small flex-grow-1 border-dashed">
                                    <i class="fa-solid fa-plus me-1"></i> Add Photo
                                </div>
                            </div>
                        </div>
                    `;
                    break;
                case 'video':
                    controlHtml = `
                        <div class="border rounded p-2 bg-light text-center border-danger-subtle">
                            <i class="fa-solid fa-video text-danger mb-1 fs-5"></i>
                            <div class="small fw-bold">Video Recorder / Upload</div>
                            <small class="text-muted d-block" style="font-size: 11px;">Record video inspection clip or pick from device.</small>
                            <button class="btn btn-sm btn-outline-danger mt-1 py-0 px-2" disabled><i class="fa-solid fa-record-vinyl me-1"></i> Record Video</button>
                        </div>
                    `;
                    break;
                case 'signature':
                    controlHtml = `
                        <div class="border rounded p-2 bg-light text-center">
                            <i class="fa-solid fa-signature text-success mb-1 fs-5"></i>
                            <div class="small fw-bold">Digital Signature Pad</div>
                            <div style="height: 45px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 4px;" class="my-1 d-flex align-items-center justify-content-center text-muted small">
                                Sign with finger / stylus
                            </div>
                        </div>
                    `;
                    break;
                case 'dropdown':
                    const opts = Array.isArray(fld.options) ? fld.options : (fld.options ? fld.options.split(',') : []);
                    controlHtml = `
                        <select class="form-select form-select-sm" disabled>
                            <option>Select an option...</option>
                            ${opts.map(o => `<option>${o.trim()}</option>`).join('')}
                        </select>
                    `;
                    break;
                case 'radio':
                    const rOpts = Array.isArray(fld.options) ? fld.options : (fld.options ? fld.options.split(',') : []);
                    controlHtml = rOpts.map((o, idx) => `
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="radio" name="preview_${fld.id}" disabled ${idx === 0 ? 'checked' : ''}>
                            <label class="form-check-label small">${o.trim()}</label>
                        </div>
                    `).join('');
                    break;
                case 'checkbox':
                    const cOpts = Array.isArray(fld.options) ? fld.options : (fld.options ? fld.options.split(',') : []);
                    controlHtml = cOpts.map(o => `
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" disabled>
                            <label class="form-check-label small">${o.trim()}</label>
                        </div>
                    `).join('');
                    break;
                case 'switch':
                    controlHtml = `
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" disabled>
                            <label class="form-check-label small text-muted">Yes / No Toggle</label>
                        </div>
                    `;
                    break;
                case 'barcode':
                    controlHtml = `
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa-solid fa-barcode"></i></span>
                            <input type="text" class="form-control" placeholder="Barcode / QR Asset Tag" disabled>
                        </div>
                    `;
                    break;
            }

            fieldDiv.innerHTML = `
                <label class="form-label fw-semibold small mb-1">${fld.label} ${reqLabel}</label>
                ${controlHtml}
                ${fld.help ? `<small class="text-muted d-block mt-1" style="font-size: 11px;">${fld.help}</small>` : ''}
            `;
            secDiv.appendChild(fieldDiv);
        });

        previewBody.appendChild(secDiv);
    });
}

function updateSectionTitle(sIdx, newTitle) {
    formData.sections[sIdx].title = newTitle;
    renderPreview();
}

function deleteSection(sIdx) {
    if (confirm('Delete this entire section and its controls?')) {
        formData.sections.splice(sIdx, 1);
        renderBuilder();
    }
}

function deleteField(sIdx, fIdx) {
    formData.sections[sIdx].fields.splice(fIdx, 1);
    renderBuilder();
}

function moveSectionUp(sIdx) {
    if (sIdx <= 0) return;
    const temp = formData.sections[sIdx];
    formData.sections[sIdx] = formData.sections[sIdx - 1];
    formData.sections[sIdx - 1] = temp;
    renderBuilder();
}

function moveSectionDown(sIdx) {
    if (sIdx >= formData.sections.length - 1) return;
    const temp = formData.sections[sIdx];
    formData.sections[sIdx] = formData.sections[sIdx + 1];
    formData.sections[sIdx + 1] = temp;
    renderBuilder();
}

function moveFieldUp(sIdx, fIdx) {
    if (fIdx <= 0) return;
    const fields = formData.sections[sIdx].fields;
    const temp = fields[fIdx];
    fields[fIdx] = fields[fIdx - 1];
    fields[fIdx - 1] = temp;
    renderBuilder();
}

function moveFieldDown(sIdx, fIdx) {
    const fields = formData.sections[sIdx].fields;
    if (fIdx >= fields.length - 1) return;
    const temp = fields[fIdx];
    fields[fIdx] = fields[fIdx + 1];
    fields[fIdx + 1] = temp;
    renderBuilder();
}

function duplicateField(sIdx, fIdx) {
    const orig = formData.sections[sIdx].fields[fIdx];
    const copy = JSON.parse(JSON.stringify(orig));
    copy.id = orig.id + '_copy_' + Math.random().toString(36).substring(2, 6);
    copy.label = orig.label + ' (Copy)';
    formData.sections[sIdx].fields.splice(fIdx + 1, 0, copy);
    renderBuilder();
}

// JSON Schema Modal Handlers
let jsonModal = null;
function getJsonModal() {
    if (!jsonModal && typeof bootstrap !== 'undefined') {
        const el = document.getElementById('jsonSchemaModal');
        if (el) jsonModal = new bootstrap.Modal(el);
    }
    return jsonModal;
}

function openJsonModal() {
    formData.id = document.getElementById('formId').value.trim();
    formData.title = document.getElementById('formTitle').value.trim();
    formData.category = document.getElementById('formCategory').value.trim();
    formData.description = document.getElementById('formDescription').value.trim();

    document.getElementById('schemaJsonTextarea').value = JSON.stringify(formData, null, 2);
    getJsonModal()?.show();
}

function copySchemaJson() {
    const ta = document.getElementById('schemaJsonTextarea');
    ta.select();
    navigator.clipboard.writeText(ta.value).then(() => {
        alert('Schema JSON copied to clipboard!');
    }).catch(() => {
        document.execCommand('copy');
        alert('Schema JSON copied to clipboard!');
    });
}

function downloadSchemaJson() {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(document.getElementById('schemaJsonTextarea').value);
    const dlAnchorElem = document.createElement('a');
    dlAnchorElem.setAttribute("href", dataStr);
    dlAnchorElem.setAttribute("download", (formData.id || "form_schema") + ".json");
    dlAnchorElem.click();
}

function importSchemaJson() {
    const raw = document.getElementById('schemaJsonTextarea').value.trim();
    try {
        const parsed = JSON.parse(raw);
        if (!parsed.sections || !Array.isArray(parsed.sections)) {
            alert('Invalid schema: Root object must contain a "sections" array.');
            return;
        }
        if (confirm('Import and replace the current form builder contents with this JSON schema?')) {
            formData = parsed;
            if (formData.id) document.getElementById('formId').value = formData.id;
            if (formData.title) document.getElementById('formTitle').value = formData.title;
            if (formData.category) document.getElementById('formCategory').value = formData.category;
            if (formData.description) document.getElementById('formDescription').value = formData.description;
            renderBuilder();
            getJsonModal()?.hide();
        }
    } catch (e) {
        alert('Invalid JSON syntax: ' + e.message);
    }
}

const btnAddSec = document.getElementById('btnAddSection');
if (btnAddSec) {
    btnAddSec.addEventListener('click', addNewSection);
}
const btnQuickAddSec = document.getElementById('btnQuickAddSection');
if (btnQuickAddSec) {
    btnQuickAddSec.addEventListener('click', addNewSection);
}

// Field Modal handlers
document.getElementById('modalFieldType').addEventListener('change', function() {
    const val = this.value;
    const isChoice = ['dropdown', 'radio', 'checkbox'].includes(val);
    document.getElementById('optionsGroup').style.display = isChoice ? 'block' : 'none';
    const isNumeric = val === 'number';
    document.getElementById('modalFieldMin').parentElement.style.display = isNumeric ? 'block' : 'none';
    document.getElementById('modalFieldMax').parentElement.style.display = isNumeric ? 'block' : 'none';
});

document.getElementById('modalEnableSkipLogic').addEventListener('change', function() {
    document.getElementById('skipLogicGroup').style.display = this.checked ? 'block' : 'none';
});

function openAddFieldModal(sIdx) {
    document.getElementById('fieldModalTitle').innerHTML = '<i class="fa-solid fa-plus me-2 text-primary"></i>Add Control';
    document.getElementById('modalSectionIdx').value = sIdx;
    document.getElementById('modalFieldIdx').value = '-1';
    document.getElementById('modalFieldType').value = 'text';
    document.getElementById('modalFieldLabel').value = '';
    document.getElementById('modalFieldId').value = '';
    document.getElementById('modalFieldOptions').value = '';
    document.getElementById('modalFieldMin').value = '';
    document.getElementById('modalFieldMax').value = '';
    document.getElementById('modalFieldPattern').value = '';
    document.getElementById('modalEnableSkipLogic').checked = false;
    document.getElementById('skipLogicGroup').style.display = 'none';
    document.getElementById('modalDepField').value = '';
    document.getElementById('modalDepOperator').value = '==';
    document.getElementById('modalDepValue').value = '';
    document.getElementById('modalFieldPlaceholder').value = '';
    document.getElementById('modalFieldHelp').value = '';
    document.getElementById('modalFieldRequired').checked = true;
    document.getElementById('optionsGroup').style.display = 'none';
    getFieldModal()?.show();
}

function openEditFieldModal(sIdx, fIdx) {
    const fld = formData.sections[sIdx].fields[fIdx];
    document.getElementById('fieldModalTitle').innerHTML = '<i class="fa-solid fa-pen me-2 text-primary"></i>Edit Control';
    document.getElementById('modalSectionIdx').value = sIdx;
    document.getElementById('modalFieldIdx').value = fIdx;
    document.getElementById('modalFieldType').value = fld.type;
    document.getElementById('modalFieldLabel').value = fld.label || '';
    document.getElementById('modalFieldId').value = fld.id || '';
    document.getElementById('modalFieldPlaceholder').value = fld.placeholder || '';
    document.getElementById('modalFieldHelp').value = fld.help || '';
    document.getElementById('modalFieldRequired').checked = !!fld.required;

    // Constraints
    document.getElementById('modalFieldMin').value = (fld.min !== undefined && fld.min !== null) ? fld.min : '';
    document.getElementById('modalFieldMax').value = (fld.max !== undefined && fld.max !== null) ? fld.max : '';
    document.getElementById('modalFieldPattern').value = fld.pattern || '';

    // Skip Logic
    if (fld.depends_on) {
        document.getElementById('modalEnableSkipLogic').checked = true;
        document.getElementById('skipLogicGroup').style.display = 'block';
        document.getElementById('modalDepField').value = fld.depends_on.field || '';
        document.getElementById('modalDepOperator').value = fld.depends_on.operator || '==';
        document.getElementById('modalDepValue').value = (fld.depends_on.value !== undefined) ? fld.depends_on.value : '';
    } else {
        document.getElementById('modalEnableSkipLogic').checked = false;
        document.getElementById('skipLogicGroup').style.display = 'none';
        document.getElementById('modalDepField').value = '';
        document.getElementById('modalDepOperator').value = '==';
        document.getElementById('modalDepValue').value = '';
    }

    const isChoice = ['dropdown', 'radio', 'checkbox'].includes(fld.type);
    document.getElementById('optionsGroup').style.display = isChoice ? 'block' : 'none';
    if (Array.isArray(fld.options)) {
        document.getElementById('modalFieldOptions').value = fld.options.join(', ');
    } else {
        document.getElementById('modalFieldOptions').value = fld.options || '';
    }

    getFieldModal()?.show();
}

document.getElementById('btnSaveFieldModal').addEventListener('click', () => {
    const sIdx = parseInt(document.getElementById('modalSectionIdx').value);
    const fIdx = parseInt(document.getElementById('modalFieldIdx').value);
    const type = document.getElementById('modalFieldType').value;
    const label = document.getElementById('modalFieldLabel').value.trim();
    let id = document.getElementById('modalFieldId').value.trim();
    const placeholder = document.getElementById('modalFieldPlaceholder').value.trim();
    const help = document.getElementById('modalFieldHelp').value.trim();
    const required = document.getElementById('modalFieldRequired').checked;
    const optionsRaw = document.getElementById('modalFieldOptions').value.trim();

    if (!label) {
        alert('Please specify a Field Label');
        return;
    }

    if (!id) {
        id = label.toLowerCase().replace(/[^a-z0-9]/g, '_').substring(0, 30);
    }

    const fieldObj = {
        id: id,
        type: type,
        label: label,
        required: required
    };

    if (placeholder) fieldObj.placeholder = placeholder;
    if (help) fieldObj.help = help;

    const minVal = document.getElementById('modalFieldMin').value.trim();
    const maxVal = document.getElementById('modalFieldMax').value.trim();
    const patternVal = document.getElementById('modalFieldPattern').value.trim();
    if (minVal !== '') fieldObj.min = parseFloat(minVal);
    if (maxVal !== '') fieldObj.max = parseFloat(maxVal);
    if (patternVal !== '') fieldObj.pattern = patternVal;

    // Skip Logic
    const hasSkipLogic = document.getElementById('modalEnableSkipLogic').checked;
    const depField = document.getElementById('modalDepField').value.trim();
    const depOp = document.getElementById('modalDepOperator').value;
    const depVal = document.getElementById('modalDepValue').value.trim();

    if (hasSkipLogic && depField) {
        let parsedVal = depVal;
        if (depVal.toLowerCase() === 'true') parsedVal = true;
        else if (depVal.toLowerCase() === 'false') parsedVal = false;
        else if (!isNaN(Number(depVal)) && depVal !== '') parsedVal = Number(depVal);

        fieldObj.depends_on = {
            field: depField,
            operator: depOp,
            value: parsedVal
        };
    }

    if (['dropdown', 'radio', 'checkbox'].includes(type)) {
        if (!optionsRaw) {
            alert('Please specify options for this selection field');
            return;
        }
        fieldObj.options = optionsRaw.split(',').map(s => s.trim()).filter(s => s.length > 0);
    }

    if (type === 'file') {
        fieldObj.file_type = 'image';
    }

    if (fIdx === -1) {
        formData.sections[sIdx].fields.push(fieldObj);
    } else {
        formData.sections[sIdx].fields[fIdx] = fieldObj;
    }

    getFieldModal()?.hide();
    renderBuilder();
});

// Auto generate Field ID from label in modal
document.getElementById('modalFieldLabel').addEventListener('input', function() {
    if (document.getElementById('modalFieldIdx').value === '-1') {
        const val = this.value.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').substring(0, 30);
        document.getElementById('modalFieldId').value = val;
    }
});

function toggleBuilderAccessPanels() {
    const accessType = document.getElementById('formAccessType').value;
    const usersPanel = document.getElementById('builderPanelUsers');
    const rolesPanel = document.getElementById('builderPanelRoles');

    if (usersPanel) {
        if (accessType === 'specific_users') usersPanel.classList.remove('d-none');
        else usersPanel.classList.add('d-none');
    }

    if (rolesPanel) {
        if (accessType === 'specific_roles') rolesPanel.classList.remove('d-none');
        else rolesPanel.classList.add('d-none');
    }
}

// Save to Server
document.getElementById('btnSaveForm').addEventListener('click', async function() {
    const formId = document.getElementById('formId').value.trim();
    const formTitle = document.getElementById('formTitle').value.trim();
    const formCategory = document.getElementById('formCategory').value.trim();
    const formDescription = document.getElementById('formDescription').value.trim();
    const accessType = document.getElementById('formAccessType')?.value || 'all';

    const assignedUsers = Array.from(document.querySelectorAll('.builder-user-cb:checked')).map(cb => parseInt(cb.value));
    const assignedRoles = Array.from(document.querySelectorAll('.builder-role-cb:checked')).map(cb => cb.value);

    if (!formId || !formTitle) {
        alert('Form ID and Form Title are required.');
        return;
    }

    formData.id = formId;
    formData.title = formTitle;
    formData.category = formCategory;
    formData.description = formDescription;

    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

    try {
        const response = await fetch('api/forms.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + <?= json_encode($currentUser['api_token'] ?? '') ?>,
                'X-CSRF-Token': <?= json_encode(generateCsrfToken()) ?>
            },
            body: JSON.stringify({
                id: formId,
                title: formTitle,
                category: formCategory,
                description: formDescription,
                is_active: 1,
                created_by: <?= json_encode($currentUser['id']) ?>,
                access_type: accessType,
                assigned_users: accessType === 'specific_users' ? assignedUsers : null,
                assigned_roles: accessType === 'specific_roles' ? assignedRoles : null,
                schema: formData
            })
        });

        const result = await response.json();
        if (result.status === 'success') {
            document.getElementById('alertContainer').innerHTML = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i> Form saved and published! Mobile devices can now fetch it via <code>/api/forms.php</code>.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            setTimeout(() => {
                window.location.href = 'forms.php';
            }, 1200);
        } else {
            alert('Error saving form: ' + (result.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Failed to connect to API: ' + e.message);
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save & Publish Form';
    }
});

document.getElementById('formTitle').addEventListener('input', renderPreview);
document.getElementById('formDescription').addEventListener('input', renderPreview);

// Initial Render
renderBuilder();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
