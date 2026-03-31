<?php
require_once __DIR__ . '/includes/bootstrap.php';
secureSessionStart();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/ensure_user_reset_schema.php';

ensureUserResetSchema($conn);

if (!isset($_SESSION['admin_login'])) {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบ!';
    header('location: login.php');
    exit;
}

if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$formatAuditValue = static function ($value): string {
    if (is_array($value)) {
        return implode(', ', array_map('strval', $value));
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '-';
    }

    $statusMap = [
        'P' => 'ผ่าน/อนุมัติ',
        'F' => 'ไม่ผ่าน/ไม่อนุมัติ',
        'W' => 'รอดำเนินการ/รอยืนยัน',
    ];

    return $statusMap[$text] ?? $text;
};

$translateAuditRow = static function (array $auditRow) use ($formatAuditValue): array {
    $action = trim((string) ($auditRow['action'] ?? ''));
    $targetType = trim((string) ($auditRow['target_type'] ?? ''));
    $targetId = trim((string) ($auditRow['target_id'] ?? ''));
    $decodedDetails = json_decode((string) ($auditRow['details'] ?? ''), true);
    $details = is_array($decodedDetails) ? $decodedDetails : [];

    $actionText = $action !== '' ? $action : 'ทำรายการ';
    $targetText = '-';
    $detailText = '-';

    switch ($action) {
        case 'login_success':
            $actionText = 'เข้าสู่ระบบสำเร็จ';
            $targetText = 'บัญชีผู้ใช้งาน';
            $detailText = 'เข้าสู่ระบบสำเร็จ';
            break;
        case 'login_failed_password':
            $actionText = 'เข้าสู่ระบบไม่สำเร็จ';
            $targetText = 'บัญชีผู้ใช้งาน';
            $detailText = 'รหัสผ่านไม่ถูกต้อง';
            break;
        case 'login_failed_user_not_found':
            $actionText = 'เข้าสู่ระบบไม่สำเร็จ';
            $targetText = 'บัญชีผู้ใช้งาน';
            $detailText = 'ไม่พบชื่อผู้ใช้ในระบบ';
            break;
        case 'login_failed_exception':
            $actionText = 'เข้าสู่ระบบไม่สำเร็จ';
            $targetText = 'ระบบยืนยันตัวตน';
            $detailText = 'เกิดข้อผิดพลาดในระบบระหว่างเข้าสู่ระบบ';
            break;
        case 'login_rate_limited':
            $actionText = 'จำกัดความถี่การเข้าสู่ระบบ';
            $targetText = 'ระบบยืนยันตัวตน';
            $detailText = 'พยายามเข้าสู่ระบบบ่อยเกินกำหนด';
            break;
        case 'import_applicants':
            $actionText = 'นำเข้าข้อมูลผู้สมัคร';
            $targetText = isset($details['exam_year']) ? 'นสต.รุ่นที่ ' . $formatAuditValue($details['exam_year']) : 'ข้อมูลผู้สมัคร';
            $detailText = 'เพิ่มใหม่ ' . $formatAuditValue($details['inserted'] ?? 0)
                . ' รายการ, อัปเดต ' . $formatAuditValue($details['updated'] ?? 0)
                . ' รายการ, ข้าม ' . $formatAuditValue($details['skipped'] ?? 0) . ' รายการ';
            break;
        case 'admin_update_user':
            $actionText = 'แก้ไขข้อมูลผู้ใช้';
            $targetText = 'ผู้ใช้ ' . ($details['username'] ?? $targetId ?: '-');
            $detailText = 'ปรับข้อมูลตำแหน่ง/ชื่อผู้ใช้/อีเมล';
            break;
        case 'admin_update_user_status':
            $actionText = 'แก้ไขสถานะการเข้าใช้';
            $targetText = 'ผู้ใช้ ' . ($details['fullname'] ?? $details['username'] ?? $targetId ?: '-');
            $detailText = 'เปลี่ยนสถานะเป็น ' . $formatAuditValue($details['userstatus'] ?? '-');
            break;
        case 'admin_reject_user':
            $actionText = 'ไม่อนุมัติผู้ใช้';
            $targetText = 'ผู้ใช้ ' . ($details['fullname'] ?? $details['username'] ?? $targetId ?: '-');
            $detailText = 'ลบผู้ใช้ออกจากฐานข้อมูล';
            break;
        case 'admin_delete_exam_year':
            $actionText = 'ลบข้อมูลปีนสต';
            $targetText = $targetId !== '' ? 'นสต.รุ่นที่ ' . $targetId : 'ข้อมูลปีนสต';
            $detailText = 'ลบข้อมูลทั้งหมด ' . $formatAuditValue($details['deleted_rows'] ?? 0) . ' รายการ';
            break;
        case 'update_stage_status':
            $actionText = 'อัปเดตผลรายด่าน';
            $targetText = ($details['stage_name'] ?? $targetType ?: 'ด่าน') . ' / ผู้สมัคร ' . ($details['applicant_id'] ?? $targetId ?: '-');
            $detailText = 'เปลี่ยนสถานะเป็น ' . $formatAuditValue($details['status'] ?? '-')
                . ' และหมายเหตุ ' . $formatAuditValue($details['note'] ?? '-');
            break;
        case 'update_applicant_names':
            $actionText = 'แก้ไขข้อมูลชื่อผู้สมัคร';
            $targetText = 'ผู้สมัคร ' . ($targetId !== '' ? $targetId : '-');
            $detailText = 'ปรับชื่อ-นามสกุล/ข้อมูลพื้นฐานของผู้สมัคร';
            break;
        case 'forgot_password_otp_sent':
            $actionText = 'ส่ง OTP รีเซ็ตรหัสผ่าน';
            $targetText = 'บัญชีอีเมล';
            $detailText = 'ส่งรหัสยืนยันสำหรับเปลี่ยนรหัสผ่านแล้ว';
            break;
        case 'forgot_password_mail_failed':
            $actionText = 'ส่ง OTP ไม่สำเร็จ';
            $targetText = 'ระบบอีเมล';
            $detailText = 'ส่งอีเมล OTP ไม่สำเร็จ';
            break;
        case 'forgot_password_reset_success':
            $actionText = 'เปลี่ยนรหัสผ่านสำเร็จ';
            $targetText = 'บัญชีผู้ใช้งาน';
            $detailText = 'ตั้งรหัสผ่านใหม่เรียบร้อย';
            break;
        case 'forgot_password_rate_limited':
            $actionText = 'จำกัดความถี่การส่ง OTP';
            $targetText = 'ระบบรีเซ็ตรหัสผ่าน';
            $detailText = 'ขอ OTP บ่อยเกินกำหนด';
            break;
        case 'forgot_password_verify_rate_limited':
            $actionText = 'จำกัดความถี่การยืนยัน OTP';
            $targetText = 'ระบบรีเซ็ตรหัสผ่าน';
            $detailText = 'กรอก OTP หรือรีเซ็ตรหัสผ่านบ่อยเกินกำหนด';
            break;
        default:
            $actionText = $action !== '' ? $action : 'ทำรายการ';
            $targetText = trim($targetType . ' ' . $targetId);
            if ($targetText === '') {
                $targetText = '-';
            }
            if ($details !== []) {
                $parts = [];
                foreach ($details as $detailKey => $detailValue) {
                    $parts[] = (string) $detailKey . ': ' . $formatAuditValue($detailValue);
                }
                $detailText = implode(' | ', $parts);
            }
            break;
    }

    return [
        'action_text' => $actionText,
        'target_text' => $targetText,
        'detail_text' => $detailText,
    ];
};

$view = (string) ($_GET['view'] ?? 'users');
if (!in_array($view, ['users', 'pending', 'delete_year', 'audit_log'], true)) {
    $view = 'users';
}

$adminId = (int) $_SESSION['admin_login'];
$stmt = $conn->prepare('SELECT id, firstname, lastname, username FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $adminId]);
$adminRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['firstname' => '', 'lastname' => '', 'username' => ''];

$formData = [
    'id' => 0,
    'position' => '',
    'idnumber' => '',
    'firstname' => '',
    'lastname' => '',
    'username' => '',
    'email' => '',
    'number' => '',
];
$statusFormData = [
    'id' => 0,
    'fullname' => '',
    'username' => '',
    'userstatus' => 'W',
];
$formError = '';
$formSuccess = '';
$isEditOpen = false;
$isStatusOpen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $formError = 'การยืนยันไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (($_POST['action'] ?? '') === 'update_user') {
        $formData = [
            'id' => (int) ($_POST['user_id'] ?? 0),
            'position' => trim((string) ($_POST['position'] ?? '')),
            'idnumber' => trim((string) ($_POST['idnumber'] ?? '')),
            'firstname' => trim((string) ($_POST['firstname'] ?? '')),
            'lastname' => trim((string) ($_POST['lastname'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'number' => trim((string) ($_POST['number'] ?? '')),
        ];
        $isEditOpen = true;

        if (
            $formData['id'] <= 0 ||
            $formData['position'] === '' ||
            $formData['idnumber'] === '' ||
            $formData['firstname'] === '' ||
            $formData['lastname'] === '' ||
            $formData['username'] === '' ||
            $formData['email'] === '' ||
            $formData['number'] === ''
        ) {
            $formError = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $formError = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            $checkStmt = $conn->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $checkStmt->execute([
                ':username' => $formData['username'],
                ':id' => $formData['id'],
            ]);

            $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $emailCheckStmt->execute([
                ':email' => $formData['email'],
                ':id' => $formData['id'],
            ]);

            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $formError = 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';
            } elseif ($emailCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                $formError = 'อีเมลนี้ถูกใช้งานแล้ว';
            } else {
                $updateStmt = $conn->prepare(
                    'UPDATE users
                     SET position = :position,
                         idnumber = :idnumber,
                         firstname = :firstname,
                         lastname = :lastname,
                         username = :username,
                         email = :email,
                         number = :number
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':position' => $formData['position'],
                    ':idnumber' => $formData['idnumber'],
                    ':firstname' => $formData['firstname'],
                    ':lastname' => $formData['lastname'],
                    ':username' => $formData['username'],
                    ':email' => $formData['email'],
                    ':number' => $formData['number'],
                    ':id' => $formData['id'],
                ]);

                auditLog($conn, 'admin_update_user', 'user', (string) $formData['id'], [
                    'position' => $formData['position'],
                    'username' => $formData['username'],
                    'email' => $formData['email'],
                ], $adminId, (string) ($adminRow['username'] ?? ''), 'admin');
                $_SESSION['admin_success'] = 'แก้ไขข้อมูลผู้ใช้เรียบร้อย';
                header('Location: admin.php?view=users');
                exit;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'update_user_status') {
        $statusFormData = [
            'id' => (int) ($_POST['status_user_id'] ?? 0),
            'fullname' => trim((string) ($_POST['status_fullname'] ?? '')),
            'username' => trim((string) ($_POST['status_username'] ?? '')),
            'userstatus' => strtoupper(trim((string) ($_POST['userstatus'] ?? 'W'))),
        ];
        $isStatusOpen = true;

        if ($statusFormData['id'] <= 0) {
            $formError = 'ไม่พบข้อมูลผู้ใช้ที่ต้องการอัปเดตสถานะ';
        } elseif (!in_array($statusFormData['userstatus'], ['W', 'P', 'F'], true)) {
            $formError = 'สถานะที่เลือกไม่ถูกต้อง';
        } else {
            if ($statusFormData['userstatus'] === 'F') {
                $deleteUserStmt = $conn->prepare('DELETE FROM users WHERE id = :id');
                $deleteUserStmt->execute([
                    ':id' => $statusFormData['id'],
                ]);
                auditLog($conn, 'admin_reject_user', 'user', (string) $statusFormData['id'], [
                    'fullname' => $statusFormData['fullname'],
                    'username' => $statusFormData['username'],
                ], $adminId, (string) ($adminRow['username'] ?? ''), 'admin');
                $_SESSION['admin_success'] = 'ไม่อนุมัติเรียบร้อย และลบผู้ใช้ออกจากฐานข้อมูลแล้ว';
            } else {
                $statusStmt = $conn->prepare('UPDATE users SET userstatus = :userstatus WHERE id = :id');
                $statusStmt->execute([
                    ':userstatus' => $statusFormData['userstatus'],
                    ':id' => $statusFormData['id'],
                ]);

                auditLog($conn, 'admin_update_user_status', 'user', (string) $statusFormData['id'], [
                    'fullname' => $statusFormData['fullname'],
                    'username' => $statusFormData['username'],
                    'userstatus' => $statusFormData['userstatus'],
                ], $adminId, (string) ($adminRow['username'] ?? ''), 'admin');
                $_SESSION['admin_success'] = 'อัปเดตสถานะการเข้าใช้งานเรียบร้อย';
            }
            header('Location: admin.php?view=pending');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'delete_exam_year') {
        $deleteExamYear = trim((string) ($_POST['exam_year'] ?? ''));

        if ($deleteExamYear === '') {
            $formError = 'ไม่พบปี นสต. ที่ต้องการลบ';
        } else {
            $countYearStmt = $conn->prepare('SELECT COUNT(*) FROM applicantname WHERE exam_year = :exam_year');
            $countYearStmt->execute([':exam_year' => $deleteExamYear]);
            $rowCount = (int) $countYearStmt->fetchColumn();

            if ($rowCount <= 0) {
                $formError = 'ไม่พบข้อมูลของปี นสต. ที่เลือก';
            } else {
                $deleteYearStmt = $conn->prepare('DELETE FROM applicantname WHERE exam_year = :exam_year');
                $deleteYearStmt->execute([':exam_year' => $deleteExamYear]);

                if (isset($_SESSION['exam_year']) && (string) $_SESSION['exam_year'] === $deleteExamYear) {
                    unset($_SESSION['exam_year']);
                }

                auditLog($conn, 'admin_delete_exam_year', 'exam_year', $deleteExamYear, [
                    'deleted_rows' => $rowCount,
                ], $adminId, (string) ($adminRow['username'] ?? ''), 'admin');
                $_SESSION['admin_success'] = "ลบข้อมูล นสต.รุ่นที่ {$deleteExamYear} เรียบร้อย ({$rowCount} รายการ)";
                header('Location: admin.php?view=delete_year');
                exit;
            }
        }
    }
}

if (isset($_SESSION['admin_success'])) {
    $formSuccess = (string) $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}

$conn->exec("DELETE FROM users WHERE COALESCE(NULLIF(TRIM(userstatus), ''), 'P') = 'F'");

$listStmt = $conn->query("SELECT id, position, idnumber, firstname, lastname, username, email, number, COALESCE(NULLIF(TRIM(userstatus), ''), 'P') AS userstatus FROM users WHERE COALESCE(NULLIF(TRIM(userstatus), ''), 'P') = 'P' ORDER BY id");
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);

$pendingStmt = $conn->query("SELECT id, position, idnumber, firstname, lastname, username, email, number, COALESCE(NULLIF(TRIM(userstatus), ''), 'P') AS userstatus FROM users WHERE COALESCE(NULLIF(TRIM(userstatus), ''), 'P') = 'W' ORDER BY id DESC");
$pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
$totalPendingUsers = count($pendingUsers);

$examYearStmt = $conn->query("
    SELECT exam_year, COUNT(*) AS total_rows
    FROM applicantname
    WHERE exam_year IS NOT NULL AND TRIM(exam_year) <> '' AND id <> 'id'
    GROUP BY exam_year
    ORDER BY exam_year DESC
");
$examYears = $examYearStmt->fetchAll(PDO::FETCH_ASSOC);
$totalExamYears = count($examYears);

ensureAuditLogSchema($conn);
$auditPage = max(1, (int) ($_GET['page'] ?? 1));
$auditPerPage = 50;
$auditOffset = ($auditPage - 1) * $auditPerPage;
$auditRows = [];
$totalAuditLogs = 0;
$auditTotalPages = 1;
$auditFilter = (string) ($_GET['audit_filter'] ?? 'all');
$auditFilterMap = [
    'all' => [
        'label' => 'ทั้งหมด',
        'actions' => [],
    ],
    'login' => [
        'label' => 'Login',
        'actions' => ['login_success', 'login_failed_password', 'login_failed_user_not_found', 'login_failed_exception', 'login_rate_limited'],
    ],
    'import' => [
        'label' => 'Import',
        'actions' => ['import_applicants'],
    ],
    'update' => [
        'label' => 'Update',
        'actions' => ['admin_update_user', 'admin_update_user_status', 'update_stage_status', 'update_applicant_names'],
    ],
    'delete' => [
        'label' => 'Delete',
        'actions' => ['admin_delete_exam_year', 'admin_reject_user'],
    ],
    'password' => [
        'label' => 'Password / OTP',
        'actions' => ['forgot_password_rate_limited', 'forgot_password_mail_failed', 'forgot_password_otp_sent', 'forgot_password_verify_rate_limited', 'forgot_password_reset_success'],
    ],
];
if (!isset($auditFilterMap[$auditFilter])) {
    $auditFilter = 'all';
}

if ($view === 'audit_log') {
    $auditCountSql = 'SELECT COUNT(*) FROM audit_logs';
    $auditCountParams = [];
    $auditWhereSql = '';

    if ($auditFilterMap[$auditFilter]['actions'] !== []) {
        $placeholders = [];
        foreach ($auditFilterMap[$auditFilter]['actions'] as $actionIndex => $actionName) {
            $placeholder = ':action_' . $actionIndex;
            $placeholders[] = $placeholder;
            $auditCountParams[$placeholder] = $actionName;
        }
        $auditWhereSql = ' WHERE action IN (' . implode(', ', $placeholders) . ')';
        $auditCountSql .= $auditWhereSql;
    }

    $auditCountStmt = $conn->prepare($auditCountSql);
    foreach ($auditCountParams as $paramName => $paramValue) {
        $auditCountStmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
    }
    $auditCountStmt->execute();
    $totalAuditLogs = (int) $auditCountStmt->fetchColumn();
    $auditTotalPages = max(1, (int) ceil($totalAuditLogs / $auditPerPage));
    if ($auditPage > $auditTotalPages) {
        $auditPage = $auditTotalPages;
        $auditOffset = ($auditPage - 1) * $auditPerPage;
    }

    $auditStmt = $conn->prepare(
        'SELECT audit_logs.id,
                audit_logs.username,
                audit_logs.role,
                audit_logs.action,
                audit_logs.target_type,
                audit_logs.target_id,
                audit_logs.details,
                audit_logs.ip_address,
                audit_logs.created_at,
                users.firstname AS actor_firstname,
                users.lastname AS actor_lastname
         FROM audit_logs
         LEFT JOIN users ON users.id = audit_logs.user_id
         ' . $auditWhereSql . '
         ORDER BY audit_logs.id DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($auditCountParams as $paramName => $paramValue) {
        $auditStmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
    }
    $auditStmt->bindValue(':limit', $auditPerPage, PDO::PARAM_INT);
    $auditStmt->bindValue(':offset', $auditOffset, PDO::PARAM_INT);
    $auditStmt->execute();
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($view === 'pending') {
    $pageTitle = 'ยืนยันสิทธิ์การเข้าใช้';
    $pageSubtitle = 'รอการอนุมัติ ' . number_format($totalPendingUsers) . ' รายการ';
} elseif ($view === 'delete_year') {
    $pageTitle = 'ลบข้อมูลปีนสต';
    $pageSubtitle = 'มีปีข้อมูลทั้งหมด ' . number_format($totalExamYears) . ' รุ่น';
} elseif ($view === 'audit_log') {
    $pageTitle = 'Audit Log';
    $pageSubtitle = 'แสดง ' . $auditFilterMap[$auditFilter]['label'] . ' จำนวน ' . number_format($totalAuditLogs) . ' รายการ';
} else {
    $pageTitle = 'ผู้เข้าใช้ระบบ';
    $pageSubtitle = 'ทั้งหมด ' . number_format($totalUsers) . ' รายการ';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($pageTitle) ?></title>
                <link href="assets/vendor/bootstrap-5.3.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
    <style>
        .admin-alert {
            margin-bottom: 10px;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .admin-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .admin-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .admin-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .admin-summary {
            font-size: 0.84rem;
            color: var(--muted);
        }

        .admin-edit-btn,
        .admin-status-btn {
            border: none;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: var(--accent);
            color: #1f2937;
            cursor: pointer;
        }

        .admin-status-btn {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .admin-edit-btn:hover,
        .admin-status-btn:hover {
            filter: brightness(0.96);
        }

        .admin-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 40;
        }

        .admin-modal.open {
            display: flex;
        }

        .admin-modal-box {
            width: min(720px, 100%);
            max-height: calc(100dvh - 40px);
            overflow: auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.24);
            padding: 18px;
        }

        .admin-status-modal-box {
            width: min(480px, 100%);
        }

        .admin-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .admin-modal-head h3 {
            margin: 0;
            font-size: 1rem;
            color: var(--brand);
        }

        .admin-close-btn {
            border: none;
            background: transparent;
            font-size: 1.3rem;
            color: #6b7280;
            cursor: pointer;
        }

        .admin-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .admin-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .admin-form-group label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #374151;
        }

        .admin-form-group input,
        .admin-form-group select {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.86rem;
        }

        .admin-form-group input:focus,
        .admin-form-group select:focus {
            outline: 2px solid #fecaca;
            border-color: #fca5a5;
        }

        .admin-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 14px;
        }

        .admin-cancel-btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
        }

        .admin-save-btn {
            border: none;
            background: linear-gradient(120deg, #b91c1c, #7f1d1d);
            color: #fff;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 74px;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .status-pill-W {
            background: #e5e7eb;
            color: #374151;
        }

        .status-pill-P {
            background: #dcfce7;
            color: #166534;
        }

        .status-pill-F {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-user-meta {
            margin: 0 0 12px;
            color: #6b7280;
            font-size: 0.84rem;
        }

        .admin-delete-btn {
            border: none;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 700;
            background: #dc2626;
            color: #fff;
            cursor: pointer;
        }

        .admin-delete-btn:hover {
            filter: brightness(0.96);
        }

        .audit-meta {
            color: #111827;
            font-size: 0.84rem;
            font-weight: 600;
            text-align: left;
        }

        .audit-action {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.76rem;
            font-weight: 700;
            background: #fef3c7;
            color: #92400e;
        }

        .audit-details {
            max-width: 360px;
            text-align: left;
            white-space: normal;
            word-break: break-word;
            color: #374151;
            font-size: 0.78rem;
            line-height: 1.45;
        }

        .audit-pagination {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .audit-filter-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .audit-filter-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 54px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 7px 12px;
            background: #fff;
            color: #374151;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .audit-filter-chip.active {
            background: #7f1d1d;
            border-color: #7f1d1d;
            color: #fff;
        }

        .audit-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 7px 12px;
            text-decoration: none;
            color: #374151;
            background: #fff;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .audit-page-btn.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }

        @media (max-width: 720px) {
            .admin-form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
            <div class="header-meta">
                <strong><?= $h($pageTitle) ?></strong>
                <span><?= $h($pageSubtitle) ?></span>
            </div>
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= $h((string) ($adminRow['firstname'] ?? '')) ?></span>
                    <i class="bi bi-caret-down-fill"></i>
                </button>
                <div id="profileCard" class="profile-card">
                    <p class="profile-name"><?= $h(trim(($adminRow['firstname'] ?? '') . ' ' . ($adminRow['lastname'] ?? ''))) ?></p>
                    <?php if (($adminRow['username'] ?? '') !== ''): ?>
                        <p class="profile-username">@<?= $h((string) $adminRow['username']) ?></p>
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
            <a class="menu-btn<?= $view === 'users' ? ' active' : '' ?>" href="admin.php?view=users">รายชื่อผู้เข้าใช้</a>
            <a class="menu-btn<?= $view === 'pending' ? ' active' : '' ?>" href="admin.php?view=pending">ยืนยันสิทธิ์การเข้าใช้</a>
            <a class="menu-btn<?= $view === 'delete_year' ? ' active' : '' ?>" href="admin.php?view=delete_year">ลบปีข้อมูลนสต</a>
            <a class="menu-btn<?= $view === 'audit_log' ? ' active' : '' ?>" href="admin.php?view=audit_log">ประวัติการใช้งาน</a>
        </aside>

        <main class="content">
            <?php if ($formSuccess !== ''): ?>
                <div class="admin-alert success"><?= $h($formSuccess) ?></div>
            <?php endif; ?>
            <?php if ($formError !== ''): ?>
                <div class="admin-alert error"><?= $h($formError) ?></div>
            <?php endif; ?>

            <?php if ($view === 'delete_year'): ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">ลบข้อมูลผู้สมัครทั้งรุ่นจาก `รุ่นนสต.` ที่มีอยู่ในระบบ</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>นสต.รุ่นที่</th>
                                <th>จำนวนข้อมูล</th>
                                <th>ลบข้อมูล</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$examYears): ?>
                                <tr>
                                    <td colspan="4" class="empty-row">ยังไม่มีข้อมูลปี นสต. ในระบบ</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($examYears as $index => $yearRow): ?>
                                <?php $examYearValue = trim((string) ($yearRow['exam_year'] ?? '')); ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= $h('นสต.' . $examYearValue) ?></td>
                                    <td><?= number_format((int) ($yearRow['total_rows'] ?? 0)) ?> รายการ</td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('ยืนยันการลบข้อมูล นสต.รุ่นที่ <?= $h($examYearValue) ?> ทั้งหมดหรือไม่');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_exam_year">
                                            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                                            <input type="hidden" name="exam_year" value="<?= $h($examYearValue) ?>">
                                            <button type="submit" class="admin-delete-btn">ลบข้อมูล</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($view === 'pending'): ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">รายการผู้สมัครใหม่ที่ยังรอการยืนยันสิทธิ์เข้าใช้งานจากผู้ดูแลระบบ</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>ชื่อผู้ใช้</th>
                                <th>ชื่อ-สกุล</th>
                                <th>ตำแหน่ง</th>
                                <th>อีเมล</th>
                                <th>เบอร์โทร</th>
                                <th>สถานะ</th>
                                <th>แก้ไขสถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$pendingUsers): ?>
                                <tr>
                                    <td colspan="8" class="empty-row">ไม่มีรายการที่รอการยืนยันสิทธิ์</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pendingUsers as $index => $user): ?>
                                <?php $fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= $h((string) $user['username']) ?></td>
                                    <td class="name-cell" style="text-align:left;padding-left:14px;"><?= $h($fullName) ?></td>
                                    <td><?= $h((string) $user['position']) ?></td>
                                    <td><?= $h((string) ($user['email'] ?? '')) ?></td>
                                    <td><?= $h((string) $user['number']) ?></td>
                                    <td><span class="status-pill status-pill-W">รอยืนยัน</span></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="admin-status-btn"
                                            data-id="<?= (int) $user['id'] ?>"
                                            data-fullname="<?= $h($fullName) ?>"
                                            data-username="<?= $h((string) $user['username']) ?>"
                                            data-userstatus="<?= $h((string) $user['userstatus']) ?>"
                                        >
                                            แก้ไขสถานะ
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($view === 'audit_log'): ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">ใช้ติดตามย้อนหลังว่าใครทำอะไร เวลาไหน และมาจาก IP ใด</div>
                </div>

                <div class="audit-filter-bar">
                    <?php foreach ($auditFilterMap as $filterKey => $filterConfig): ?>
                        <a class="audit-filter-chip<?= $filterKey === $auditFilter ? ' active' : '' ?>" href="admin.php?view=audit_log&audit_filter=<?= $h($filterKey) ?>">
                            <?= $h((string) $filterConfig['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>เวลา</th>
                                <th>ผู้ใช้</th>
                                <th>รายการ</th>
                                <th>เป้าหมาย</th>
                                <th>รายละเอียด</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$auditRows): ?>
                                <tr>
                                    <td colspan="6" class="empty-row">ยังไม่มีข้อมูล Audit Log</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($auditRows as $auditRow): ?>
                                <?php
                                $actorFullName = trim((string) ($auditRow['actor_firstname'] ?? '') . ' ' . (string) ($auditRow['actor_lastname'] ?? ''));
                                $actorDisplay = $actorFullName !== '' ? $actorFullName : trim((string) ($auditRow['username'] ?? ''));
                                if ($actorDisplay === '') {
                                    $actorDisplay = '-';
                                }
                                $translatedAudit = $translateAuditRow($auditRow);
                                ?>
                                <tr>
                                    <td><?= $h((string) ($auditRow['created_at'] ?? '-')) ?></td>
                                    <td class="audit-meta"><?= $h($actorDisplay) ?></td>
                                    <td><span class="audit-action"><?= $h($translatedAudit['action_text']) ?></span></td>
                                    <td class="audit-meta"><?= $h($translatedAudit['target_text']) ?></td>
                                    <td class="audit-details"><?= $h($translatedAudit['detail_text']) ?></td>
                                    <td><?= $h((string) ($auditRow['ip_address'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($auditTotalPages > 1): ?>
                    <div class="audit-pagination">
                        <?php for ($page = 1; $page <= $auditTotalPages; $page++): ?>
                            <a class="audit-page-btn<?= $page === $auditPage ? ' active' : '' ?>" href="admin.php?view=audit_log&audit_filter=<?= $h($auditFilter) ?>&page=<?= $page ?>">
                                <?= $page ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">จัดการข้อมูลผู้ใช้งานที่อยู่ในระบบจากหน้าเดียว</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>ชื่อผู้ใช้</th>
                                <th>ชื่อ-สกุล</th>
                                <th>ตำแหน่ง</th>
                                <th>เลขบัตรประชาชน</th>
                                <th>อีเมล</th>
                                <th>เบอร์โทร</th>
                                <th>สถานะ</th>
                                <th>แก้ไข</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$users): ?>
                                <tr>
                                    <td colspan="9" class="empty-row">ไม่พบข้อมูลผู้เข้าใช้</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($users as $index => $user): ?>
                                <?php
                                $userStatus = strtoupper(trim((string) ($user['userstatus'] ?? 'P')));
                                if (!in_array($userStatus, ['W', 'P', 'F'], true)) {
                                    $userStatus = 'P';
                                }
                                $statusLabel = $userStatus === 'W' ? 'รอยืนยัน' : ($userStatus === 'F' ? 'ไม่อนุมัติ' : 'อนุมัติแล้ว');
                                $statusClass = 'status-pill-' . $userStatus;
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= $h((string) $user['username']) ?></td>
                                    <td class="name-cell" style="text-align:left;padding-left:14px;">
                                        <?= $h(trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))) ?>
                                    </td>
                                    <td><?= $h((string) $user['position']) ?></td>
                                    <td><?= $h((string) $user['idnumber']) ?></td>
                                    <td><?= $h((string) ($user['email'] ?? '')) ?></td>
                                    <td><?= $h((string) $user['number']) ?></td>
                                    <td><span class="status-pill <?= $h($statusClass) ?>"><?= $h($statusLabel) ?></span></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="admin-edit-btn"
                                            data-id="<?= (int) $user['id'] ?>"
                                            data-position="<?= $h((string) $user['position']) ?>"
                                            data-idnumber="<?= $h((string) $user['idnumber']) ?>"
                                            data-firstname="<?= $h((string) $user['firstname']) ?>"
                                            data-lastname="<?= $h((string) $user['lastname']) ?>"
                                            data-username="<?= $h((string) $user['username']) ?>"
                                            data-email="<?= $h((string) ($user['email'] ?? '')) ?>"
                                            data-number="<?= $h((string) $user['number']) ?>"
                                        >
                                            แก้ไข
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="adminModal" class="admin-modal<?= $isEditOpen ? ' open' : '' ?>">
        <div class="admin-modal-box">
            <div class="admin-modal-head">
                <h3>แก้ไขข้อมูลผู้เข้าใช้</h3>
                <button type="button" id="closeAdminModal" class="admin-close-btn" aria-label="ปิด">&times;</button>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" id="user_id" name="user_id" value="<?= (int) $formData['id'] ?>">

                <div class="admin-form-grid">
                    <div class="admin-form-group">
                        <label for="position">ตำแหน่ง</label>
                        <input id="position" name="position" type="text" value="<?= $h($formData['position']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="idnumber">เลขบัตรประชาชน</label>
                        <input id="idnumber" name="idnumber" type="text" value="<?= $h($formData['idnumber']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="firstname">ชื่อ</label>
                        <input id="firstname" name="firstname" type="text" value="<?= $h($formData['firstname']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="lastname">นามสกุล</label>
                        <input id="lastname" name="lastname" type="text" value="<?= $h($formData['lastname']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="username">ชื่อผู้ใช้</label>
                        <input id="username" name="username" type="text" value="<?= $h($formData['username']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="email">อีเมล</label>
                        <input id="email" name="email" type="email" value="<?= $h($formData['email']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="number">เบอร์โทร</label>
                        <input id="number" name="number" type="text" value="<?= $h($formData['number']) ?>" required>
                    </div>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="admin-save-btn">บันทึกข้อมูล</button>
                    <button type="button" id="cancelAdminModal" class="admin-cancel-btn">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <div id="statusModal" class="admin-modal<?= $isStatusOpen ? ' open' : '' ?>">
        <div class="admin-modal-box admin-status-modal-box">
            <div class="admin-modal-head">
                <h3>แก้ไขสถานะการเข้าใช้</h3>
                <button type="button" id="closeStatusModal" class="admin-close-btn" aria-label="ปิด">&times;</button>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="update_user_status">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" id="status_user_id" name="status_user_id" value="<?= (int) $statusFormData['id'] ?>">
                <input type="hidden" id="status_fullname" name="status_fullname" value="<?= $h($statusFormData['fullname']) ?>">
                <input type="hidden" id="status_username" name="status_username" value="<?= $h($statusFormData['username']) ?>">

                <p class="status-user-meta">
                    ผู้ใช้: <strong id="statusUserNameLabel"><?= $h($statusFormData['fullname']) ?></strong>
                    <span id="statusUsernameLabel"><?= $statusFormData['username'] !== '' ? '(' . $h($statusFormData['username']) . ')' : '' ?></span>
                </p>

                <div class="admin-form-group">
                    <label for="userstatus">สถานะการเข้าใช้</label>
                    <select id="userstatus" name="userstatus" required>
                        <option value="W"<?= $statusFormData['userstatus'] === 'W' ? ' selected' : '' ?>>รอยืนยัน</option>
                        <option value="P"<?= $statusFormData['userstatus'] === 'P' ? ' selected' : '' ?>>อนุมัติให้เข้าใช้</option>
                        <option value="F"<?= $statusFormData['userstatus'] === 'F' ? ' selected' : '' ?>>ไม่อนุมัติ</option>
                    </select>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="admin-save-btn">บันทึกสถานะ</button>
                    <button type="button" id="cancelStatusModal" class="admin-cancel-btn">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');
        const adminModal = document.getElementById('adminModal');
        const statusModal = document.getElementById('statusModal');
        const closeAdminModal = document.getElementById('closeAdminModal');
        const cancelAdminModal = document.getElementById('cancelAdminModal');
        const closeStatusModal = document.getElementById('closeStatusModal');
        const cancelStatusModal = document.getElementById('cancelStatusModal');

        const formFields = {
            id: document.getElementById('user_id'),
            position: document.getElementById('position'),
            idnumber: document.getElementById('idnumber'),
            firstname: document.getElementById('firstname'),
            lastname: document.getElementById('lastname'),
            username: document.getElementById('username'),
            email: document.getElementById('email'),
            number: document.getElementById('number'),
        };

        const statusFields = {
            id: document.getElementById('status_user_id'),
            fullname: document.getElementById('status_fullname'),
            username: document.getElementById('status_username'),
            userstatus: document.getElementById('userstatus'),
            fullNameLabel: document.getElementById('statusUserNameLabel'),
            usernameLabel: document.getElementById('statusUsernameLabel'),
        };

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });

        function openAdminModal(button) {
            formFields.id.value = button.dataset.id || '';
            formFields.position.value = button.dataset.position || '';
            formFields.idnumber.value = button.dataset.idnumber || '';
            formFields.firstname.value = button.dataset.firstname || '';
            formFields.lastname.value = button.dataset.lastname || '';
            formFields.username.value = button.dataset.username || '';
            formFields.email.value = button.dataset.email || '';
            formFields.number.value = button.dataset.number || '';
            adminModal.classList.add('open');
        }

        function openStatusModal(button) {
            const fullName = button.dataset.fullname || '';
            const username = button.dataset.username || '';
            statusFields.id.value = button.dataset.id || '';
            statusFields.fullname.value = fullName;
            statusFields.username.value = username;
            statusFields.userstatus.value = button.dataset.userstatus || 'W';
            statusFields.fullNameLabel.textContent = fullName;
            statusFields.usernameLabel.textContent = username ? '(' + username + ')' : '';
            statusModal.classList.add('open');
        }

        function closeModal(modal) {
            modal.classList.remove('open');
        }

        document.querySelectorAll('.admin-edit-btn').forEach((button) => {
            button.addEventListener('click', function() {
                openAdminModal(this);
            });
        });

        document.querySelectorAll('.admin-status-btn').forEach((button) => {
            button.addEventListener('click', function() {
                openStatusModal(this);
            });
        });

        if (closeAdminModal) {
            closeAdminModal.addEventListener('click', function() {
                closeModal(adminModal);
            });
        }
        if (cancelAdminModal) {
            cancelAdminModal.addEventListener('click', function() {
                closeModal(adminModal);
            });
        }
        if (closeStatusModal) {
            closeStatusModal.addEventListener('click', function() {
                closeModal(statusModal);
            });
        }
        if (cancelStatusModal) {
            cancelStatusModal.addEventListener('click', function() {
                closeModal(statusModal);
            });
        }

        adminModal.addEventListener('click', function(event) {
            if (event.target === adminModal) {
                closeModal(adminModal);
            }
        });

        statusModal.addEventListener('click', function(event) {
            if (event.target === statusModal) {
                closeModal(statusModal);
            }
        });
    </script>
</body>

</html>
