<?php

declare(strict_types=1);

function renderStatusPage(array $config): void
{
    session_start();
    require __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/user_profile.php';

    if (!isset($_SESSION['user_login'])) {
        header('Location: login.php');
        exit;
    }

    if (!isset($_SESSION['exam_year'])) {
        header('Location: import_gptV1.php');
        exit;
    }

    $userProfile = getCurrentUserProfile($conn);

    $required = ['page_title', 'page_file', 'status_column', 'note_column', 'update_endpoint'];
    foreach ($required as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('Missing config key: ' . $key);
        }
    }

    $statusColumn = (string) $config['status_column'];
    $noteColumn = (string) $config['note_column'];

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $statusColumn) || !preg_match('/^[a-zA-Z0-9_]+$/', $noteColumn)) {
        throw new RuntimeException('Invalid column name in config');
    }

    $h = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };

    $statusText = array_merge(
        ['W' => 'รอดำเนินการ', 'P' => 'ผ่าน', 'F' => 'ไม่ผ่าน'],
        $config['status_text'] ?? []
    );
    $stageOrder = [
        'submit_doc',
        'lab_check',
        'swim_test',
        'run_test',
        'station3_test',
        'hospital_check',
        'fingerprint_check',
        'background_check',
        'interview',
        'militarydoc',
    ];
    $currentStageIndex = array_search($statusColumn, $stageOrder, true);
    $stageScopeSql = '';
    if ($currentStageIndex !== false) {
        $previousStages = array_slice($stageOrder, 0, $currentStageIndex);
        $hasAnyFailExpr = '(' . implode(" = 'F' OR ", $stageOrder) . " = 'F')";
        $noAnyFailExpr = "NOT $hasAnyFailExpr";

        $firstFailIsCurrentExprParts = [];
        foreach ($previousStages as $stage) {
            $firstFailIsCurrentExprParts[] = "$stage <> 'F'";
        }
        $firstFailIsCurrentExprParts[] = "$statusColumn = 'F'";
        $firstFailIsCurrentExpr = '(' . implode(' AND ', $firstFailIsCurrentExprParts) . ')';

        // ถ้ายังไม่มี F เลย ให้แสดงทุกด่าน (รองรับกรอกข้ามด่าน)
        // ถ้ามี F แล้ว ให้แสดงเฉพาะด่าน F แรก
        $stageScopeSql = "($firstFailIsCurrentExpr OR $noAnyFailExpr)";
    }

    $noteOptions = array_values(array_filter(array_map('strval', $config['note_options'] ?? [])));
    $requiredNoteStatuses = array_values(array_filter(array_map('strval', $config['required_note_statuses'] ?? ['F'])));
    $editableNoteStatuses = array_values(array_filter(array_map('strval', $config['editable_note_statuses'] ?? $requiredNoteStatuses)));
    $showNoteStatuses = array_values(array_filter(array_map('strval', $config['show_note_statuses'] ?? ['F'])));

    $examYear = (string) $_SESSION['exam_year'];
    $limit = 20;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $allowedStatus = ['W', 'P', 'F'];
    if (!in_array($statusFilter, $allowedStatus, true)) {
        $statusFilter = '';
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    if (mb_strlen($search) > 100) {
        $search = mb_substr($search, 0, 100);
    }

    $whereParts = ["id <> 'id'", 'exam_year = :exam_year'];
    if ($stageScopeSql !== '') {
        $whereParts[] = $stageScopeSql;
    }
    $queryParams = [':exam_year' => $examYear];

    if ($statusFilter !== '') {
        $whereParts[] = "$statusColumn = :status";
        $queryParams[':status'] = $statusFilter;
    }

    if ($search !== '') {
        $whereParts[] = '(idcode LIKE :search OR firstname LIKE :search OR lastname LIKE :search)';
        $queryParams[':search'] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $whereParts);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM applicantname WHERE $whereSql");
    foreach ($queryParams as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRows = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalRows / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $listStmt = $conn->prepare("
        SELECT
            a.id,
            a.idcode,
            a.prefix,
            a.firstname,
            a.lastname,
            $statusColumn AS step_status,
            $noteColumn AS step_note,
            (
                SELECT COUNT(*)
                FROM applicantname a2
                WHERE a2.id <> 'id'
                  AND a2.exam_year = a.exam_year
                  AND CAST(a2.id AS UNSIGNED) <= CAST(a.id AS UNSIGNED)
            ) AS global_order_no
        FROM applicantname a
        WHERE $whereSql
        ORDER BY CAST(a.id AS UNSIGNED)
        LIMIT :limit OFFSET :offset
    ");

    foreach ($queryParams as $key => $value) {
        $listStmt->bindValue($key, $value);
    }
    $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $statusWhere = "id <> 'id' AND exam_year = :exam_year";
    if ($stageScopeSql !== '') {
        $statusWhere .= " AND $stageScopeSql";
    }
    $statusStmt = $conn->prepare("\n        SELECT\n            SUM(CASE WHEN $statusColumn = 'W' THEN 1 ELSE 0 END) AS waiting_count,\n            SUM(CASE WHEN $statusColumn = 'P' THEN 1 ELSE 0 END) AS pass_count,\n            SUM(CASE WHEN $statusColumn = 'F' THEN 1 ELSE 0 END) AS fail_count\n        FROM applicantname\n        WHERE $statusWhere\n    ");
    $statusStmt->bindValue(':exam_year', $examYear);
    $statusStmt->execute();
    $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $statusCount = [
        'W' => (int) ($statusRow['waiting_count'] ?? 0),
        'P' => (int) ($statusRow['pass_count'] ?? 0),
        'F' => (int) ($statusRow['fail_count'] ?? 0),
    ];

    $baseQuery = ['exam_year' => $examYear];
    if ($statusFilter !== '') {
        $baseQuery['status'] = $statusFilter;
    }
    if ($search !== '') {
        $baseQuery['search'] = $search;
    }

    $range = 5;
    $startPage = max(1, $page - (int) floor($range / 2));
    $endPage = min($totalPages, $startPage + $range - 1);
    if ($endPage - $startPage + 1 < $range) {
        $startPage = max(1, $endPage - $range + 1);
    }

    $menuItems = [
        ['file' => 'All name.php', 'label' => 'รายชื่อทั้งหมด'],
        ['file' => 'document.php', 'label' => 'ยื่นเอกสาร'],
        ['file' => 'lab_check.php', 'label' => 'ตรวจ LAB'],
        ['file' => 'swim.php', 'label' => 'ว่ายน้ำ'],
        ['file' => 'run.php', 'label' => 'วิ่ง'],
        ['file' => '3station.php', 'label' => '๓ สถานี'],
        ['file' => 'hospital_check.php', 'label' => 'ตรวจร่างกาย รพ.ตร.'],
        ['file' => 'fingerprint_check.php', 'label' => 'ตรวจลายนิ้วมือ ศพฐ.'],
        ['file' => 'background_check.php', 'label' => 'ตรวจประวัติทางคดี'],
        ['file' => 'interview.php', 'label' => 'สัมภาษณ์'],
        ['file' => 'militarydoc.php', 'label' => 'เอกสารทางทหาร'],
        ['file' => 'Step.php', 'label' => 'สรุปผลรายขั้นตอน'],
        ['file' => 'selected.php', 'label' => 'ผู้ได้รับการคัดเลือก'],
        ['file' => 'final.php', 'label' => 'สรุปข้อมูลการสอบ นสต.'],
    ];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h((string) $config['page_title']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
    <link href="assets/css/status-page.css" rel="stylesheet">
</head>

<body>
    <header class="top-header">
        <div class="brand-wrap">
            <a href="menu.php" class="logo-link" aria-label="กลับหน้าเมนูหลัก">
                <img src="upload/tcpr5-1024x990.png" class="logo" alt="ตราศูนย์ฝึกอบรมตำรวจภูธรภาค 5">
            </a>
            <div>
                <h1>ศูนย์ฝึกอบรมตำรวจภูธรภาค ๕</h1>
                <p>TRAINING CENTER OF PROVINCIAL POLICE REGION 5</p>
            </div>
        </div>
        <div class="header-right">
            <a class="header-home" href="menu.php" aria-label="กลับหน้าเมนูหลัก" style="color:#fff;">
                <i class="bi bi-house-door-fill" style="color:#fff;"></i>
            </a>
            <div class="header-meta">
                <strong><?= $h((string) $config['page_title']) ?></strong>
                <span>นสต.รุ่นที่: <?= $h($examYear) ?></span>
            </div>
           
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= $h($userProfile['firstname']) ?></span>
                    <i class="bi bi-caret-down-fill"></i>
                </button>
                <div id="profileCard" class="profile-card">
                    <p class="profile-name"><?= $h($userProfile['fullname']) ?></p>
                    <?php if ($userProfile['username'] !== ''): ?>
                        <p class="profile-username">@<?= $h($userProfile['username']) ?></p>
                    <?php endif; ?>
                    <a class="logout-btn" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar">
            <div class="menu-title">เมนู</div>
            <?php foreach ($menuItems as $menu): ?>
                <a class="menu-btn <?= $menu['file'] === $config['page_file'] ? 'active' : '' ?>" href="<?= $h($menu['file']) ?>"><?= $h($menu['label']) ?></a>
            <?php endforeach; ?>
        </aside>

        <main class="content">
            <div class="toolbar">
                <form method="GET" class="search-box">
                    <input type="hidden" name="exam_year" value="<?= $h($examYear) ?>">
                    <?php if ($statusFilter !== ''): ?>
                        <input type="hidden" name="status" value="<?= $h($statusFilter) ?>">
                    <?php endif; ?>
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= $h($search) ?>" placeholder="ค้นหาเลขสอบ / ชื่อ / นามสกุล">
                    <button type="submit" class="btn btn-sm btn-danger">ค้นหา</button>
                </form>

                <div class="toolbar-actions">
                    <label class="check-all">
                        <input type="checkbox" id="checkAll">
                        เลือกทั้งหมด
                    </label>
                    <button class="edit-btn" type="button" onclick="openPopup()">แก้ไขสถานะ</button>
                </div>
            </div>

            <div class="status-bar">
                <a class="badge text-bg-dark" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search])) ?>">แสดงทั้งหมด</a>
                <a class="badge text-bg-secondary" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'W'])) ?>">รอดำเนินการ <?= $statusCount['W'] ?></a>
                <a class="badge text-bg-success" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'P'])) ?>">ผ่าน <?= $statusCount['P'] ?></a>
                <a class="badge text-bg-danger" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'F'])) ?>">ไม่ผ่าน <?= $statusCount['F'] ?></a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>ลำดับ</th>
                            <th>เลขประจำตัวสอบ</th>
                            <th>คำนำหน้า</th>
                            <th>ชื่อ</th>
                            <th>นามสกุล</th>
                            <th>สถานะ</th>
                            <th>แก้ไข</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="8" class="empty-row">ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $status = (string) ($row['step_status'] ?? 'W');
                            if (!in_array($status, ['W', 'P', 'F'], true)) {
                                $status = 'W';
                            }
                            $note = trim((string) ($row['step_note'] ?? ''));
                            $shouldShowNote = in_array($status, $showNoteStatuses, true) && $note !== '';
                            ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        class="row-check"
                                        data-id="<?= $h((string) $row['id']) ?>"
                                        data-order="<?= (int) ($row['global_order_no'] ?? ($offset + $index + 1)) ?>"
                                        data-idcode="<?= $h((string) $row['idcode']) ?>"
                                        data-prefix="<?= $h((string) $row['prefix']) ?>"
                                        data-firstname="<?= $h((string) $row['firstname']) ?>"
                                        data-lastname="<?= $h((string) $row['lastname']) ?>"
                                        data-status="<?= $h($status) ?>"
                                        data-note="<?= $h($note) ?>">
                                </td>
                                <td><?= (int) ($row['global_order_no'] ?? ($offset + $index + 1)) ?></td>
                                <td><?= $h((string) $row['idcode']) ?></td>
                                <td><?= $h((string) $row['prefix']) ?></td>
                                <td><?= $h((string) $row['firstname']) ?></td>
                                <td><?= $h((string) $row['lastname']) ?></td>
                                <td class="status-<?= $h($status) ?>">
                                    <?= $h($statusText[$status] ?? 'รอดำเนินการ') ?>
                                    <?php if ($shouldShowNote): ?>
                                        <div class="status-note"><?= $h($note) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button
                                        class="edit-btn"
                                        type="button"
                                        onclick="openPopup(this)"
                                        data-id="<?= $h((string) $row['id']) ?>"
                                        data-order="<?= (int) ($row['global_order_no'] ?? ($offset + $index + 1)) ?>"
                                        data-idcode="<?= $h((string) $row['idcode']) ?>"
                                        data-prefix="<?= $h((string) $row['prefix']) ?>"
                                        data-firstname="<?= $h((string) $row['firstname']) ?>"
                                        data-lastname="<?= $h((string) $row['lastname']) ?>"
                                        data-status="<?= $h($status) ?>"
                                        data-note="<?= $h($note) ?>">
                                        แก้ไข
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrap">
                <span>ทั้งหมด <?= number_format($totalRows) ?> รายการ</span>
                <div class="pagination-controls">
                    <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => max(1, $page - 1)])) ?>">
                        <button <?= $page <= 1 ? 'disabled' : '' ?>>◀</button>
                    </a>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => $i])) ?>">
                            <button class="<?= $i === $page ? 'active-page' : '' ?>"><?= $i ?></button>
                        </a>
                    <?php endfor; ?>

                    <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => min($totalPages, $page + 1)])) ?>">
                        <button <?= $page >= $totalPages ? 'disabled' : '' ?>>▶</button>
                    </a>
                </div>
            </div>
        </main>
    </div>

    <div id="popup" class="popup-overlay" style="display:none;">
        <div class="popup-box">
            <form id="editForm">
                <div class="popup-head-row">
                    <h5 class="popup-title">แก้ไขสถานะ<?= $h((string) $config['page_title']) ?></h5>
                    <button id="markAllPassBtn" type="button" class="edit-btn">คลิกผ่านทั้งหมด</button>
                </div>
                <table class="popup-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>เลขสอบ</th>
                            <th>คำนำหน้า</th>
                            <th>ชื่อ</th>
                            <th>นามสกุล</th>
                            <th>รอดำเนินการ</th>
                            <th>ผ่าน</th>
                            <th>ไม่ผ่าน</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody id="popupBody"></tbody>
                </table>

                <div class="popup-actions">
                    <button id="confirmBtn" type="submit" class="confirm-btn">ยืนยัน</button>
                    <button type="button" class="cancel-btn" onclick="closePopup()">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const popup = document.getElementById('popup');
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');
        const checkAll = document.getElementById('checkAll');
        const popupBody = document.getElementById('popupBody');
        const editForm = document.getElementById('editForm');
        const confirmBtn = document.getElementById('confirmBtn');
        const markAllPassBtn = document.getElementById('markAllPassBtn');

        const failReasons = <?= json_encode($noteOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const requiredNoteStatuses = <?= json_encode($requiredNoteStatuses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const editableNoteStatuses = <?= json_encode($editableNoteStatuses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const updateEndpoint = <?= json_encode((string) $config['update_endpoint']) ?>;

        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach((checkbox) => {
                checkbox.checked = this.checked;
            });
        });

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });

        function createStatusRadio(groupName, value, currentValue) {
            const input = document.createElement('input');
            input.type = 'radio';
            input.name = groupName;
            input.value = value;
            input.checked = currentValue === value;
            return input;
        }

        function createNoteCell(status, note) {
            const cell = document.createElement('td');

            const select = document.createElement('select');
            select.className = 'note-select form-select form-select-sm';
            select.disabled = !editableNoteStatuses.includes(status);

            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = '-- เลือกเหตุผล --';
            select.appendChild(defaultOption);

            failReasons.forEach((reason) => {
                const option = document.createElement('option');
                option.value = reason;
                option.textContent = reason;
                select.appendChild(option);
            });

            const otherOption = document.createElement('option');
            otherOption.value = 'OTHER';
            otherOption.textContent = 'อื่น ๆ (กรอกเพิ่ม)';
            select.appendChild(otherOption);

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'note-input form-control form-control-sm mt-1';
            input.placeholder = 'กรอกหมายเหตุ';

            if (note && failReasons.includes(note)) {
                select.value = note;
                input.style.display = 'none';
                input.value = '';
            } else if (note) {
                select.value = 'OTHER';
                input.style.display = 'block';
                input.value = note;
            } else {
                select.value = '';
                input.style.display = 'none';
                input.value = '';
            }

            cell.appendChild(select);
            cell.appendChild(input);
            return cell;
        }

        function addPopupRow(data) {
            const row = document.createElement('tr');
            row.dataset.id = data.id;
            row.dataset.oldStatus = data.status || 'W';
            row.dataset.oldNote = data.note || '';

            const orderCell = document.createElement('td');
            orderCell.textContent = data.order || String(popupBody.children.length + 1);
            row.appendChild(orderCell);

            const idCodeCell = document.createElement('td');
            idCodeCell.textContent = data.idcode || '';
            row.appendChild(idCodeCell);

            const prefixCell = document.createElement('td');
            prefixCell.textContent = data.prefix || '';
            row.appendChild(prefixCell);

            const firstnameCell = document.createElement('td');
            firstnameCell.textContent = data.firstname || '';
            row.appendChild(firstnameCell);

            const lastnameCell = document.createElement('td');
            lastnameCell.textContent = data.lastname || '';
            row.appendChild(lastnameCell);

            const groupName = `status_${data.id}`;
            ['W', 'P', 'F'].forEach((status) => {
                const cell = document.createElement('td');
                cell.appendChild(createStatusRadio(groupName, status, data.status || 'W'));
                row.appendChild(cell);
            });

            row.appendChild(createNoteCell(data.status || 'W', data.note || ''));
            popupBody.appendChild(row);
        }

        function setRowStatusToPass(row) {
            const passRadio = row.querySelector('input[type="radio"][value="P"]');
            if (!passRadio) {
                return;
            }

            passRadio.checked = true;

            const select = row.querySelector('.note-select');
            const input = row.querySelector('.note-input');
            if (!select || !input) {
                return;
            }

            if (editableNoteStatuses.includes('P')) {
                select.disabled = false;
                return;
            }

            select.disabled = true;
            select.value = '';
            input.value = '';
            input.style.display = 'none';
        }

        function openPopup(button = null) {
            popupBody.innerHTML = '';

            const selectedRows = Array.from(document.querySelectorAll('.row-check:checked'));
            if (selectedRows.length > 0) {
                selectedRows.forEach((checkbox) => addPopupRow(checkbox.dataset));
            } else if (button !== null) {
                addPopupRow(button.dataset);
            } else {
                alert('กรุณาเลือกรายการที่ต้องการแก้ไข');
                return;
            }

            popup.style.display = 'flex';
        }

        function closePopup() {
            popup.style.display = 'none';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'ยืนยัน';
        }

        markAllPassBtn.addEventListener('click', function() {
            const rows = Array.from(document.querySelectorAll('#popupBody tr'));
            if (rows.length === 0) {
                return;
            }

            rows.forEach((row) => setRowStatusToPass(row));
        });

        popup.addEventListener('click', function(event) {
            if (event.target === popup) {
                closePopup();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && popup.style.display === 'flex') {
                closePopup();
            }
        });

        document.addEventListener('change', function(event) {
            if (event.target.type === 'radio') {
                const row = event.target.closest('tr');
                if (!row) {
                    return;
                }

                const status = event.target.value;
                const select = row.querySelector('.note-select');
                const input = row.querySelector('.note-input');

                if (editableNoteStatuses.includes(status)) {
                    select.disabled = false;
                    return;
                }

                select.disabled = true;
                select.value = '';
                input.value = '';
                input.style.display = 'none';
            }

            if (event.target.classList.contains('note-select')) {
                const row = event.target.closest('tr');
                if (!row) {
                    return;
                }

                const input = row.querySelector('.note-input');
                if (event.target.value === 'OTHER') {
                    input.style.display = 'block';
                    input.focus();
                    return;
                }

                input.style.display = 'none';
                input.value = '';
            }
        });

        function collectChanges() {
            const changes = [];
            const popupRows = Array.from(document.querySelectorAll('#popupBody tr'));

            popupRows.forEach((row) => {
                const selectedStatus = row.querySelector('input[type="radio"]:checked')?.value || 'W';
                const select = row.querySelector('.note-select');
                const input = row.querySelector('.note-input');

                let note = '';
                if (editableNoteStatuses.includes(selectedStatus)) {
                    note = select.value === 'OTHER' ? input.value.trim() : select.value;
                    if (requiredNoteStatuses.includes(selectedStatus) && !note) {
                        throw new Error('กรุณาเลือกหรือกรอกหมายเหตุให้ครบถ้วน');
                    }
                }

                const oldStatus = row.dataset.oldStatus || 'W';
                const oldNote = row.dataset.oldNote || '';

                if (selectedStatus !== oldStatus || note !== oldNote) {
                    changes.push({
                        id: row.dataset.id,
                        status: selectedStatus,
                        note: note,
                    });
                }
            });

            return changes;
        }

        editForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            let payload = [];
            try {
                payload = collectChanges();
            } catch (error) {
                alert(error.message || 'ข้อมูลไม่ถูกต้อง');
                return;
            }

            if (payload.length === 0) {
                alert('ไม่มีรายการเปลี่ยนแปลง');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'กำลังบันทึก...';

            try {
                const response = await fetch(updateEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'บันทึกไม่สำเร็จ');
                }

                alert('บันทึกข้อมูลเรียบร้อย');
                window.location.reload();
            } catch (error) {
                alert(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'ยืนยัน';
            }
        });
    </script>
</body>

</html>
<?php
}
