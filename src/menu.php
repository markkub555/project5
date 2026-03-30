<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (isset($_GET['exam_year']) && $_GET['exam_year'] !== '') {
    $_SESSION['exam_year'] = $_GET['exam_year'];
}

if (!isset($_SESSION['exam_year'])) {
    header('Location: import_gptV1.php');
    exit;
}

$examYear = (string) $_SESSION['exam_year'];

$menus = [
    ['page' => 'All name.php', 'icon' => 'upload/allname.png', 'title' => 'รายชื่อทั้งหมด'],
    ['page' => 'document.php', 'icon' => 'upload/document1.png', 'title' => 'ยื่นเอกสาร'],
    ['page' => 'lab_check.php', 'icon' => 'upload/lab.png', 'title' => 'ตรวจ LAB'],
    ['page' => 'swim.php', 'icon' => 'upload/swim.png', 'title' => 'ว่ายน้ำ'],
    ['page' => 'run.php', 'icon' => 'upload/run.png', 'title' => 'วิ่ง'],
    ['page' => '3station.php', 'icon' => 'upload/3station.png', 'title' => '๓ สถานี'],
    ['page' => 'hospital_check.php', 'icon' => 'upload/hospital_check.png', 'title' => 'ตรวจร่างกาย รพ.ตร.'],
    ['page' => 'fingerprint_check.php', 'icon' => 'upload/fingerprint_check.png', 'title' => 'ตรวจลายนิ้วมือ ศพฐ.'],
    ['page' => 'background_check.php', 'icon' => 'upload/criminal.png', 'title' => 'ตรวจประวัติทางคดี'],
    ['page' => 'interview.php', 'icon' => 'upload/interview.png', 'title' => 'สัมภาษณ์'],
    ['page' => 'militarydoc.php', 'icon' => 'upload/military.png', 'title' => 'เอกสารทางทหาร'],
    ['page' => 'Step.php', 'icon' => 'upload/summary.png', 'title' => 'สรุปผลรายขั้นตอน'],
    ['page' => 'import_gptV1.php', 'icon' => 'upload/import.png', 'title' => 'เพิ่ม/ดูข้อมูล นสต.'],
    ['page' => 'selected.php', 'icon' => 'upload/select.png', 'title' => 'ผู้ได้รับคัดเลือก'],
    ['page' => 'final.php', 'icon' => 'upload/final.png', 'title' => 'สรุปข้อมูลการสอบ นสต.'],
    ['page' => 'export.php', 'icon' => 'upload/import.png', 'title' => 'นำข้อมูลออก'],

];

$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เมนูระบบ</title>
            <link href="assets/css/local-fonts.css" rel="stylesheet">
    <link href="assets/css/menu.css" rel="stylesheet">
</head>

<body>
    <header class="top-header">
        <div class="brand-wrap">
            <a href="menu.php" class="logo-link" aria-label="กลับหน้าเมนูหลัก">
                <img src="upload/tcpr5-1024x990.png" class="logo" alt="ตราศูนย์ฝึกอบรมตำรวจภูธรภาค 5" decoding="async">
            </a>
            <div>
                <h1>ศูนย์ฝึกอบรมตำรวจภูธรภาค ๕</h1>
                <p>TRAINING CENTER OF PROVINCIAL POLICE REGION 5</p>
            </div>
        </div>
        <div class="year-badge">ปีที่ใช้งาน: <strong><?= $h($examYear) ?></strong></div>
    </header>

    <main class="container">
        <section class="menu-panel">
            <div class="panel-head">
                <div>
                    <h2>เมนูหลักการประมวลผล</h2>
                    <p>เลือกขั้นตอนเพื่อเข้าสู่หน้าจัดการข้อมูลตามกระบวนการสอบ</p>
                </div>
                <a class="back-link" href="import_gptV1.php">เลือกดูปีข้อมูล(รุ่น)</a>
            </div>

            <div class="grid">
                <?php foreach ($menus as $menu): ?>
                    <a class="menu-item" href="<?= $h($menu['page']) ?>">
                        <div class="menu-card">
                            <div class="icon-wrap">
                                <img src="<?= $h($menu['icon']) ?>" alt="<?= $h($menu['title']) ?>" loading="lazy" decoding="async">
                            </div>
                            <div class="menu-title"><?= $h($menu['title']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>

</html>
